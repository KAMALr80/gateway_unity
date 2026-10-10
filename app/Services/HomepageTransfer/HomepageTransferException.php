<?php

namespace App\Services\HomepageTransfer;

use RuntimeException;

/** A refusal the admin should see: carries a machine-readable code and a safe, user-facing message. */
class HomepageTransferException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
