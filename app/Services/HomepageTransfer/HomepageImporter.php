<?php

namespace App\Services\HomepageTransfer;

use App\Events\HomepageImported;
use App\Events\HomepageRestored;
use App\Models\HomepageConfig;
use App\Models\HomepageImport;
use App\Models\HomepageSection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Validate -> preview -> confirm -> import, and rollback. Every step that changes the homepage:
 *   - runs under a per-client lock, so two operations on one client never overlap;
 *   - re-validates the stored package and checks the live homepage is still the one the preview was computed on;
 *   - takes and verifies a backup first (no verified backup, no change);
 *   - applies everything in ONE database transaction, verifies the stored result before committing;
 *   - only after the commit runs the public-API check and dispatches the event other code listens to.
 *
 * Uploaded homepage files are written next to the rows but never deleted by import or rollback, so a backup
 * always still points at files that exist.
 */
class HomepageImporter
{
    public function __construct(
        private readonly HomepageSnapshot $snapshot,
        private readonly HomepagePackageValidator $validator,
        private readonly HomepageReferenceResolver $resolver,
        private readonly HomepageDiff $diff,
        private readonly HomepageBackup $backup,
        private readonly HomepagePostImportVerifier $verifier,
    ) {}

    // ------------------------------------------------------------------ validate + preview (read-only for the homepage)

    public function validateUpload(string $uploadedPath, int $adminId): HomepageImport
    {
        $this->expireStale();

        $result = $this->validator->validate($uploadedPath);
        $manifest = $result['manifest'];
        $client = $manifest['client_key'] ?? 'unknown';

        $import = new HomepageImport([
            'uuid' => (string) Str::uuid(),
            'kind' => HomepageImport::KIND_IMPORT,
            'client_key' => $client,
            'admin_user_id' => $adminId,
            'package_id' => $manifest['export_id'] ?? null,
            'package_sha256' => $result['package_sha256'],
            'schema_version' => is_int($manifest['schema_version'] ?? null) ? $manifest['schema_version'] : null,
        ]);

        $errors = $result['errors'];
        $warnings = $result['warnings'];
        $references = null;
        $summary = null;
        $baseFingerprint = null;
        $targetExists = null;

        if (! $errors) {
            $state = $result['state'];
            $resolved = $this->resolver->resolve($state);
            $errors = array_merge($errors, $resolved['errors']);
            $warnings = array_merge($warnings, $resolved['warnings']);
            $references = $resolved['report'];

            $raw = $this->snapshot->raw($client);
            $baseFingerprint = $this->snapshot->fingerprint($raw);
            $targetExists = $raw['exists'];
            $current = $this->snapshot->portable($client, strict: false);
            $summary = $this->diff->compare($current['state'] ?? null, $this->prune($state, $resolved['maps']));

            if ($this->alreadyApplied($client, $manifest, $result)) {
                $errors[] = ['code' => 'import.already_applied', 'path' => 'manifest.export_id', 'message' => 'This package has already been imported into this client. Export again for a new package, or roll the earlier import back.'];
            }
        }

        $import->fill([
            'validation' => [
                'package' => $manifest ? [
                    'export_id' => $manifest['export_id'], 'schema_version' => $manifest['schema_version'], 'client_key' => $client,
                    'exported_at' => $manifest['exported_at'] ?? null, 'source_environment' => $manifest['source_environment'] ?? null,
                    'includes' => $manifest['includes'] ?? null,
                ] : null,
                'errors' => $errors,
                'warnings' => $warnings,
                'references' => $references,
                'target' => $targetExists === null ? null : ['exists' => $targetExists, 'fingerprint' => $baseFingerprint],
            ],
            'summary' => $summary,
            'base_fingerprint' => $baseFingerprint,
        ]);

        if ($errors) {
            $import->status = HomepageImport::INVALID;
            $import->error_category = 'validation';
            $import->completed_at = now();
            $import->save();
            $this->log('validated', $import, ['errors' => count($errors)]);

            return $import;
        }

        $disk = Storage::disk(config('homepage_transfer.private_disk'));
        $path = trim(config('homepage_transfer.upload_directory'), '/')."/{$import->uuid}.zip";
        $stream = fopen($uploadedPath, 'rb');
        $stored = $disk->put($path, $stream);
        is_resource($stream) && fclose($stream);

        if (! $stored) {
            throw new HomepageTransferException('upload.store', 'The package could not be stored for confirmation.', 500);
        }

        $import->fill([
            'status' => HomepageImport::VALIDATED,
            'upload_path' => $path,
            'expires_at' => now()->addMinutes((int) config('homepage_transfer.validation_ttl_minutes')),
        ])->save();
        $this->log('validated', $import, ['warnings' => count($warnings)]);

        return $import;
    }

    // ------------------------------------------------------------------ confirm + import

    public function confirm(HomepageImport $import, int $adminId, string $expectedFingerprint, bool $confirmReplace): HomepageImport
    {
        $this->assertImportable($import, $adminId);

        if (! $confirmReplace) {
            throw new HomepageTransferException('import.confirmation_required', 'Confirm that this import replaces the homepage of this client.', 422);
        }
        if (! hash_equals((string) $import->base_fingerprint, $expectedFingerprint)) {
            throw new HomepageTransferException('import.stale', 'That preview does not belong to this validation. Review the preview again.', 409);
        }

        return $this->withLock($import->client_key, fn () => $this->runImport($import->fresh()));
    }

    private function runImport(HomepageImport $import): HomepageImport
    {
        $client = $import->client_key;
        $this->reconcileInterrupted($client);

        if ($import->status !== HomepageImport::VALIDATED) {
            throw new HomepageTransferException('import.not_pending', 'This import is no longer waiting for confirmation.', 409);
        }
        if (HomepageImport::where('client_key', $client)->where('status', HomepageImport::IN_PROGRESS)->exists()) {
            throw new HomepageTransferException('import.busy', 'Another operation on this client is still in progress.', 409);
        }

        // Re-check everything the preview relied on, against the package as stored and the live data as it is now.
        $disk = Storage::disk(config('homepage_transfer.private_disk'));
        if (! $import->upload_path || ! $disk->exists($import->upload_path)) {
            $this->reject($import, 'package_missing', 'The validated package is no longer available. Upload it again.');
        }
        $packagePath = $disk->path($import->upload_path);

        $check = $this->validator->validate($packagePath);
        $manifest = $check['manifest'];

        if ($check['errors'] || ! hash_equals((string) $import->package_sha256, (string) $check['package_sha256'])) {
            $this->reject($import, 'revalidation', 'The package no longer validates. Upload it again.');
        }
        if ($this->alreadyApplied($client, $manifest, $check)) {
            $this->reject($import, 'already_applied', 'This package has already been imported into this client.', 409, 'import.already_applied');
        }

        $resolved = $this->resolver->resolve($check['state']);
        if ($resolved['errors']) {
            $this->reject($import, 'references', 'Some products, categories or brands this homepage uses no longer exist here. Validate again.');
        }

        $raw = $this->snapshot->raw($client);
        if (! hash_equals((string) $import->base_fingerprint, $this->snapshot->fingerprint($raw))) {
            $this->reject($import, 'stale', 'The homepage was changed after this preview was made. Upload and validate the package again.', 409, 'import.stale');
        }

        $intended = $this->prune($check['state'], $resolved['maps']);

        $import->transitionTo(HomepageImport::IN_PROGRESS, ['started_at' => now()]);

        try {
            $backup = $this->backup->create($client, $raw);
        } catch (HomepageTransferException $e) {
            $this->fail($import, 'backup', $e->getMessage());
            throw $e;
        }
        $import->forceFill(['backup_uuid' => $backup['uuid'], 'backup_path' => $backup['path'], 'backup_sha256' => $backup['sha256']])->save();

        $createdFiles = [];
        $importId = $import->id;
        try {
            DB::transaction(function () use ($client, $intended, $resolved, $check, $packagePath, $manifest, &$createdFiles, $import) {
                $this->apply($client, $intended, $resolved['maps'], $check['assets'], $packagePath, $createdFiles);

                $after = $this->snapshot->portable($client, strict: false);
                if ($after === null || Canonical::json(Canonical::withoutNames($after['state'])) !== Canonical::json(Canonical::withoutNames($intended))) {
                    throw new HomepageTransferException('import.verify_db', 'The stored homepage does not match the package, so nothing was kept.', 500);
                }

                $import->transitionTo(HomepageImport::SUCCEEDED, [
                    'completed_at' => now(),
                    'result_fingerprint' => $this->snapshot->fingerprint($this->snapshot->raw($client)),
                    'idempotency_key' => $this->idempotencyKey($client, $manifest, $check),
                ]);
            });
        } catch (Throwable $e) {
            foreach ($createdFiles as $file) {
                Storage::disk('public')->delete($file);
            }
            // The row changed in memory inside the rolled-back transaction; read the real one.
            $import = HomepageImport::findOrFail($importId);
            $duplicate = $e instanceof UniqueConstraintViolationException;
            $this->fail($import, $duplicate ? 'already_applied' : ($e instanceof HomepageTransferException ? $e->errorCode : 'database'), $duplicate ? 'This package has already been imported into this client.' : $this->safeMessage($e));
            report($e);

            throw $duplicate
                ? new HomepageTransferException('import.already_applied', 'This package has already been imported into this client.', 409)
                : ($e instanceof HomepageTransferException ? $e : new HomepageTransferException('import.failed', 'The import failed and nothing was changed.', 500));
        }

        $this->cleanupUpload($import);

        // Committed. Now check what the public API serves; undo if it is wrong.
        $verification = $this->verifier->verify($client, $intended);
        $import->forceFill(['verification' => $verification])->save();

        if (! $verification['ok']) {
            $this->autoRestore($import, 'verification_failed');

            return $import->fresh();
        }

        $this->log('imported', $import, ['sections' => count($intended['sections'])]);
        event(new HomepageImported($import));

        return $import->fresh();
    }

    // ------------------------------------------------------------------ rollback

    public function rollback(HomepageImport $source, int $adminId, bool $acknowledgeOverwrite): HomepageImport
    {
        return $this->withLock($source->client_key, function () use ($source, $adminId, $acknowledgeOverwrite) {
            $source = $source->fresh();
            $client = $source->client_key;
            $this->reconcileInterrupted($client);

            if (! in_array($source->status, [HomepageImport::SUCCEEDED, HomepageImport::RECOVERY_REQUIRED], true) || ! $source->hasBackup()) {
                throw new HomepageTransferException('rollback.unavailable', 'Only a completed operation with a backup can be rolled back.', 409);
            }
            if (HomepageImport::where('client_key', $client)->where('status', HomepageImport::IN_PROGRESS)->exists()) {
                throw new HomepageTransferException('rollback.busy', 'Another operation on this client is still in progress.', 409);
            }

            // The backup must be intact before anything is touched.
            $backup = $this->backup->read($source->backup_path, $source->backup_sha256, $client);

            $raw = $this->snapshot->raw($client);
            $fingerprint = $this->snapshot->fingerprint($raw);
            if ($source->result_fingerprint && ! hash_equals($source->result_fingerprint, $fingerprint) && ! $acknowledgeOverwrite) {
                throw new HomepageTransferException('rollback.changed_since', 'The homepage was changed after this operation. Rolling back would discard those changes; confirm to continue.', 409);
            }

            $rollback = HomepageImport::create([
                'uuid' => (string) Str::uuid(),
                'kind' => HomepageImport::KIND_ROLLBACK,
                'client_key' => $client,
                'status' => HomepageImport::IN_PROGRESS,
                'admin_user_id' => $adminId,
                'package_id' => $source->package_id,
                'source_import_uuid' => $source->uuid,
                'base_fingerprint' => $fingerprint,
                'started_at' => now(),
            ]);

            try {
                // Rolling back is itself reversible: keep what is there now.
                $own = $this->backup->create($client, $raw);
                $rollback->forceFill(['backup_uuid' => $own['uuid'], 'backup_path' => $own['path'], 'backup_sha256' => $own['sha256']])->save();
            } catch (HomepageTransferException $e) {
                $this->fail($rollback, 'backup', $e->getMessage());
                throw $e;
            }

            try {
                DB::transaction(function () use ($backup, $client, $source) {
                    $this->backup->restore($backup);

                    $restored = $this->snapshot->fingerprint($this->snapshot->raw($client));
                    if (! hash_equals($this->snapshot->fingerprint($backup['snapshot']), $restored)) {
                        throw new HomepageTransferException('rollback.verify_db', 'The restored homepage does not match the backup, so nothing was kept.', 500);
                    }

                    $source->transitionTo(HomepageImport::ROLLED_BACK, ['rolled_back_at' => now(), 'idempotency_key' => null]);
                });
            } catch (Throwable $e) {
                report($e);
                $this->fail(HomepageImport::findOrFail($rollback->id), $e instanceof HomepageTransferException ? $e->errorCode : 'database', $this->safeMessage($e));

                throw $e instanceof HomepageTransferException ? $e : new HomepageTransferException('rollback.failed', 'The rollback failed and nothing was changed.', 500);
            }

            $intended = $backup['snapshot']['exists'] ? $this->snapshot->portable($client, strict: false)['state'] ?? null : null;
            $verification = $this->verifier->verify($client, $intended);
            $result = $this->snapshot->fingerprint($this->snapshot->raw($client));
            $rollback = HomepageImport::findOrFail($rollback->id);

            if ($verification['ok']) {
                $rollback->transitionTo(HomepageImport::SUCCEEDED, ['completed_at' => now(), 'verification' => $verification, 'result_fingerprint' => $result]);
                $this->log('rolled_back', $rollback, ['source' => $source->uuid]);
                event(new HomepageRestored($rollback));
            } else {
                $rollback->transitionTo(HomepageImport::RECOVERY_REQUIRED, [
                    'completed_at' => now(), 'verification' => $verification, 'result_fingerprint' => $result,
                    'error_category' => 'verification_failed', 'error_message' => 'The homepage was restored but the public API check failed. Inspect the homepage.',
                ]);
                $this->log('rollback_unverified', $rollback, ['source' => $source->uuid]);
            }

            return $rollback->fresh();
        });
    }

    // ------------------------------------------------------------------ applying a package

    /**
     * Writes the package into the database. Sections are matched to existing ones by type and position, items by
     * kind and position, so ids survive where the structure is the same; only what differs is changed.
     */
    private function apply(string $client, array $state, array $maps, array $assetFiles, string $packagePath, array &$createdFiles): void
    {
        $zip = new ZipArchive;
        if ($zip->open($packagePath, ZipArchive::RDONLY) !== true) {
            throw new HomepageTransferException('import.package', 'The stored package could not be opened.', 500);
        }

        try {
            $config = HomepageConfig::where('client_key', $client)->lockForUpdate()->first() ?? new HomepageConfig(['client_key' => $client]);

            // Settings outside the homepage allowlist are not ours to touch.
            $kept = array_diff_key($config->site_settings ?? [], array_flip(HomepageSchema::SITE_SETTING_KEYS));
            $config->fill([
                'label' => $state['label'],
                'is_active' => $state['is_active'],
                'site_settings' => array_merge($kept, $state['site_settings']),
            ]);
            $config->site_logo_path = $this->place($state['site_logo'] ?? null, $config->site_logo_path, "homepage/$client/site", $assetFiles, $zip, $createdFiles);
            $config->catalog_background_path = $this->place($state['catalog_background'] ?? null, $config->catalog_background_path, "homepage/$client/site", $assetFiles, $zip, $createdFiles);
            $config->save();

            $existing = [];
            $count = [];
            foreach ($config->sections()->with('items')->get() as $section) {
                $count[$section->type] = ($count[$section->type] ?? 0) + 1;
                $existing["{$section->type}#{$count[$section->type]}"] = $section;
            }

            foreach ($state['sections'] as $incoming) {
                $attributes = [
                    'type' => $incoming['type'],
                    'title' => $incoming['title'],
                    'is_active' => $incoming['is_active'],
                    'sort_order' => $incoming['sort_order'],
                    'settings' => $this->storedSettings($incoming['type'], $incoming['settings'], $maps),
                ];

                $section = $existing[$incoming['ref']] ?? null;
                if ($section) {
                    $section->update($attributes);
                    unset($existing[$incoming['ref']]);
                } else {
                    $section = $config->sections()->create($attributes);
                    $section->setRelation('items', collect());
                }

                $this->applyItems($client, $section, $incoming['items'], $assetFiles, $zip, $createdFiles);
            }

            foreach ($existing as $leftover) {
                $leftover->items()->delete();
                $leftover->delete();
            }
        } finally {
            $zip->close();
        }
    }

    private function applyItems(string $client, HomepageSection $section, array $incoming, array $assetFiles, ZipArchive $zip, array &$createdFiles): void
    {
        $existing = [];
        foreach (HomepageDiff::itemsByKey($section->items->map(fn ($i) => $i->getAttributes() + ['_model' => $i])->all()) as $key => $row) {
            $existing[$key] = $row['_model'];
        }

        $count = [];
        foreach ($incoming as $item) {
            $count[$item['kind']] = ($count[$item['kind']] ?? 0) + 1;
            $key = "{$item['kind']}#{$count[$item['kind']]}";
            $model = $existing[$key] ?? null;
            $dir = "homepage/$client/{$section->id}";

            $attributes = [
                'kind' => $item['kind'],
                'title' => $item['title'],
                'alt_text' => $item['alt_text'],
                'link_url' => $item['link_url'],
                'sort_order' => $item['sort_order'],
                'is_active' => $item['is_active'],
                'desktop_image_path' => $this->place($item['desktop_image'] ?? null, $model?->desktop_image_path, $dir, $assetFiles, $zip, $createdFiles),
                'desktop_image_external_url' => $item['desktop_image']['external_url'] ?? null,
                'mobile_image_path' => $this->place($item['mobile_image'] ?? null, $model?->mobile_image_path, $dir, $assetFiles, $zip, $createdFiles),
                'mobile_image_external_url' => $item['mobile_image']['external_url'] ?? null,
                'pdf_path' => $this->place($item['pdf'] ?? null, $model?->pdf_path, $dir, $assetFiles, $zip, $createdFiles),
            ];

            if ($model) {
                $model->update($attributes);
                unset($existing[$key]);
            } else {
                $section->items()->create($attributes);
            }
        }

        foreach ($existing as $leftover) {
            $leftover->delete();
        }
    }

    /**
     * Returns the stored path for a package file. A current file with identical content is kept; otherwise the
     * bytes (re-checked against their hash) are written under their own hash, never over another file.
     */
    private function place(?array $ref, ?string $currentPath, string $directory, array $assetFiles, ZipArchive $zip, array &$createdFiles): ?string
    {
        $sha = $ref['asset'] ?? null;
        if ($sha === null) {
            return null;
        }

        $disk = Storage::disk('public');
        if ($currentPath && $disk->exists($currentPath) && hash_equals($sha, hash_file('sha256', $disk->path($currentPath)))) {
            return $currentPath;
        }

        $entry = $assetFiles[$sha] ?? null;
        if ($entry === null) {
            throw new HomepageTransferException('import.asset', 'A file the homepage uses is missing from the package.', 422);
        }

        $target = "$directory/$sha.".pathinfo($entry, PATHINFO_EXTENSION);
        if ($disk->exists($target)) {
            if (! hash_equals($sha, hash_file('sha256', $disk->path($target)))) {
                throw new HomepageTransferException('import.asset', 'A stored file does not match the package and was not overwritten.', 409);
            }

            return $target;
        }

        $bytes = $this->validator->readEntry($zip, $entry);
        if ($bytes === null || ! hash_equals($sha, hash('sha256', $bytes)) || ! $disk->put($target, $bytes)) {
            throw new HomepageTransferException('import.asset', 'A file from the package could not be stored.', 500);
        }
        $createdFiles[] = $target;

        return $target;
    }

    /** The portable settings of a section as stored in homepage_sections.settings. */
    private function storedSettings(string $type, array $portable, array $maps): array
    {
        $settings = array_intersect_key($portable, array_flip(HomepageSchema::SECTION_SETTING_KEYS[$type] ?? []));

        if ($type === 'product_carousel') {
            $settings['product_ids'] = array_values(array_map(fn ($r) => $maps['products'][(string) $r['erp_product_id']], $portable['product_refs'] ?? []));
        }
        if ($type === 'brand_showcase') {
            $settings['brand_ids'] = array_values(array_map(fn ($r) => $maps['brands'][(int) $r['erp_brand_id']], $portable['brand_refs'] ?? []));
        }
        if ($type === 'featured_category') {
            $settings['category_id'] = ! empty($portable['category_ref']) ? $maps['categories'][(int) $portable['category_ref']['erp_category_id']] : null;
        }

        return $settings;
    }

    /** Drops references that did not resolve (only possible in a list the section does not use) and fills defaults. */
    private function prune(array $state, array $maps): array
    {
        foreach ($state['sections'] as &$section) {
            $s = &$section['settings'];
            if ($section['type'] === 'product_carousel') {
                $s['product_refs'] = array_values(array_filter($s['product_refs'] ?? [], fn ($r) => isset($maps['products'][(string) $r['erp_product_id']])));
            }
            if ($section['type'] === 'brand_showcase') {
                $s['brand_refs'] = array_values(array_filter($s['brand_refs'] ?? [], fn ($r) => isset($maps['brands'][(int) $r['erp_brand_id']])));
            }
            if ($section['type'] === 'featured_category') {
                $s['category_ref'] = $s['category_ref'] ?? null;
            }
            unset($s);
        }
        unset($section);

        $state['site_logo'] = $state['site_logo'] ?? null;
        $state['catalog_background'] = $state['catalog_background'] ?? null;
        foreach ($state['sections'] as &$section) {
            foreach ($section['items'] as &$item) {
                $item['mobile_image'] = $item['mobile_image'] ?? null;
                $item['pdf'] = $item['pdf'] ?? null;
                foreach (['desktop_image', 'mobile_image'] as $slot) {
                    if ($item[$slot] !== null) {
                        $item[$slot] = ['asset' => $item[$slot]['asset'] ?? null, 'external_url' => ($item[$slot]['external_url'] ?? null) ?: null];
                    }
                }
            }
            unset($item);
        }
        unset($section);

        return $state;
    }

    // ------------------------------------------------------------------ state machine helpers

    private function assertImportable(HomepageImport $import, int $adminId): void
    {
        if ($import->kind !== HomepageImport::KIND_IMPORT || $import->status !== HomepageImport::VALIDATED) {
            throw new HomepageTransferException('import.not_pending', 'This import is not waiting for confirmation.', 409);
        }
        if ($import->isExpired()) {
            $this->cleanupUpload($import);
            $import->transitionTo(HomepageImport::EXPIRED, ['error_category' => 'expired']);
            throw new HomepageTransferException('import.expired', 'This validation has expired. Upload the package again.', 409);
        }
        if ((int) $import->admin_user_id !== $adminId) {
            throw new HomepageTransferException('import.forbidden', 'Only the administrator who uploaded the package can confirm it.', 403);
        }
    }

    /** A validated import that can no longer be confirmed (stale, changed, invalid on re-check). */
    private function reject(HomepageImport $import, string $category, string $message, int $status = 422, ?string $code = null): never
    {
        $import->transitionTo(HomepageImport::EXPIRED, ['error_category' => $category, 'error_message' => $message, 'completed_at' => now()]);
        $this->cleanupUpload($import);
        $this->log('rejected', $import, ['category' => $category]);

        throw new HomepageTransferException($code ?? "import.$category", $message, $status);
    }

    private function fail(HomepageImport $import, string $category, string $message): void
    {
        if (in_array(HomepageImport::FAILED, HomepageImport::TRANSITIONS[$import->status] ?? [], true)) {
            $import->transitionTo(HomepageImport::FAILED, ['error_category' => $category, 'error_message' => Str::limit($message, 480), 'completed_at' => now()]);
        }
        $this->cleanupUpload($import);
        $this->log('failed', $import, ['category' => $category]);
    }

    /** The public API check failed after a commit: put the previous homepage back from the verified backup. */
    private function autoRestore(HomepageImport $import, string $category): void
    {
        $client = $import->client_key;

        try {
            $backup = $this->backup->read($import->backup_path, $import->backup_sha256, $client);
            DB::transaction(function () use ($backup, $client) {
                $this->backup->restore($backup);
                if (! hash_equals($this->snapshot->fingerprint($backup['snapshot']), $this->snapshot->fingerprint($this->snapshot->raw($client)))) {
                    throw new HomepageTransferException('restore.verify', 'The restored homepage does not match the backup.', 500);
                }
            });

            $import->transitionTo(HomepageImport::ROLLED_BACK, [
                'rolled_back_at' => now(), 'idempotency_key' => null, 'error_category' => $category,
                'error_message' => 'The public API check failed after the import, so the previous homepage was restored.',
            ]);
            $this->log('auto_restored', $import);
        } catch (Throwable $e) {
            report($e);
            $import->transitionTo(HomepageImport::RECOVERY_REQUIRED, [
                'error_category' => $category,
                'error_message' => 'The public API check failed and the automatic restore did not complete. Restore the backup manually.',
            ]);
            $this->log('recovery_required', $import);
        }
    }

    /** An operation stuck in progress past the timeout has died. */
    private function reconcileInterrupted(string $client): void
    {
        HomepageImport::where('client_key', $client)->where('status', HomepageImport::IN_PROGRESS)->get()
            ->filter->isInterrupted()
            ->each(function (HomepageImport $stuck) {
                // An import marks itself succeeded inside its transaction, so a stuck import never changed the
                // homepage. A stuck rollback may have, so it needs a look.
                $stuck->transitionTo(
                    $stuck->kind === HomepageImport::KIND_ROLLBACK ? HomepageImport::RECOVERY_REQUIRED : HomepageImport::FAILED,
                    ['error_category' => 'interrupted', 'error_message' => 'The operation did not finish and was marked as interrupted.', 'completed_at' => now()],
                );
                $this->cleanupUpload($stuck);
            });
    }

    private function expireStale(): void
    {
        HomepageImport::where('status', HomepageImport::VALIDATED)->where('expires_at', '<', now())->get()->each(function (HomepageImport $old) {
            $this->cleanupUpload($old);
            $old->transitionTo(HomepageImport::EXPIRED, ['error_category' => 'expired', 'completed_at' => now()]);
        });
    }

    private function alreadyApplied(string $client, ?array $manifest, array $check): bool
    {
        return $manifest !== null
            && HomepageImport::where('idempotency_key', $this->idempotencyKey($client, $manifest, $check))->where('status', HomepageImport::SUCCEEDED)->exists();
    }

    private function idempotencyKey(string $client, array $manifest, array $check): string
    {
        return hash('sha256', implode('|', [$client, $manifest['export_id'], $manifest['content_sha256']]));
    }

    private function cleanupUpload(HomepageImport $import): void
    {
        if ($import->upload_path) {
            Storage::disk(config('homepage_transfer.private_disk'))->delete($import->upload_path);
            $import->forceFill(['upload_path' => null])->saveQuietly();
        }
    }

    private function withLock(string $client, \Closure $callback): mixed
    {
        $lock = Cache::lock("homepage-transfer:$client", (int) config('homepage_transfer.lock_seconds'));

        if (! $lock->get()) {
            throw new HomepageTransferException('transfer.busy', 'Another import or rollback is running for this client. Try again in a moment.', 409);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function safeMessage(Throwable $e): string
    {
        return $e instanceof HomepageTransferException ? $e->getMessage() : 'An internal error occurred.';
    }

    private function log(string $event, HomepageImport $import, array $context = []): void
    {
        // Identifiers and counts only: never package content, paths or credentials.
        Log::info("homepage_transfer.$event", ['import' => $import->uuid, 'kind' => $import->kind, 'client' => $import->client_key, 'status' => $import->status, 'admin' => $import->admin_user_id] + $context);
    }
}
