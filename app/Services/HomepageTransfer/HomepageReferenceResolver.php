<?php

namespace App\Services\HomepageTransfer;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;

/**
 * Maps the business keys in a package (erp product / category / brand ids) to the ids of THIS environment.
 *
 * Database ids differ between environments, so an id is never used as a reference. A key that is not found
 * is reported and, when the homepage would render wrongly without it, blocks the import. Nothing is created.
 */
class HomepageReferenceResolver
{
    /**
     * @return array{maps: array{products: array, categories: array, brands: array}, errors: array, warnings: array, report: array}
     */
    public function resolve(array $state): array
    {
        $keys = ['products' => [], 'categories' => [], 'brands' => []];
        foreach ($state['sections'] as $section) {
            foreach ($section['settings']['product_refs'] ?? [] as $ref) {
                $keys['products'][$ref['erp_product_id']] = true;
            }
            foreach ($section['settings']['brand_refs'] ?? [] as $ref) {
                $keys['brands'][(int) $ref['erp_brand_id']] = true;
            }
            if (! empty($section['settings']['category_ref'])) {
                $keys['categories'][(int) $section['settings']['category_ref']['erp_category_id']] = true;
            }
        }

        $found = [
            'products' => $this->load(Product::class, 'erp_product_id', array_map('strval', array_keys($keys['products'])), ['id', 'erp_product_id', 'name', 'is_active']),
            'categories' => $this->load(Category::class, 'erp_category_id', array_keys($keys['categories']), ['id', 'erp_category_id', 'name', 'is_active']),
            'brands' => $this->load(Brand::class, 'erp_brand_id', array_keys($keys['brands']), ['id', 'erp_brand_id', 'name', 'is_active']),
        ];

        $errors = [];
        $warnings = [];
        $missing = ['products' => [], 'categories' => [], 'brands' => []];
        $maps = ['products' => [], 'categories' => [], 'brands' => []];

        foreach ($state['sections'] as $i => $section) {
            $where = "sections.$i ({$section['ref']})";
            $settings = $section['settings'];
            // A carousel that is filled from live catalogue data never reads its stored product list.
            $listIsUsed = ($settings['source'] ?? 'manual') === 'manual';

            foreach ($settings['product_refs'] ?? [] as $ref) {
                $this->check('products', 'product', 'erp_product_id', (string) $ref['erp_product_id'], $ref['name'] ?? null, $found, $maps, $missing, $errors, $warnings, $where, $listIsUsed);
            }
            foreach ($settings['brand_refs'] ?? [] as $ref) {
                $this->check('brands', 'brand', 'erp_brand_id', (int) $ref['erp_brand_id'], $ref['name'] ?? null, $found, $maps, $missing, $errors, $warnings, $where, true);
            }
            if (! empty($settings['category_ref'])) {
                $ref = $settings['category_ref'];
                $this->check('categories', 'category', 'erp_category_id', (int) $ref['erp_category_id'], $ref['name'] ?? null, $found, $maps, $missing, $errors, $warnings, $where, true);
            }
        }

        $report = [];
        foreach ($keys as $kind => $set) {
            $report[$kind] = [
                'referenced' => count($set),
                'resolved' => count($maps[$kind]),
                'missing' => array_slice(array_values($missing[$kind]), 0, 50),
                'missing_count' => count($missing[$kind]),
            ];
        }

        return ['maps' => $maps, 'errors' => $errors, 'warnings' => $warnings, 'report' => $report];
    }

    private function check(string $kind, string $label, string $column, string|int $key, ?string $name, array $found, array &$maps, array &$missing, array &$errors, array &$warnings, string $where, bool $required): void
    {
        $rows = $found[$kind][(string) $key] ?? [];

        if (count($rows) === 1) {
            $row = $rows[0];
            $maps[$kind][$key] = $row->id;

            if ($name !== null && $this->normalise($name) !== $this->normalise((string) $row->name)) {
                $warnings[] = ['code' => 'reference.name_differs', 'path' => $where, 'message' => ucfirst($label)." $key is called '{$row->name}' here but '$name' in the package. Check it is the same $label."];
            }
            if (! $row->is_active) {
                $warnings[] = ['code' => 'reference.inactive', 'path' => $where, 'message' => ucfirst($label)." $key exists but is inactive here, so it will not be shown."];
            }

            return;
        }

        $problem = count($rows) > 1 ? 'ambiguous' : 'missing';
        $missing[$kind][(string) $key] = ['key' => $key, 'name' => $name, 'section' => $where, 'problem' => $problem];

        $entry = [
            'code' => "reference.$problem",
            'path' => $where,
            'message' => ucfirst($label)." $key".($name ? " ('$name')" : '').($problem === 'ambiguous' ? ' matches more than one record here.' : ' does not exist in this environment.'),
        ];

        if ($required) {
            $errors[] = $entry;
        } else {
            $entry['message'] .= ' It is in a list this section does not use, so it will be left out.';
            $warnings[] = $entry;
        }
    }

    /** @return array<string, array<int, object>> rows grouped by business key */
    private function load(string $model, string $column, array $keys, array $select): array
    {
        $grouped = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            foreach ($model::whereIn($column, $chunk)->get($select) as $row) {
                $grouped[(string) $row->{$column}][] = $row;
            }
        }

        return $grouped;
    }

    private function normalise(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    }
}
