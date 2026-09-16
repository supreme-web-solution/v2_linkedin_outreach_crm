<?php

namespace Tests\Unit\V2\Services;

use App\V2\Services\OpenAiUserError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OpenAiUserErrorSociAgentTest extends TestCase
{
    public function test_billing_credits_message_hides_provider_details(): void
    {
        $previous = new RuntimeException('You have no credits remaining. Add credits to continue using the API at https://platform.openai.com');
        $e = new RuntimeException('Application rate limited by AI provider [openai].', 429, $previous);

        $msg = OpenAiUserError::forSociAgent($e);

        $this->assertSame(OpenAiUserError::SOCI_CONTACT_ADMIN_BILLING, $msg);
        $this->assertStringContainsString('$', $msg);
        $this->assertStringNotContainsString('OpenAI', $msg);
        $this->assertStringNotContainsString('OPENROUTER', $msg);
        $this->assertStringNotContainsString('platform.openai', $msg);
    }

    public function test_pure_rate_limit_does_not_expose_keys(): void
    {
        $e = new RuntimeException('Application rate limited by AI provider [openai].');

        $msg = OpenAiUserError::forSociAgent($e);

        $this->assertSame(OpenAiUserError::SOCI_TRY_AGAIN, $msg);
        $this->assertStringNotContainsString('OPENROUTER', $msg);
        $this->assertStringNotContainsString('OpenAI', $msg);
    }
}
