<?php

namespace Tests\Unit\Services;

use App\V2\Integrations\Unipile\UnipileException;
use App\V2\Services\IntegrationUserErrorMapper;
use Tests\TestCase;

class IntegrationUserErrorMapperTest extends TestCase
{
    public function test_subscription_required_returns_admin_message(): void
    {
        $exception = new UnipileException('subscription required', 403, [
            'error_code' => 'errors/subscription_required',
        ]);

        $message = IntegrationUserErrorMapper::forConnection($exception, 'Google Calendar');

        $this->assertStringContainsString('subscription', strtolower($message));
        $this->assertStringContainsString('administrator', strtolower($message));
        $this->assertStringNotContainsString('schema', $message);
    }

    public function test_invalid_parameters_returns_configuration_message(): void
    {
        $exception = new UnipileException('Unipile API error (HTTP 400): Invalid parameters', 400, [
            'error_code' => 'errors/invalid_parameters',
        ]);

        $message = IntegrationUserErrorMapper::forConnection($exception, 'Google Calendar');

        $this->assertStringContainsString('Google Calendar', $message);
        $this->assertStringContainsString('administrator', strtolower($message));
        $this->assertLessThan(200, strlen($message));
    }

    public function test_generic_exception_returns_short_fallback(): void
    {
        $message = IntegrationUserErrorMapper::forConnection(new \RuntimeException('massive json blob'), 'WhatsApp');

        $this->assertStringContainsString('WhatsApp', $message);
        $this->assertStringNotContainsString('massive json blob', $message);
    }
}
