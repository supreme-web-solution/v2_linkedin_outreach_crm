<?php

namespace App\V2\Ai\Services;

use App\Models\AiChannelLinkCode;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class WhatsAppCommandLinkPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function payload(AiChannelLinkCode $code): array
    {
        $bot = (string) config('socifusion_ai.zernio.from_number', '');
        $digits = preg_replace('/\D+/', '', $bot) ?? '';
        $deepLink = $digits !== ''
            ? 'https://wa.me/'.$digits.'?text='.urlencode($code->code)
            : null;

        return [
            'code' => $code->code,
            'expires_at' => $code->expires_at,
            'bot_number' => $bot,
            'deep_link' => $deepLink,
            'qr_svg' => $deepLink !== null ? $this->qrSvg($deepLink) : null,
            'instructions' => $bot !== ''
                ? "On your phone's WhatsApp app, message {$bot} with code {$code->code}"
                : "Set ZERNIO_FROM_NUMBER, then message the bot from your phone with {$code->code}",
            'desktop_hint' => 'Most people connect from the WhatsApp app on their phone — scan the QR or copy the number and code below.',
            'mobile_hint' => 'Tap Open in WhatsApp to pre-fill the code, then send the message.',
        ];
    }

    private function qrSvg(string $url): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle(220, 2, null, null, Fill::uniformColor(new Rgb(0, 0, 0), new Rgb(255, 255, 255))),
                new SvgImageBackEnd,
            )
        ))->writeString($url);

        return trim(str_replace("\n", '', $svg));
    }
}
