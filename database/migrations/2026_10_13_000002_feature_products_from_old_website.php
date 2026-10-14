<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Starts the featured list with the products featured on the old website (unitywholesales.com/feature-product,
 * October 2026), matched by product slug. Products missing from this catalogue are simply skipped, and the list
 * can be changed afterwards in the admin (Products > product > Featured).
 */
return new class extends Migration
{
    private const SLUGS = [
        'moon-walker-kratom-chewable-tablet-250mg-4tab-10-ct',
        'stokers-proud-moist-snuff-12-oz-5ct',
        'pseudooo-maxx-10ct-bottle',
        'heat-full-spectrum-kratom-extract-4x-10-ct',
        'pseudooo-max-450mg-blister-3pk-10-ct',
        'buffalo-honey-grip-glove-1350',
        'buffalo-orange-jersey-glove-1000',
        'buffalo-latex-coated-large-2400',
        'buffalo-camou-red-lined-glove-1900',
        'buffalo-leather-palm-glove-1700',
        'buffalo-golden-chore-glove-2150-single',
        'buffalo-leather-palm-glove-1800-single',
        'buffalo-lg-maxigrip-nitrile-glove-single',
        'ziploc-storage-bag-29ct-quart',
        'ziploc-sandwich-bag-105ct-65-in-x-575-in',
        'windex-glass-cleaner-17oz',
        'real-tough-tall-kitchen-bag-13gl-50ct-draw',
        'real-tough-lawn-leaf-bag-5ct-39-gallontwist-tie',
        '8-self-acrylic-display',
        'pharmacy-best-rubbing-alcohol-12oz-91-regular',
        'palmolive-diswash-liquid-28oz-lemon',
        'palmolive-dishwash-liquid-126-oz',
        'little-trees-car-freshner-35oz-spray-black-ice-6-ct',
        'little-trees-car-freshener-1ct-fiber-can-new-car-4-cans',
        'little-trees-car-freshener-1ct-fiber-can-black-ice-4-cans',
        'glade-air-freshner-spray-8oz-2pk',
        'fabuloso-multi-purpose-cleaner-169-0z-5-lt-case-3-ct',
        'dawn-dishwash-liquid-18-oz',
        'clorox-bleach-43-oz127-l-6-ct',
        'bounce-dryer-sheets',
        'always-ultra-thin-pads-20ct-size-2-flexi-wings',
        'always-ultra-thin-17ct-size-3',
        'gillette-disposable-razor-g2-double-blades-5ct',
        'always-ultra-thin-15ct-size-5',
        'always-ultra-day-11ct-size-2-long-with-wings',
        'always-regular-18ct-size-1infinity-with-wings',
        'ajax-dishwash-liquid-124-oz',
        'geek-us-5ct',
        'arm-hammer-baking-soda-8oz',
        'opia-4x-pseudo-indoxyl-480mg-10ct-box',
        'alec-bradley-chunk-sampler-5-5ct',
        'cohiba-rubicon-cigar-box-20ct',
        'zig-zag-paper-organic-hemp-cones-mini-70s-size-8pk-of-18-ct-box',
        'rocky-patel-fresh-pack-of-4pk-10ct',
        'zour-dream-mushroom-chocolate-30ct-display',
        'zour-dream-mushroom-gummies-30ct-display',
        'zour-chews-30000mg-gummies-30ct-per-jar',
        'venera-exotic-pre-roll-thcp-1gm-40ct-box',
    ];

    public function up(): void
    {
        DB::table('products')->whereIn('slug', self::SLUGS)->whereNull('parent_id')->update(['is_featured' => true]);
    }

    public function down(): void
    {
        DB::table('products')->whereIn('slug', self::SLUGS)->update(['is_featured' => false]);
    }
};
