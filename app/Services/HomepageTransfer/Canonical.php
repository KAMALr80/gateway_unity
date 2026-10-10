<?php

namespace App\Services\HomepageTransfer;

/** Deterministic JSON and hashing, so equal content always gives equal bytes and equal fingerprints. */
final class Canonical
{
    public static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map([self::class, 'sort'], $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    public static function json(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

        return json_encode(self::sort($value), $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }

    /** Drops human-readable names from references, so two environments can be compared by business key only. */
    public static function withoutNames(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isRef = ! array_is_list($value) && count(preg_grep('/^erp_/', array_keys($value))) > 0;

        $out = [];
        foreach ($value as $key => $item) {
            if ($isRef && $key === 'name') {
                continue;
            }
            $out[$key] = self::withoutNames($item);
        }

        return $out;
    }
}
