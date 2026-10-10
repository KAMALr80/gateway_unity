<?php

namespace App\Services\HomepageTransfer;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Builds the portable homepage package for one client:
 *
 *   manifest.json    what this is, where it came from, record counts, content hash
 *   homepage.json    the allowlisted homepage content (business keys instead of database ids)
 *   checksums.json   SHA-256 of every other entry
 *   README.txt       a human description
 *   assets/<sha>.ext homepage-owned uploads (images, catalog PDFs), named by content hash
 *
 * homepage.json and every asset are byte-for-byte reproducible for an unchanged homepage; only the
 * manifest (export id, timestamp) differs between two exports.
 */
class HomepageExporter
{
    /** A fixed entry time keeps the archive free of incidental differences. */
    private const ENTRY_TIME = 946684800; // 2000-01-01T00:00:00Z

    public function __construct(private readonly HomepageSnapshot $snapshot) {}

    /** @return array{path: string, filename: string, manifest: array} A temporary file the caller must delete. */
    public function export(string $clientKey): array
    {
        if (! preg_match(HomepageSchema::CLIENT_KEY_PATTERN, $clientKey)) {
            throw new HomepageTransferException('export.client', 'A valid homepage client is required.', 422);
        }

        $portable = $this->snapshot->portable($clientKey, strict: true);

        if ($portable === null) {
            throw new HomepageTransferException('export.not_found', 'That client has no homepage to export.', 404);
        }

        ['state' => $state, 'assets' => $assets, 'warnings' => $warnings] = $portable;
        ksort($assets);

        $content = [
            'schema_version' => (int) config('homepage_transfer.schema_version'),
            'homepage' => $state,
            'assets' => array_map(fn (array $a, string $sha) => [
                'sha256' => $sha,
                'file' => "assets/{$sha}.{$a['extension']}",
                'mime' => $a['mime'],
                'size' => $a['size'],
            ], $assets, array_keys($assets)),
        ];
        $homepageJson = Canonical::json($content, pretty: true);

        $manifest = [
            'package_type' => config('homepage_transfer.package_type'),
            'schema_version' => (int) config('homepage_transfer.schema_version'),
            'export_id' => (string) Str::uuid(),
            'exported_at' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'source_environment' => $this->environmentLabel(),
            'source_commit' => config('homepage_transfer.source_commit') ?: null,
            'client_key' => $state['client_key'],
            'content_sha256' => hash('sha256', $homepageJson),
            'checksum_algorithm' => 'sha256',
            'includes' => $this->counts($state, $assets),
            'warnings' => $warnings,
            'compatibility' => ['importer_schema_versions' => [(int) config('homepage_transfer.schema_version')]],
        ];
        $manifestJson = Canonical::json($manifest, pretty: true);
        $readme = $this->readme($manifest);

        $checksums = [
            'algorithm' => 'sha256',
            'files' => ['README.txt' => hash('sha256', $readme), 'homepage.json' => hash('sha256', $homepageJson), 'manifest.json' => hash('sha256', $manifestJson)],
        ];
        foreach ($assets as $sha => $asset) {
            $checksums['files']["assets/{$sha}.{$asset['extension']}"] = $sha;
        }
        ksort($checksums['files']);

        $path = tempnam(sys_get_temp_dir(), 'hpx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new HomepageTransferException('export.zip', 'The package could not be created.', 500);
        }

        $this->addText($zip, 'manifest.json', $manifestJson);
        $this->addText($zip, 'homepage.json', $homepageJson);
        $this->addText($zip, 'checksums.json', Canonical::json($checksums, pretty: true));
        $this->addText($zip, 'README.txt', $readme);

        foreach ($assets as $sha => $asset) {
            $name = "assets/{$sha}.{$asset['extension']}";
            $zip->addFile(Storage::disk('public')->path($asset['path']), $name);
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
            $zip->setMtimeName($name, self::ENTRY_TIME);
        }

        if (! $zip->close()) {
            @unlink($path);
            throw new HomepageTransferException('export.zip', 'The package could not be written.', 500);
        }

        return ['path' => $path, 'filename' => "homepage-{$state['client_key']}-".Carbon::now('UTC')->format('Ymd-His').'.zip', 'manifest' => $manifest];
    }

    private function addText(ZipArchive $zip, string $name, string $contents): void
    {
        $zip->addFromString($name, $contents);
        $zip->setCompressionName($name, ZipArchive::CM_DEFLATE);
        $zip->setMtimeName($name, self::ENTRY_TIME);
    }

    private function environmentLabel(): string
    {
        $label = (string) (config('homepage_transfer.environment_label') ?: app()->environment());

        return substr(preg_replace('/[^A-Za-z0-9._-]/', '-', $label), 0, 40);
    }

    private function counts(array $state, array $assets): array
    {
        $items = 0;
        $products = 0;
        $categories = 0;
        $brands = 0;
        foreach ($state['sections'] as $section) {
            $items += count($section['items']);
            $products += count($section['settings']['product_refs'] ?? []);
            $brands += count($section['settings']['brand_refs'] ?? []);
            $categories += ! empty($section['settings']['category_ref']) ? 1 : 0;
        }

        return [
            'sections' => count($state['sections']),
            'items' => $items,
            'assets' => count($assets),
            'product_references' => $products,
            'category_references' => $categories,
            'brand_references' => $brands,
        ];
    }

    private function readme(array $manifest): string
    {
        return implode("\n", [
            'Gateway homepage package',
            '',
            "Client:  {$manifest['client_key']}",
            "Schema:  {$manifest['schema_version']}",
            "Export:  {$manifest['export_id']} at {$manifest['exported_at']}",
            '',
            'This archive holds the homepage configuration of one storefront client and nothing else:',
            'no users, orders, carts, product/category/brand records, credentials or settings outside the homepage.',
            'Products, categories and brands are referenced by their ERP ids and must already exist where this is imported.',
            '',
            'Do not edit these files. Upload the archive on the Gateway admin page "Homepage > Import / export":',
            'it is validated and previewed first, and nothing changes until you confirm.',
            '',
        ]);
    }
}
