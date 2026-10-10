<?php

namespace App\Events;

use App\Models\HomepageImport;

/** Dispatched once, after a homepage rollback has been committed and verified. */
class HomepageRestored
{
    public function __construct(public readonly HomepageImport $rollback) {}
}
