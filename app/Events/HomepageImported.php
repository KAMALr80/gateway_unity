<?php

namespace App\Events;

use App\Models\HomepageImport;

/**
 * Dispatched once, after a homepage import has been committed AND verified. Anything that must refresh after a
 * homepage change (a cache, a CDN, a storefront rebuild hook) listens here, so it can never run for an import
 * that failed or was rolled back.
 */
class HomepageImported
{
    public function __construct(public readonly HomepageImport $import) {}
}
