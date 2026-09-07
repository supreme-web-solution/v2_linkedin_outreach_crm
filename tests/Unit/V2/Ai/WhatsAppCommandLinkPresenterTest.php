<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiChannelLinkCode;
use App\V2\Ai\Services\WhatsAppCommandLinkPresenter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsAppCommandLinkPresenterTest extends TestCase
{
    public function test_payload_includes_qr_and_phone_first_hints(): void
    {
        config([
            'socifusion_ai.zernio.from_number' => '+19295320453',
        ]);

        $code = new AiChannelLinkCode([
            'code' => 'LINK-TEST1',
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);

        $payload = app(WhatsAppCommandLinkPresenter::class)->payload($code);

        $this->assertSame('LINK-TEST1', $payload['code']);
        $this->assertSame('+19295320453', $payload['bot_number']);
        $this->assertStringContainsString('wa.me/19295320453', $payload['deep_link']);
        $this->assertStringContainsString('LINK-TEST1', $payload['deep_link']);
        $this->assertStringContainsString('<svg', $payload['qr_svg']);
        $this->assertStringContainsString('phone', strtolower($payload['desktop_hint']));
    }
}
