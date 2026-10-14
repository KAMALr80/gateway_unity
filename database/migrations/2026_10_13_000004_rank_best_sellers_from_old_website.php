<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Starts the Best Sellers ranking with the old website's list (unitywholesales.com/best-sellers, October 2026), in
 * the same order, matched by product slug. Missing products are skipped; ranks can be changed in the admin.
 */
return new class extends Migration
{
    private const SLUGS = [
        'trolli-sour-brite-blasts-peg',
        'njoy-kratom-20ct',
        'xyc-005-14-male-oil-burner-gg',
        'flower-rose-36ct-passion-rose',
        'juice-head',
        'coastal-clouds-vape-juice-60ml-3mg',
        '4-heavy-oil-burner-green',
        'juul-basic-kit-device-charger-display-of-8',
        'earth-kratom-powder',
        'formula-cleaner-16-fl-oz',
        'coastal-clouds-vape-juice-60ml-6mg',
        'cloud-8-delta-10-thc-1ml-carts',
        'elf-bar-ebdesign-10ct',
        'air-factory-3mg-60ml',
        'air-factory-6mg-60ml',
        'earth-kratom-caps',
        'saltbae-50mg',
        'formula-cleaner-12-fl-oz',
        'micro-nc-red-small',
        'vita-opms-gold',
        'saltbae-25mg',
        'flying-monkey-delta-8-disposable-940mg',
        'vita-opms-whole-herb-capsules',
        'cloud-8-delta-10-thc-disposable-1ml',
        'vita-opms-remarkable-herb-powder',
        'escobar-2500-puffs-10ct',
        'lipht-d8-cbd-disposable-2gm-single',
        'hyde-edge-1500-puffs-10ct',
        'lookah-seahorse-pro-2-in-1-vaporizer',
        'royal-blunts-ez-roll-tube',
        'air-factory-36mg-30ml',
        'zion-speciosa-soda',
        'flying-monkey-d8-1gm-cartridge-6ct',
        'exxus-snap-vv-vaporizer',
        'herbal-clean-qcarbo16',
        'flying-monkey-delta-8-disposable',
        'curevana-delta-10-thc-flower',
        'rare-delta-10-10000mg-cartridge',
        'ooze-quatrz-glass-bowl-14mm-male',
        'innevape-75ml',
        'bong-aid-glass-cleaner-16-oz',
    ];

    public function up(): void
    {
        foreach (self::SLUGS as $index => $slug) {
            DB::table('products')->where('slug', $slug)->whereNull('best_seller_rank')->update(['best_seller_rank' => $index + 1]);
        }
    }

    public function down(): void
    {
        DB::table('products')->whereIn('slug', self::SLUGS)->update(['best_seller_rank' => null]);
    }
};
