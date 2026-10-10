<?php

namespace App\Services\HomepageTransfer;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Restorable snapshots of ONE client's homepage rows (config, sections, items) with their original ids.
 *
 * Backups are private files (disk "local" = storage/app/private); their path is built from a validated client key
 * and a server-generated uuid, never from request input. Files uploaded for the homepage are not copied: an
 * import or rollback never deletes them, so the paths in a backup stay valid.
 */
class HomepageBackup
{
    public function __construct(private readonly HomepageSnapshot $snapshot) {}

    /** @return array{uuid: string, path: string, sha256: string} */
    public function create(string $clientKey, array $raw): array
    {
        $this->assertClientKey($clientKey);

        $uuid = (string) Str::uuid();
        $path = trim(config('homepage_transfer.backup_directory'), '/')."/{$clientKey}/{$uuid}.json";
        $json = Canonical::json([
            'format' => 'homepage-backup',
            'version' => 1,
            'backup_id' => $uuid,
            'client_key' => $clientKey,
            'created_at' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'snapshot' => $raw,
        ]);

        $disk = Storage::disk(config('homepage_transfer.private_disk'));
        if (! $disk->put($path, $json)) {
            throw new HomepageTransferException('backup.write', 'The backup could not be written, so nothing was changed.', 500);
        }

        $record = ['uuid' => $uuid, 'path' => $path, 'sha256' => hash('sha256', $json)];

        try {
            $this->read($record['path'], $record['sha256'], $clientKey, $raw);
        } catch (HomepageTransferException $e) {
            $disk->delete($path);
            throw new HomepageTransferException('backup.verify', 'The backup could not be verified, so nothing was changed.', 500);
        }

        return $record;
    }

    /**
     * Reads a backup back and checks it: checksum, format, client, and (when given) that it matches the rows it was taken from.
     *
     * @return array the decoded backup
     */
    public function read(string $path, string $sha256, string $clientKey, ?array $expectedSnapshot = null): array
    {
        $this->assertClientKey($clientKey);
        $disk = Storage::disk(config('homepage_transfer.private_disk'));

        // Only files this feature wrote, for this client.
        if (! str_starts_with($path, trim(config('homepage_transfer.backup_directory'), '/')."/{$clientKey}/") || str_contains($path, '..') || ! $disk->exists($path)) {
            throw new HomepageTransferException('backup.missing', 'The backup file is not available.', 404);
        }

        $json = $disk->get($path);
        if (! hash_equals($sha256, hash('sha256', $json))) {
            throw new HomepageTransferException('backup.checksum', 'The backup file does not match its recorded checksum and will not be used.', 409);
        }

        $backup = json_decode($json, true);
        if (! is_array($backup) || ($backup['format'] ?? null) !== 'homepage-backup' || ($backup['client_key'] ?? null) !== $clientKey || ! is_array($backup['snapshot'] ?? null)) {
            throw new HomepageTransferException('backup.format', 'The backup file is not a valid homepage backup for this client.', 409);
        }

        if ($expectedSnapshot !== null && $this->snapshot->fingerprint($backup['snapshot']) !== $this->snapshot->fingerprint($expectedSnapshot)) {
            throw new HomepageTransferException('backup.content', 'The backup does not match the homepage it was taken from.', 500);
        }

        return $backup;
    }

    /**
     * Puts a client's homepage rows back exactly as they were in the backup. Runs inside the caller's
     * transaction. Touches only this client's homepage rows; uploaded files are left where they are.
     */
    public function restore(array $backup): void
    {
        $snapshot = $backup['snapshot'];
        $clientKey = $snapshot['client_key'];
        $this->assertClientKey($clientKey);

        $current = DB::table('homepage_configs')->where('client_key', $clientKey)->lockForUpdate()->first();

        if (! $snapshot['exists']) {
            if ($current) {
                $this->deleteSections($current->id);
                DB::table('homepage_configs')->where('id', $current->id)->delete();
            }

            return;
        }

        $config = $snapshot['config'];
        $values = array_diff_key($config, ['id' => 1]);

        if ($current) {
            DB::table('homepage_configs')->where('id', $current->id)->update($values);
            $configId = $current->id;
        } else {
            $free = ! DB::table('homepage_configs')->where('id', $config['id'])->exists();
            $configId = $free
                ? tap($config['id'], fn () => DB::table('homepage_configs')->insert($config))
                : DB::table('homepage_configs')->insertGetId($values);
        }

        $sectionIds = array_column($snapshot['sections'], 'id');
        $itemIds = [];
        foreach ($snapshot['sections'] as $section) {
            array_push($itemIds, ...array_column($section['items'], 'id'));
        }

        // Never take over an id that now belongs to something else.
        if ($sectionIds && DB::table('homepage_sections')->whereIn('id', $sectionIds)->where('homepage_config_id', '!=', $configId)->exists()) {
            throw new HomepageTransferException('restore.conflict', 'A section id from the backup is now used by another homepage, so it cannot be restored safely.', 409);
        }
        if ($itemIds && DB::table('homepage_section_items')->whereIn('id', $itemIds)->whereNotIn('homepage_section_id', $sectionIds)->exists()) {
            throw new HomepageTransferException('restore.conflict', 'An item id from the backup is now used by another homepage, so it cannot be restored safely.', 409);
        }

        $this->deleteSections($configId);

        foreach ($snapshot['sections'] as $section) {
            $items = $section['items'];
            unset($section['items']);
            $section['homepage_config_id'] = $configId;
            DB::table('homepage_sections')->insert($section);

            foreach (array_chunk($items, 200) as $chunk) {
                DB::table('homepage_section_items')->insert($chunk);
            }
        }
    }

    private function deleteSections(int $configId): void
    {
        $ids = DB::table('homepage_sections')->where('homepage_config_id', $configId)->pluck('id');
        DB::table('homepage_section_items')->whereIn('homepage_section_id', $ids)->delete();
        DB::table('homepage_sections')->where('homepage_config_id', $configId)->delete();
    }

    private function assertClientKey(string $clientKey): void
    {
        if (! preg_match(HomepageSchema::CLIENT_KEY_PATTERN, $clientKey)) {
            throw new HomepageTransferException('backup.client', 'Invalid client.', 422);
        }
    }
}
