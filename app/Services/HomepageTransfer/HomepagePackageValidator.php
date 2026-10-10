<?php

namespace App\Services\HomepageTransfer;

use Illuminate\Support\Facades\Validator;
use JsonException;
use ZipArchive;

/**
 * Validates an uploaded homepage package without writing anything: archive structure and limits, checksums,
 * schema, content rules and file types. References into the database are checked by HomepageReferenceResolver.
 *
 * Nothing in the package is trusted: sizes are measured while reading, names are matched against an allowlist,
 * content is checked against the same rules as the admin forms, and unknown fields are rejected.
 */
class HomepagePackageValidator
{
    private const ENTRY_PATTERN = '#^(manifest\.json|homepage\.json|checksums\.json|README\.txt|assets/[a-f0-9]{64}\.(?:jpg|png|webp|gif|pdf))$#';

    private const REQUIRED_ENTRIES = ['manifest.json', 'homepage.json', 'checksums.json'];

    private const MANIFEST_KEYS = ['package_type', 'schema_version', 'export_id', 'exported_at', 'source_environment', 'source_commit', 'client_key', 'content_sha256', 'checksum_algorithm', 'includes', 'warnings', 'compatibility'];

    private const STATE_KEYS = ['client_key', 'label', 'is_active', 'site_settings', 'site_logo', 'catalog_background', 'sections'];

    private const SECTION_KEYS = ['ref', 'type', 'title', 'is_active', 'sort_order', 'settings', 'items'];

    private const ITEM_KEYS = ['kind', 'title', 'alt_text', 'link_url', 'sort_order', 'is_active', 'desktop_image', 'mobile_image', 'pdf'];

    private const URL_KEYS = ['link_url', 'action_url', 'cta_url', 'sale_url', 'url'];

    /**
     * @return array{errors: array, warnings: array, manifest: ?array, state: ?array, assets: array<string, string>, package_sha256: ?string}
     */
    public function validate(string $path): array
    {
        $result = ['errors' => [], 'warnings' => [], 'manifest' => null, 'state' => null, 'assets' => [], 'package_sha256' => is_file($path) ? hash_file('sha256', $path) : null];

        $zip = new ZipArchive;
        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            $this->error($result, 'archive.unreadable', 'file', 'The file is not a readable ZIP archive.');

            return $result;
        }

        try {
            $entries = $this->inspectArchive($zip, $result);
            if ($result['errors']) {
                return $result;
            }

            $files = $this->readAndVerify($zip, $entries, $result);
            if ($result['errors']) {
                return $result;
            }

            $manifest = $this->decode($files['manifest.json'], 'manifest.json', $result);
            $content = $this->decode($files['homepage.json'], 'homepage.json', $result);
            if ($result['errors'] || $manifest === null || $content === null) {
                return $result;
            }

            $this->validateManifest($manifest, $content, $files['homepage.json'], $result);
            if ($result['errors']) {
                return $result;
            }
            $result['manifest'] = $manifest;

            $this->validateContent($content, $entries, $result);
            if (! $result['errors']) {
                $result['state'] = $content['homepage'];
                $result['assets'] = collect($content['assets'])->mapWithKeys(fn ($a) => [$a['sha256'] => $a['file']])->all();
            }
        } finally {
            $zip->close();
        }

        return $result;
    }

    /** Names, counts and declared sizes, before any content is read. @return array<string, array{size:int}> */
    private function inspectArchive(ZipArchive $zip, array &$result): array
    {
        $max = (int) config('homepage_transfer.max_entries');
        if ($zip->numFiles < 1 || $zip->numFiles > $max) {
            $this->error($result, 'archive.entry_count', 'file', "The archive must contain between 1 and $max entries.");

            return [];
        }

        $entries = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) ($stat['name'] ?? '');

            if ($name === 'assets/') {
                continue;
            }
            if (! preg_match(self::ENTRY_PATTERN, $name)) {
                $this->error($result, 'archive.unexpected_entry', 'file', 'The archive contains an entry that is not allowed in a homepage package.');

                return [];
            }
            if (isset($entries[$name])) {
                $this->error($result, 'archive.duplicate_entry', $name, 'The archive contains the same entry twice.');

                return [];
            }
            if (($stat['encryption_method'] ?? 0) !== 0) {
                $this->error($result, 'archive.encrypted', $name, 'Encrypted entries are not accepted.');

                return [];
            }

            $limit = $this->limitFor($name);
            $size = (int) $stat['size'];
            $compressed = max(1, (int) $stat['comp_size']);

            if ($size > $limit) {
                $this->error($result, 'archive.entry_too_large', $name, 'An entry is larger than allowed.');

                return [];
            }
            if ($size > 1024 * 1024 && intdiv($size, $compressed) > (int) config('homepage_transfer.max_compression_ratio')) {
                $this->error($result, 'archive.compression_ratio', $name, 'An entry is compressed suspiciously well and was refused.');

                return [];
            }

            $total += $size;
            $entries[$name] = ['size' => $size];
        }

        if ($total > (int) config('homepage_transfer.max_total_uncompressed_bytes')) {
            $this->error($result, 'archive.too_large', 'file', 'The package is larger than allowed once unpacked.');

            return [];
        }

        foreach (self::REQUIRED_ENTRIES as $required) {
            if (! isset($entries[$required])) {
                $this->error($result, 'archive.missing_entry', $required, "The package is missing $required.");
            }
        }

        return $entries;
    }

    /**
     * Reads every entry with a hard byte limit (declared sizes are not trusted), checks each against checksums.json,
     * and checks that uploaded files really are images / PDFs.
     *
     * @return array<string, string> contents of the JSON/text entries
     */
    private function readAndVerify(ZipArchive $zip, array $entries, array &$result): array
    {
        $checksums = $this->decode($this->readBounded($zip, 'checksums.json', $this->limitFor('checksums.json')) ?? '', 'checksums.json', $result);
        if ($checksums === null) {
            return [];
        }

        $listed = $checksums['files'] ?? null;
        if (($checksums['algorithm'] ?? null) !== 'sha256' || ! is_array($listed)) {
            $this->error($result, 'checksums.invalid', 'checksums.json', 'checksums.json is not a valid SHA-256 checksum list.');

            return [];
        }

        $expectedNames = array_keys(array_diff_key($entries, ['checksums.json' => 1]));
        if (array_diff($expectedNames, array_keys($listed)) || array_diff(array_keys($listed), $expectedNames)) {
            $this->error($result, 'checksums.mismatch_entries', 'checksums.json', 'checksums.json does not list exactly the files in the package.');

            return [];
        }

        $files = [];
        foreach ($expectedNames as $name) {
            $limit = $this->limitFor($name);
            $body = $this->readBounded($zip, $name, $limit);

            if ($body === null) {
                $this->error($result, 'archive.entry_too_large', $name, 'An entry is larger than allowed.');

                return [];
            }
            if (! hash_equals((string) $listed[$name], hash('sha256', $body))) {
                $this->error($result, 'checksum.invalid', $name, "The checksum of $name does not match: the package is damaged or was changed.");

                return [];
            }

            if (str_starts_with($name, 'assets/')) {
                $this->checkAssetType($name, $body, $result);
            } else {
                $files[$name] = $body;
            }
        }

        $files['checksums.json'] = '';

        return $files;
    }

    private function checkAssetType(string $name, string $body, array &$result): void
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        if ($extension === 'pdf') {
            if (! str_starts_with($body, '%PDF-') || (new \finfo(FILEINFO_MIME_TYPE))->buffer($body) !== 'application/pdf') {
                $this->error($result, 'asset.type', $name, "$name is not a PDF.");
            }

            return;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        $expected = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'][$extension] ?? null;

        if ($mime !== $expected || @getimagesizefromstring($body) === false) {
            $this->error($result, 'asset.type', $name, "$name is not a valid ".strtoupper($extension).' image.');
        }
    }

    private function validateManifest(array $manifest, array $content, string $homepageJson, array &$result): void
    {
        foreach (array_diff(array_keys($manifest), self::MANIFEST_KEYS) as $unknown) {
            $this->error($result, 'manifest.unknown_field', "manifest.$unknown", 'The manifest contains a field this importer does not understand.');
        }

        if (($manifest['package_type'] ?? null) !== config('homepage_transfer.package_type')) {
            $this->error($result, 'manifest.package_type', 'manifest.package_type', 'This is not a homepage package.');
        }

        $version = $manifest['schema_version'] ?? null;
        if (! is_int($version) || ! in_array($version, config('homepage_transfer.supported_schema_versions'), true)) {
            $this->error($result, 'manifest.schema_version', 'manifest.schema_version', 'This package uses a schema version this Gateway does not support. Upgrade the Gateway or export again.');
        } elseif (($content['schema_version'] ?? null) !== $version) {
            $this->error($result, 'manifest.inconsistent', 'homepage.json', 'The schema version in homepage.json differs from the manifest.');
        }

        if (! is_string($manifest['export_id'] ?? null) || ! preg_match('/^[0-9a-f-]{36}$/', $manifest['export_id'])) {
            $this->error($result, 'manifest.export_id', 'manifest.export_id', 'The package has no valid export id.');
        }

        if (! is_string($manifest['client_key'] ?? null) || ! preg_match(HomepageSchema::CLIENT_KEY_PATTERN, $manifest['client_key'])) {
            $this->error($result, 'manifest.client_key', 'manifest.client_key', 'The package has no valid client.');
        } elseif (($content['homepage']['client_key'] ?? null) !== $manifest['client_key']) {
            $this->error($result, 'manifest.inconsistent', 'homepage.json', 'The client in homepage.json differs from the manifest.');
        }

        if (($manifest['checksum_algorithm'] ?? null) !== 'sha256' || ! hash_equals((string) ($manifest['content_sha256'] ?? ''), hash('sha256', $homepageJson))) {
            $this->error($result, 'manifest.content_hash', 'manifest.content_sha256', 'The manifest does not match homepage.json.');
        }
    }

    private function validateContent(array $content, array $entries, array &$result): void
    {
        $state = $content['homepage'] ?? null;
        if (! is_array($state) || ! is_array($content['assets'] ?? null)) {
            $this->error($result, 'content.shape', 'homepage.json', 'homepage.json has an unexpected structure.');

            return;
        }

        if ($extra = array_diff(array_keys($content), ['schema_version', 'homepage', 'assets'])) {
            $this->error($result, 'content.unknown_field', 'homepage.json', 'homepage.json contains fields this importer does not understand: '.implode(', ', $extra).'.');

            return;
        }

        $this->checkAssetList($content['assets'], $entries, $result);
        $this->rejectUnknown($state, self::STATE_KEYS, 'homepage', $result);

        $sections = is_array($state['sections'] ?? null) ? $state['sections'] : [];
        foreach ($sections as $i => $section) {
            if (! is_array($section)) {
                $this->error($result, 'content.shape', "sections.$i", 'A section has an unexpected structure.');

                return;
            }
            $this->rejectUnknown($section, self::SECTION_KEYS, "sections.$i", $result);
            $type = $section['type'] ?? null;
            if (is_string($type) && isset(HomepageSchema::SECTION_SETTING_KEYS[$type]) && is_array($section['settings'] ?? null)) {
                $allowed = array_merge(HomepageSchema::SECTION_SETTING_KEYS[$type], ['product_refs', 'category_ref', 'brand_refs']);
                $this->rejectUnknown($section['settings'], $allowed, "sections.$i.settings", $result);
            }
            foreach (is_array($section['items'] ?? null) ? $section['items'] : [] as $j => $item) {
                is_array($item) ? $this->rejectUnknown($item, self::ITEM_KEYS, "sections.$i.items.$j", $result) : $this->error($result, 'content.shape', "sections.$i.items.$j", 'An item has an unexpected structure.');
            }
        }
        if (is_array($state['site_settings'] ?? null)) {
            $this->rejectUnknown($state['site_settings'], HomepageSchema::SITE_SETTING_KEYS, 'site_settings', $result);
        }
        if ($result['errors']) {
            return;
        }

        $rules = [
            'homepage.client_key' => ['required', 'string', 'regex:'.HomepageSchema::CLIENT_KEY_PATTERN],
            'homepage.label' => 'required|string|max:255',
            'homepage.is_active' => 'required|boolean',
            'homepage.site_logo' => 'nullable|array',
            'homepage.site_logo.asset' => ['required_with:homepage.site_logo', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'homepage.catalog_background' => 'nullable|array',
            'homepage.catalog_background.asset' => ['required_with:homepage.catalog_background', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'homepage.sections' => 'required|array|max:'.(int) config('homepage_transfer.max_sections'),
            'homepage.sections.*.ref' => 'required|string|max:64|distinct',
            'homepage.sections.*.type' => 'required|in:'.implode(',', HomepageSchema::SECTION_TYPES),
            'homepage.sections.*.title' => 'required|string|max:255',
            'homepage.sections.*.is_active' => 'required|boolean',
            'homepage.sections.*.sort_order' => 'required|integer|min:0|max:100000',
            'homepage.sections.*.settings' => 'present|array',
            'homepage.sections.*.items' => 'present|array|max:'.(int) config('homepage_transfer.max_items_per_section'),
            'homepage.sections.*.items.*.kind' => 'required|string|max:32',
            'homepage.sections.*.items.*.title' => 'nullable|string|max:255',
            'homepage.sections.*.items.*.alt_text' => 'nullable|string|max:255',
            'homepage.sections.*.items.*.link_url' => 'nullable|string|max:2048',
            'homepage.sections.*.items.*.sort_order' => 'required|integer|min:0|max:100000',
            'homepage.sections.*.items.*.is_active' => 'required|boolean',
            'homepage.sections.*.items.*.desktop_image' => 'required|array',
            'homepage.sections.*.items.*.mobile_image' => 'nullable|array',
            'homepage.sections.*.items.*.pdf' => 'nullable|array',
            'homepage.sections.*.items.*.pdf.asset' => ['required_with:homepage.sections.*.items.*.pdf', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ] + HomepageSchema::siteSettingRules('homepage.site_settings');

        foreach ($sections as $i => $section) {
            $type = $section['type'] ?? null;
            if (is_string($type) && in_array($type, HomepageSchema::SECTION_TYPES, true)) {
                $rules += HomepageSchema::sectionSettingRules($type, "homepage.sections.$i.settings");
                $rules["homepage.sections.$i.settings.product_refs"] = 'sometimes|array|max:50';
                $rules["homepage.sections.$i.settings.product_refs.*.erp_product_id"] = 'required|string|max:64';
                $rules["homepage.sections.$i.settings.product_refs.*.name"] = 'nullable|string|max:500';
                $rules["homepage.sections.$i.settings.brand_refs"] = 'sometimes|array|max:200';
                $rules["homepage.sections.$i.settings.brand_refs.*.erp_brand_id"] = 'required|integer|min:1';
                $rules["homepage.sections.$i.settings.brand_refs.*.name"] = 'nullable|string|max:500';
                $rules["homepage.sections.$i.settings.category_ref"] = 'sometimes|nullable|array';
                $rules["homepage.sections.$i.settings.category_ref.erp_category_id"] = "required_with:homepage.sections.$i.settings.category_ref|integer|min:1";
                $rules["homepage.sections.$i.settings.category_ref.name"] = 'nullable|string|max:500';
            }
        }

        $validator = Validator::make($content, $rules);
        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->error($result, 'content.invalid', $field, $messages[0]);
            }

            return;
        }

        $this->checkSections($sections, $content['assets'], $result);
        $this->checkUrls($state, 'homepage', $result);

        foreach (['site_logo', 'catalog_background'] as $key) {
            if (! empty($state[$key]['asset']) && ! $this->assetIsImage($state[$key]['asset'], $content['assets'])) {
                $this->error($result, 'asset.type', "homepage.$key", 'This must be an image file.');
            }
        }
    }

    private function checkAssetList(array $assets, array $entries, array &$result): void
    {
        $seen = [];
        foreach ($assets as $i => $asset) {
            $sha = $asset['sha256'] ?? null;
            $file = $asset['file'] ?? null;

            if (! is_string($sha) || ! is_string($file) || ! preg_match('#^assets/'.preg_quote((string) $sha, '#').'\.(jpg|png|webp|gif|pdf)$#', $file) || ! isset($entries[$file])) {
                $this->error($result, 'asset.invalid', "assets.$i", 'An asset entry is invalid or its file is missing from the package.');

                return;
            }
            if (isset($seen[$sha])) {
                $this->error($result, 'asset.duplicate', "assets.$i", 'The same asset is listed twice.');

                return;
            }
            $seen[$sha] = true;
        }

        if ($orphans = array_diff(array_filter(array_keys($entries), fn ($n) => str_starts_with($n, 'assets/')), array_column($assets, 'file'))) {
            $this->error($result, 'asset.unlisted', 'assets', 'The package contains files that homepage.json does not list.');
        }
    }

    private function checkSections(array $sections, array $assets, array &$result): void
    {
        $occurrence = [];
        $listed = array_column($assets, 'file', 'sha256');

        foreach ($sections as $i => $section) {
            $type = $section['type'];
            if ($section['sort_order'] !== $i) {
                $this->error($result, 'content.section_order', "sections.$i.sort_order", 'Sections must be listed in display order, numbered from 0.');
            }
            $occurrence[$type] = ($occurrence[$type] ?? 0) + 1;
            if ($section['ref'] !== "{$type}#{$occurrence[$type]}") {
                $this->error($result, 'content.section_ref', "sections.$i.ref", 'The section identifier does not match its type and position.');
            }

            foreach ($section['items'] as $j => $item) {
                $where = "sections.$i.items.$j";
                if ($item['sort_order'] !== $j) {
                    $this->error($result, 'content.item_order', "$where.sort_order", 'Items must be listed in display order, numbered from 0.');
                }
                if (! in_array($item['kind'], HomepageSchema::ITEM_KINDS[$type], true)) {
                    $this->error($result, 'content.item_kind', "$where.kind", "A $type section cannot hold '{$item['kind']}' items.");
                }

                foreach (['desktop_image', 'mobile_image'] as $slot) {
                    $image = $item[$slot] ?? null;
                    if ($image === null) {
                        continue;
                    }
                    $this->rejectUnknown($image, ['asset', 'external_url'], "$where.$slot", $result);
                    $asset = $image['asset'] ?? null;
                    $url = $image['external_url'] ?? null;

                    if ($asset === null && ($url === null || $url === '')) {
                        $this->error($result, 'content.image_missing', "$where.$slot", 'An image needs an uploaded file or an image URL.');
                    }
                    if ($url !== null && (! is_string($url) || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                        $this->error($result, 'content.image_url', "$where.$slot.external_url", 'The image URL must be an http(s) URL.');
                    }
                    if ($asset !== null && (! is_string($asset) || ! isset($listed[$asset]))) {
                        $this->error($result, 'asset.missing', "$where.$slot.asset", 'The image refers to a file that is not in the package.');
                    } elseif ($asset !== null && ! $this->assetIsImage($asset, $assets)) {
                        $this->error($result, 'asset.type', "$where.$slot.asset", 'This must be an image file.');
                    }
                }

                if (($item['pdf']['asset'] ?? null) !== null) {
                    $file = $listed[$item['pdf']['asset']] ?? null;
                    if ($file === null) {
                        $this->error($result, 'asset.missing', "$where.pdf.asset", 'The PDF refers to a file that is not in the package.');
                    } elseif (! str_ends_with($file, '.pdf')) {
                        $this->error($result, 'asset.type', "$where.pdf.asset", 'This must be a PDF file.');
                    }
                }
            }

            $refs = array_column($section['settings']['product_refs'] ?? [], 'erp_product_id');
            if (count($refs) !== count(array_unique($refs))) {
                $this->error($result, 'content.duplicate_reference', "sections.$i.settings.product_refs", 'A product is listed twice in one section.');
            }
            $brands = array_column($section['settings']['brand_refs'] ?? [], 'erp_brand_id');
            if (count($brands) !== count(array_unique($brands))) {
                $this->error($result, 'content.duplicate_reference', "sections.$i.settings.brand_refs", 'A brand is listed twice in one section.');
            }
        }
    }

    private function assetIsImage(string $sha, array $assets): bool
    {
        foreach ($assets as $asset) {
            if (($asset['sha256'] ?? null) === $sha) {
                return ! str_ends_with((string) $asset['file'], '.pdf');
            }
        }

        return false;
    }

    /** Blocks script-capable link targets in every url field. */
    private function checkUrls(mixed $value, string $path, array &$result): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $here = "$path.$key";
            if (is_string($item) && in_array((string) $key, self::URL_KEYS, true) && $this->isUnsafeUrl($item)) {
                $this->error($result, 'content.unsafe_url', $here, 'Links must not use javascript:, data: or similar schemes.');
            }
            $this->checkUrls($item, $here, $result);
        }
    }

    private function isUnsafeUrl(string $url): bool
    {
        $clean = strtolower(preg_replace('/[\x00-\x20]+/', '', $url));

        return (bool) preg_match('/^(javascript|data|vbscript|file):/', $clean);
    }

    private function rejectUnknown(array $value, array $allowed, string $path, array &$result): void
    {
        foreach (array_diff(array_keys($value), $allowed) as $key) {
            $this->error($result, 'content.unknown_field', "$path.$key", 'This field is not part of the homepage schema.');
        }
    }

    private function decode(string $json, string $name, array &$result): ?array
    {
        try {
            $decoded = json_decode($json, true, (int) config('homepage_transfer.max_json_depth'), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error($result, 'json.invalid', $name, "$name is not valid JSON (or is nested too deeply).");

            return null;
        }

        if (! is_array($decoded)) {
            $this->error($result, 'json.invalid', $name, "$name must contain a JSON object.");

            return null;
        }

        return $decoded;
    }

    /** Reads one package entry with the same size limit the validation applied. */
    public function readEntry(ZipArchive $zip, string $name): ?string
    {
        return preg_match(self::ENTRY_PATTERN, $name) ? $this->readBounded($zip, $name, $this->limitFor($name)) : null;
    }

    /** Reads at most $limit bytes; null when the entry is bigger than it claimed (never reads past the limit). */
    private function readBounded(ZipArchive $zip, string $name, int $limit): ?string
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return null;
        }

        $body = '';
        while (! feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
            if (strlen($body) > $limit) {
                fclose($stream);

                return null;
            }
        }
        fclose($stream);

        return $body;
    }

    private function limitFor(string $name): int
    {
        return match (true) {
            str_ends_with($name, '.pdf') => (int) config('homepage_transfer.max_pdf_bytes'),
            str_starts_with($name, 'assets/') => (int) config('homepage_transfer.max_image_bytes'),
            $name === 'README.txt' => 65536,
            default => (int) config('homepage_transfer.max_json_bytes'),
        };
    }

    private function error(array &$result, string $code, string $path, string $message): void
    {
        $result['errors'][] = ['code' => $code, 'path' => $path, 'message' => $message];
    }
}
