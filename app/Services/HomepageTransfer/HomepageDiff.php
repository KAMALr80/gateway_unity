<?php

namespace App\Services\HomepageTransfer;

/**
 * Compares the homepage a package would produce with the one the target environment has now.
 * Both sides are in portable form (business keys, file hashes), so the comparison is meaningful across
 * environments. Names inside references are ignored; only the business key counts.
 */
class HomepageDiff
{
    private const SECTION_FIELDS = ['title', 'is_active', 'settings'];

    private const ITEM_FIELDS = ['title', 'alt_text', 'link_url', 'is_active', 'desktop_image', 'mobile_image', 'pdf'];

    private const LIST_LIMIT = 100;

    public function compare(?array $current, array $incoming): array
    {
        $summary = [
            'replaces_homepage' => true,
            'target_exists' => $current !== null,
            'creates_homepage' => $current === null,
            'config' => [],
            'sections' => ['added' => [], 'removed' => [], 'updated' => []],
            'order_changed' => false,
            'counts' => ['sections_added' => 0, 'sections_removed' => 0, 'sections_updated' => 0, 'items_added' => 0, 'items_removed' => 0, 'items_updated' => 0],
            'unchanged' => false,
        ];

        $this->compareConfig($current, $incoming, $summary);

        $currentSections = $this->byRef($current['sections'] ?? []);
        $incomingSections = $this->byRef($incoming['sections']);

        foreach ($incomingSections as $ref => $section) {
            if (! isset($currentSections[$ref])) {
                $summary['counts']['sections_added']++;
                $summary['counts']['items_added'] += count($section['items']);
                $summary['sections']['added'][] = ['ref' => $ref, 'title' => $section['title'], 'items' => count($section['items'])];

                continue;
            }

            $change = $this->compareSection($currentSections[$ref], $section);
            if ($change) {
                $summary['counts']['sections_updated']++;
                $summary['counts']['items_added'] += $change['items']['added'];
                $summary['counts']['items_removed'] += $change['items']['removed'];
                $summary['counts']['items_updated'] += $change['items']['updated'];
                $summary['sections']['updated'][] = ['ref' => $ref, 'title' => $section['title']] + $change;
            }
        }

        foreach ($currentSections as $ref => $section) {
            if (! isset($incomingSections[$ref])) {
                $summary['counts']['sections_removed']++;
                $summary['counts']['items_removed'] += count($section['items']);
                $summary['sections']['removed'][] = ['ref' => $ref, 'title' => $section['title'], 'items' => count($section['items'])];
            }
        }

        $sharedNow = array_values(array_filter(array_keys($currentSections), fn ($r) => isset($incomingSections[$r])));
        $sharedNew = array_values(array_filter(array_keys($incomingSections), fn ($r) => isset($currentSections[$r])));
        $summary['order_changed'] = $sharedNow !== $sharedNew;

        foreach (['added', 'removed', 'updated'] as $kind) {
            $summary['sections'][$kind] = array_slice($summary['sections'][$kind], 0, self::LIST_LIMIT);
        }

        $summary['unchanged'] = $current !== null
            && ! $summary['config']
            && ! $summary['order_changed']
            && array_sum($summary['counts']) === 0;

        return $summary;
    }

    private function compareConfig(?array $current, array $incoming, array &$summary): void
    {
        if ($current === null) {
            $summary['config'][] = ['field' => 'homepage', 'change' => 'created'];

            return;
        }

        foreach (['label', 'is_active', 'site_logo', 'catalog_background'] as $field) {
            if ($this->differs($current[$field] ?? null, $incoming[$field] ?? null)) {
                $summary['config'][] = ['field' => $field, 'change' => 'changed'];
            }
        }

        $keys = array_unique(array_merge(array_keys($current['site_settings'] ?? []), array_keys($incoming['site_settings'] ?? [])));
        sort($keys);
        foreach ($keys as $key) {
            $before = $current['site_settings'][$key] ?? null;
            $after = $incoming['site_settings'][$key] ?? null;
            if ($this->differs($before, $after)) {
                $summary['config'][] = ['field' => "site_settings.$key", 'change' => $before === null ? 'added' : ($after === null ? 'removed' : 'changed')];
            }
        }
    }

    /** @return array{fields: array, items: array}|null */
    private function compareSection(array $current, array $incoming): ?array
    {
        $fields = [];
        foreach (self::SECTION_FIELDS as $field) {
            if ($this->differs($current[$field] ?? null, $incoming[$field] ?? null)) {
                $fields[] = $field;
            }
        }
        if ($current['sort_order'] !== $incoming['sort_order']) {
            $fields[] = 'sort_order';
        }

        $items = ['added' => 0, 'removed' => 0, 'updated' => 0, 'reordered' => false];
        $now = $this->itemsByKey($current['items']);
        $new = $this->itemsByKey($incoming['items']);

        foreach ($new as $key => $item) {
            if (! isset($now[$key])) {
                $items['added']++;
            } elseif ($this->itemChanged($now[$key], $item)) {
                $items['updated']++;
            }
        }
        $items['removed'] = count(array_diff_key($now, $new));
        $items['reordered'] = array_keys(array_intersect_key($now, $new)) !== array_keys(array_intersect_key($new, $now));

        if (! $fields && ! $items['added'] && ! $items['removed'] && ! $items['updated'] && ! $items['reordered']) {
            return null;
        }

        return ['fields' => $fields, 'items' => $items];
    }

    private function itemChanged(array $current, array $incoming): bool
    {
        foreach (self::ITEM_FIELDS as $field) {
            if ($this->differs($current[$field] ?? null, $incoming[$field] ?? null)) {
                return true;
            }
        }

        return $current['sort_order'] !== $incoming['sort_order'];
    }

    private function byRef(array $sections): array
    {
        $map = [];
        foreach ($sections as $section) {
            $map[$section['ref']] = $section;
        }

        return $map;
    }

    /** Items are identified by kind and their position among items of that kind. */
    public static function itemsByKey(array $items): array
    {
        usort($items, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);
        $count = [];
        $map = [];
        foreach ($items as $item) {
            $count[$item['kind']] = ($count[$item['kind']] ?? 0) + 1;
            $map["{$item['kind']}#{$count[$item['kind']]}"] = $item;
        }

        return $map;
    }

    private function differs(mixed $a, mixed $b): bool
    {
        return Canonical::json(Canonical::withoutNames($a)) !== Canonical::json(Canonical::withoutNames($b));
    }
}
