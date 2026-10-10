<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Product;
use App\Services\MediaThumbnails;
use Illuminate\Console\Command;

/**
 * Pre-generates WebP variants for product images so the first shopper doesn't wait for the slow ERP origin.
 * Read-only for the database; each source is downloaded once and skipped on later runs.
 */
class WarmImageVariants extends Command
{
    protected $signature = 'media:warm-variants
                            {--limit=0 : Stop after this many images (0 = all)}
                            {--all-images : Include gallery images, not only primary images}';

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

        $query = Media::query()->where('mediable_type', Product::class);
        if (! $this->option('all-images')) {
            $query->where('is_primary', true);
        }

        foreach ($query->lazyById(200) as $media) {
            if ($limit && $done + $failed >= $limit) {
                break;
            }
            if (! $thumbnails->eligible($media->public_url)) {
                $skipped++;

                continue;
            }

            $thumbnails->resolve($media, $largest) !== null ? $done++ : $failed++;
        }

        $this->info("Variants ready: {$done}, failed: {$failed}, skipped (not eligible): {$skipped}");

        return self::SUCCESS;
    }
}
