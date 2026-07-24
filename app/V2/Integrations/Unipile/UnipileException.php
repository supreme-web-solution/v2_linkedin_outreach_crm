<?php

namespace App\V2\Integrations\Unipile;

use RuntimeException;

class UnipileException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 502,
        public readonly array $context = []
    ) {
        parent::__construct($message);
    }
}
