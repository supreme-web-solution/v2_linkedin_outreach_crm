<?php

namespace App\V2\Contracts\Providers;

interface WebhookProviderInterface
{
    public function verifySignature(array $headers, string $rawBody): bool;

    public function parseEvent(array $payload): array;

    public function eventType(array $payload): string;

    public function eventId(array $payload): string;
}
