<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A code a product is sold under besides its sku (see the product_barcodes migration). Codes are kept clean:
 * trimmed, inner runs of spaces collapsed, never empty, and once per product.
 */
class ProductBarcode extends Model
{
    public const MAX_LENGTH = 191;

    protected $fillable = ['product_id', 'code', 'source'];

    protected static function booted(): void
    {
        // The last line of defence: a blank or oversized code is never saved, whoever writes it.
        static::saving(function (ProductBarcode $barcode) {
            $barcode->code = self::clean($barcode->code);

            return $barcode->code !== null;
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Trimmed with inner whitespace runs collapsed to one space; null when nothing usable is left. */
    public static function clean(mixed $code): ?string
    {
        if (! is_string($code) && ! is_int($code)) {
            return null;
        }

        // \s plus the no-break, zero-width and BOM characters that pasted codes carry.
        $code = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', ' ', (string) $code) ?? '';
        $code = trim($code);

        return ($code === '' || mb_strlen($code) > self::MAX_LENGTH) ? null : $code;
    }

    /**
     * The codes the ERP sent for one product, ready to save: each cleaned, blanks dropped, and every code once
     * (compared without case, as the database's unique index does). A code equal to the product's own sku is
     * dropped too, because the sku is already on the product.
     *
     * @param  array<int, mixed>  $raw  strings, or {code, source} objects
     * @return list<array{code: string, source: ?string}>
     */
    public static function normalize(array $raw, ?string $sku = null): array
    {
        $seen = [];
        if (($ownSku = self::clean($sku)) !== null) {
            $seen[mb_strtolower($ownSku)] = true;
        }

        $codes = [];
        foreach ($raw as $item) {
            $code   = self::clean(is_array($item) ? ($item['code'] ?? null) : $item);
            $source = is_array($item) && is_string($item['source'] ?? null) ? mb_substr(trim($item['source']), 0, 32) : null;

            if ($code === null || isset($seen[$key = mb_strtolower($code)])) {
                continue;
            }

            $seen[$key] = true;
            $codes[]    = ['code' => $code, 'source' => $source === '' ? null : $source];
        }

        return $codes;
    }

    /**
     * What to look a scanned or typed code up by. Some scanners add a leading 0 (a 12-digit UPC-A read as a
     * 13-digit EAN-13) and some stored barcodes have one, so an all-digit code also matches with one 0 added
     * and with one leading 0 removed.
     *
     * @return list<string>
     */
    public static function lookupCodes(mixed $code): array
    {
        $code = self::clean($code);
        if ($code === null) {
            return [];
        }

        $codes = [$code];
        if (ctype_digit($code)) {
            $codes[] = '0'.$code;
            if (strlen($code) > 1 && $code[0] === '0') {
                $codes[] = substr($code, 1);
            }
        }

        return array_values(array_unique($codes));
    }
}
