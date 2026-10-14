<?php

namespace App\Console\Commands;

use App\Models\HomepageSection;
use App\Models\Media;
use App\Models\Product;
use App\Services\MediaThumbnails;
use Illuminate\Console\Command;

/**
 * Pre-generates WebP variants for product images so the first shopper doesn't wait for the slow ERP origin.
 * Read-only for the database; each source is downloaded once and skipped on later runs.
 *
 * The products shoppers see first go first: featured products, best sellers and the homepage carousels, then
 * the rest of the catalogue by id. Already generated images are skipped quickly, so every scheduled run starts
 * by topping up those pages before continuing through the catalogue.
 */
class WarmImageVariants extends Command
{
    protected $signature = 'media:warm-variants
                            {--limit=0 : Stop after this many images (0 = all)}
                            {--all-images : Include gallery images, not only primary images}
                            {--priority-only : Only featured products, best sellers and homepage carousel products}';

    protected $description = 'Generate missing WebP variants for product images';

    public function handle(MediaThumbnails $thumbnails): int
    {
        if (! $thumbnails->canEncode()) {
            $this->error('GD with WebP support is not available.');

            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $largest = max($thumbnails->sizes());
        $done = $skipped = $failed = 0;

        $priorityIds = self::priorityProductIds();
        $media = fn () => tap(Media::query()->where('mediable_type', Product::class), function ($query) {
            if (! $this->option('all-images')) {
                $query->where('is_primary', true);
            }
        });

        $batches = [$media()->whereIn('mediable_id', $priorityIds)];
        if (! $this->option('priority-only')) {
            $batches[] = $media()->whereNotIn('mediable_id', $priorityIds);
        }

        foreach ($batches as $query) {
            foreach ($query->lazyById(200) as $item) {
                if ($limit && $done + $failed >= $limit) {
                    break 2;
                }
                if (! $thumbnails->eligible($item->public_url)) {
                    $skipped++;

                    continue;
                }

                $thumbnails->resolve($item, $largest) !== null ? $done++ : $failed++;
            }
        }

        $this->info("Variants ready: {$done}, failed: {$failed}, skipped (not eligible): {$skipped}");

        return self::SUCCESS;
    }

    /**
     * Products whose images are seen first: featured products, best sellers (with the parents of featured or
     * ranked variants, since the listing shows the parent) and every product picked for a homepage carousel.
     *
     * @return list<int>
     */
    public static function priorityProductIds(): array
    {
        $highlighted = Product::query()
            ->where(fn ($q) => $q->where('is_featured', true)->orWhereNotNull('best_seller_rank'))
            ->get(['id', 'parent_id']);

        $carousel = HomepageSection::query()->where('type', 'product_carousel')->get(['settings'])
            ->flatMap(fn (HomepageSection $section) => $section->settings['product_ids'] ?? []);

        return $highlighted->pluck('id')
            ->merge($highlighted->pluck('parent_id')->filter())
            ->merge($carousel)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
