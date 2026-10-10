<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\HomepageConfig;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Loads the Unity Wholesale homepage (database/seeders/data/unity-homepage.json) into the homepage CMS tables as
 * the "unity" client. The storefront then renders it through GET /api/homepage?client=unity like any other client.
 *
 * Re-runnable: only the "unity" config is replaced. Other clients, products, prices and orders are never touched.
 * Run it against a local database only:  php artisan db:seed --class=UnityHomepageSeeder
 */
class UnityHomepageSeeder extends Seeder
{
    public function run(): void
    {
        $spec = json_decode((string) file_get_contents(database_path('seeders/data/unity-homepage.json')), true, flags: JSON_THROW_ON_ERROR);
        $host = rtrim($spec['image_host'], '/');

        DB::transaction(function () use ($spec, $host) {
            $config = HomepageConfig::updateOrCreate(
                ['client_key' => $spec['client_key']],
                ['label' => $spec['label'], 'is_active' => true, 'site_settings' => $spec['site_settings']],
            );

            // Replace only this client's sections (items are removed by the foreign key cascade).
            $config->sections()->delete();

            foreach ($spec['sections'] as $index => $sectionSpec) {
                $settings = $sectionSpec['settings'] ?? [];

                if ($sectionSpec['type'] === 'product_carousel' && ($settings['source'] ?? 'manual') === 'manual') {
                    $settings['product_ids'] = $this->pickProducts((int) ($settings["pick"] ?? $settings["limit"] ?? 7), (array) ($settings["pick_categories"] ?? []));
                }
                unset($settings["pick"], $settings["pick_categories"]);

                $section = $config->sections()->create([
                    'type' => $sectionSpec['type'],
                    'title' => $sectionSpec['title'],
                    'sort_order' => $index,
                    'is_active' => true,
                    'settings' => $settings,
                ]);

                foreach ($sectionSpec['items'] as $itemIndex => $item) {
                    $section->items()->create([
                        'kind' => $item['kind'],
                        'title' => $item['title'] ?? null,
                        'alt_text' => $item['alt'] ?? '',
                        'link_url' => $item['link'] ?? null,
                        'desktop_image_external_url' => $host.'/'.$item['image'],
                        'sort_order' => $itemIndex,
                        'is_active' => true,
                    ]);
                }
            }
        });
    }

    /**
     * Hand-picked ("manual") carousels need product ids. There is no rating or featured data to rank by, so this
     * takes in-stock catalogue products that have an image, optionally limited to categories whose name contains
     * one of the given keywords (sub-categories included). The list can be replaced from the admin at any time.
     */
    private function pickProducts(int $count, array $categoryKeywords): array
    {
        $query = Product::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->where('in_stock', true)
            ->whereHas('primaryImage');

        if ($categoryKeywords !== []) {
            $roots = Category::query()->where(function ($q) use ($categoryKeywords) {
                foreach ($categoryKeywords as $keyword) {
                    $q->orWhere('name', 'like', '%'.$keyword.'%');
                }
            })->pluck('id');
            $ids = Category::query()->whereIn('id', $roots)->orWhereIn('parent_id', $roots)->pluck('id');
            $query->where(fn ($q) => $q->whereIn('category_id', $ids)->orWhereIn('sub_category_id', $ids));
        }

        return $query->orderByDesc('id')->limit($count)->pluck('id')->all();
    }
}
