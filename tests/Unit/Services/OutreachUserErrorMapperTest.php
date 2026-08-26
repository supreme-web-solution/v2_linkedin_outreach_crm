<?php

namespace Tests\Unit\Services;

use App\V2\Integrations\Unipile\UnipileException;
use App\V2\Services\OutreachUserErrorMapper;
use Tests\TestCase;

class OutreachUserErrorMapperTest extends TestCase
{
    public function test_stale_provider_chat_error_detects_missing_chat(): void
    {
        $exception = new UnipileException(
            'Messaging error (HTTP 404): The requested resource were not found. Chat not found',
            404,
            ['response' => ['detail' => 'Chat not found', 'type' => 'errors/resource_not_found']]
        );

        $this->assertTrue(OutreachUserErrorMapper::isStaleProviderChatError($exception));
    }

    public function test_stale_provider_chat_error_ignores_missing_account(): void
    {
        $exception = new UnipileException(
            'Messaging error (HTTP 404): Account not found',
            404,
            ['response' => ['detail' => 'Account not found', 'type' => 'errors/resource_not_found']]
        );

        $this->assertFalse(OutreachUserErrorMapper::isStaleProviderChatError($exception));
    }

    public function test_stale_provider_chat_error_ignores_non_404(): void
    {
        $exception = new UnipileException('Chat not found', 500);

        $this->assertFalse(OutreachUserErrorMapper::isStaleProviderChatError($exception));
    }
}
