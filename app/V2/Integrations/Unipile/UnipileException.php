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

    public function isDisconnectedAccount(): bool
    {
        $type = (string) ($this->context['error_code'] ?? '');
        $response = is_array($this->context['response'] ?? null) ? $this->context['response'] : [];
        $responseType = (string) ($response['type'] ?? '');
        $haystack = strtolower($this->getMessage().' '.$type.' '.$responseType);

        return $this->statusCode === 401 && (
            $type === 'errors/disconnected_account'
            || $responseType === 'errors/disconnected_account'
            || str_contains($haystack, 'disconnected account')
        );
    }
}
