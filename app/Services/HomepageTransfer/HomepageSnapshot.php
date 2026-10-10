<?php

namespace App\Services\HomepageTransfer;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Reads one client's homepage in two forms:
 *  - portable(): environment-independent. Database ids are replaced by business keys (erp ids) and uploaded files
 *    by their SHA-256, so it can be exported, compared with another environment, and re-applied there.
 *  - raw(): the exact rows (with ids), used for backups, restores and the state fingerprint.
 */
class HomepageSnapshot
{
    private const CONFIG_COLUMNS = ['id', 'client_key', 'label', 'site_settings', 'site_logo_path', 'catalog_background_path', 'is_active', 'created_at', 'updated_at'];

    /** Exact rows for a client, or ['exists' => false]. */
    public function raw(string $clientKey): array
    {
        $config = DB::table('homepage_configs')->where('client_key', $clientKey)->first();

        if (! $config) {
            return ['exists' => false, 'client_key' => $clientKey];
        }

        $sections = DB::table('homepage_sections')->where('homepage_config_id', $config->id)->orderBy('sort_order')->orderBy('id')->get();
        $items = $sections->isEmpty()
            ? collect()
            : DB::table('homepage_section_items')->whereIn('homepage_section_id', $sections->pluck('id'))->orderBy('sort_order')->orderBy('id')->get()->groupBy('homepage_section_id');

        return [
            'exists' => true,
            'client_key' => $clientKey,
            'config' => (array) $config,
            'sections' => $sections->map(fn ($section) => (array) $section + [
                'items' => ($items[$section->id] ?? collect())->map(fn ($item) => (array) $item)->values()->all(),
            ])->values()->all(),
        ];
    }

    /** Hash of the content that can change the homepage (timestamps excluded). */
    public function fingerprint(array $raw): string
    {
        if (! ($raw['exists'] ?? false)) {
            return hash('sha256', 'absent:'.$raw['client_key']);
        }

        $strip = fn (array $row) => array_diff_key($row, ['created_at' => 1, 'updated_at' => 1]);
        $json = fn ($value) => is_string($value) ? json_decode($value, true) ?? $value : $value;

        $config = $strip($raw['config']);
        $config['site_settings'] = $json($config['site_settings']);

        $sections = array_map(function (array $section) use ($strip, $json) {
            $items = array_map($strip, $section['items']);
            $section = $strip(array_diff_key($section, ['items' => 1]));
            $section['settings'] = $json($section['settings']);
            $section['items'] = $items;

            return $section;
        }, $raw['sections']);

        return Canonical::hash(['config' => $config, 'sections' => $sections]);
    }

    /**
     * Portable form of a client's homepage, or null when the client has no homepage.
     *
     * @param  bool  $strict  true for export: a reference or file that cannot be exported faithfully is an error.
     *                        false when reading a target environment for comparison: gaps are marked, never fatal.
     * @return array{state: array, assets: array<string, array>, warnings: array<int, string>}|null
     */
    public function portable(string $clientKey, bool $strict): ?array
    {
        $raw = $this->raw($clientKey);

        if (! $raw['exists']) {
            return null;
        }

        $assets = [];
        $warnings = [];
        $problems = [];
        $refs = $this->referenceMaps($raw);

        $sections = [];
        $occurrence = [];
        foreach ($raw['sections'] as $position => $row) {
            $type = $row['type'];
            $occurrence[$type] = ($occurrence[$type] ?? 0) + 1;
            $label = "{$type}#{$occurrence[$type]}";

            $items = [];
            foreach ($row['items'] as $index => $item) {
                $where = "$label item ".($index + 1);
                $items[] = [
                    'kind' => $item['kind'],
                    'title' => $item['title'],
                    'alt_text' => $item['alt_text'],
                    'link_url' => $item['link_url'],
                    // Order is stored as the 0-based position, so equal content always has equal numbers.
                    'sort_order' => $index,
                    'is_active' => (bool) $item['is_active'],
                    'desktop_image' => $this->image($item['desktop_image_path'], $item['desktop_image_external_url'], "$where desktop image", $strict, $assets, $problems),
                    'mobile_image' => $this->image($item['mobile_image_path'], $item['mobile_image_external_url'], "$where mobile image", $strict, $assets, $problems),
                    'pdf' => $this->file($item['pdf_path'], ['pdf'], ['application/pdf'], (int) config('homepage_transfer.max_pdf_bytes'), "$where PDF", $strict, $assets, $problems),
                ];
            }

            $sections[] = [
                'ref' => $label,
                'type' => $type,
                'title' => $row['title'],
                'is_active' => (bool) $row['is_active'],
                'sort_order' => $position,
                'settings' => $this->portableSettings($type, json_decode($row['settings'] ?? 'null', true) ?: [], $refs, $label, $strict, $problems, $warnings),
                'items' => $items,
            ];
        }

        $config = $raw['config'];
        $stored = json_decode($config['site_settings'] ?? 'null', true) ?: [];
        $settings = array_intersect_key($stored, array_flip(HomepageSchema::SITE_SETTING_KEYS));
        if ($dropped = array_diff(array_keys($stored), HomepageSchema::SITE_SETTING_KEYS)) {
            $warnings[] = 'Site settings not part of the homepage package were left out: '.implode(', ', $dropped).'.';
        }

        $state = [
            'client_key' => $config['client_key'],
            'label' => $config['label'],
            'is_active' => (bool) $config['is_active'],
            'site_settings' => $settings,
            'site_logo' => $this->file($config['site_logo_path'], HomepageSchema::IMAGE_EXTENSIONS, HomepageSchema::IMAGE_MIMES, (int) config('homepage_transfer.max_image_bytes'), 'site logo', $strict, $assets, $problems),
            'catalog_background' => $this->file($config['catalog_background_path'], HomepageSchema::IMAGE_EXTENSIONS, HomepageSchema::IMAGE_MIMES, (int) config('homepage_transfer.max_image_bytes'), 'catalog background', $strict, $assets, $problems),
            'sections' => $sections,
        ];

        if ($strict && $problems) {
            throw new HomepageTransferException('export.incomplete', 'The homepage cannot be exported faithfully: '.implode(' ', array_slice($problems, 0, 8)), 422, ['problems' => $problems]);
        }

        return ['state' => $state, 'assets' => $assets, 'warnings' => $warnings];
    }

    /** Looks up the business key and name of every product, category and brand the homepage points at. */
    private function referenceMaps(array $raw): array
    {
        $products = $categories = $brands = [];
        foreach ($raw['sections'] as $section) {
            $settings = json_decode($section['settings'] ?? 'null', true) ?: [];
            array_push($products, ...array_map('intval', $settings['product_ids'] ?? []));
            array_push($brands, ...array_map('intval', $settings['brand_ids'] ?? []));
            if (! empty($settings['category_id'])) {
                $categories[] = (int) $settings['category_id'];
            }
        }

        return [
            'products' => Product::whereIn('id', array_unique($products))->get(['id', 'erp_product_id', 'name'])->keyBy('id'),
            'categories' => Category::whereIn('id', array_unique($categories))->get(['id', 'erp_category_id', 'name'])->keyBy('id'),
            'brands' => Brand::whereIn('id', array_unique($brands))->get(['id', 'erp_brand_id', 'name'])->keyBy('id'),
        ];
    }

    private function portableSettings(string $type, array $stored, array $refs, string $label, bool $strict, array &$problems, array &$warnings): array
    {
        $allowed = HomepageSchema::SECTION_SETTING_KEYS[$type] ?? [];
        $settings = array_intersect_key($stored, array_flip($allowed));

        $known = array_merge($allowed, ['product_ids', 'category_id', 'brand_ids']);
        if ($dropped = array_diff(array_keys($stored), $known)) {
            $warnings[] = "$label: settings not part of the homepage package were left out: ".implode(', ', $dropped).'.';
        }

        if ($type === 'product_carousel') {
            $settings['product_refs'] = array_map(fn ($id) => $this->ref($refs['products'], (int) $id, 'erp_product_id', 'product', $label, $strict, $problems), $stored['product_ids'] ?? []);
        }
        if ($type === 'brand_showcase') {
            $settings['brand_refs'] = array_map(fn ($id) => $this->ref($refs['brands'], (int) $id, 'erp_brand_id', 'brand', $label, $strict, $problems), $stored['brand_ids'] ?? []);
        }
        if ($type === 'featured_category') {
            $settings['category_ref'] = empty($stored['category_id'])
                ? null
                : $this->ref($refs['categories'], (int) $stored['category_id'], 'erp_category_id', 'category', $label, $strict, $problems);
        }

        return $settings;
    }

    private function ref($map, int $id, string $keyColumn, string $what, string $label, bool $strict, array &$problems): array
    {
        $model = $map->get($id);
        $key = $model?->{$keyColumn};

        if ($model === null || $key === null || $key === '') {
            $problems[] = "$label: $what #$id has no stable business key ($keyColumn), so it cannot be matched in another environment.";

            return ['unresolved_id' => $id];
        }

        return [$keyColumn => $keyColumn === 'erp_product_id' ? (string) $key : (int) $key, 'name' => (string) $model->name];
    }

    /** An item image: an uploaded file (preferred by the public API) and/or an external URL. */
    private function image(?string $path, ?string $externalUrl, string $what, bool $strict, array &$assets, array &$problems): ?array
    {
        $asset = $this->file($path, HomepageSchema::IMAGE_EXTENSIONS, HomepageSchema::IMAGE_MIMES, (int) config('homepage_transfer.max_image_bytes'), $what, $strict, $assets, $problems);

        if ($asset === null && ($externalUrl === null || $externalUrl === '')) {
            return null;
        }

        return ['asset' => $asset['asset'] ?? null, 'external_url' => $externalUrl ?: null];
    }

    /** An uploaded file on the public disk, by content hash. */
    private function file(?string $path, array $extensions, array $mimes, int $maxBytes, string $what, bool $strict, array &$assets, array &$problems): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $safe = str_starts_with($path, 'homepage/') && ! str_contains($path, '..') && ! str_contains($path, '\\') && in_array($extension, $extensions, true);
        $disk = Storage::disk('public');

        if (! $safe || ! $disk->exists($path)) {
            $problems[] = "$what: the stored file is missing or not a supported type.";

            return ['asset' => 'missing:'.sha1($path)];
        }

        $full = $disk->path($path);
        $size = filesize($full);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($full);

        if ($size === false || $size > $maxBytes || ! in_array($mime, $mimes, true)) {
            $problems[] = "$what: the stored file is too large or not a supported type.";

            return ['asset' => 'missing:'.sha1($path)];
        }

        $sha = hash_file('sha256', $full);
        // The extension comes from the real content type: a PNG saved under a .jpg name must travel as a PNG.
        $assets[$sha] = ['path' => $path, 'extension' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'][$mime], 'mime' => $mime, 'size' => $size];

        return ['asset' => $sha];
    }
}
