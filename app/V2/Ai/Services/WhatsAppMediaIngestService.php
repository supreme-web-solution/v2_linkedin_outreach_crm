<?php

namespace App\V2\Ai\Services;

use App\Models\AiChannelIdentity;
use App\Models\AiConversation;
use App\Models\User;
use App\V2\Services\CloudinaryMediaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppMediaIngestService
{
    public function __construct(
        private readonly CloudinaryMediaService $cloudinary,
    ) {}

    /**
     * Download a WhatsApp image and attach to the Command Center conversation for Alex tools.
     *
     * @param  array{type?:string,url?:string,mime?:string}  $media
     * @return array{ai_image_url:string,ai_image_path:string}|null
     */
    public function storeForConversation(
        AiConversation $conversation,
        User $user,
        array $media,
    ): ?array {
        $url = trim((string) ($media['url'] ?? ''));
        if ($url === '') {
            return null;
        }

        if (! $this->cloudinary->isConfigured()) {
            Log::warning('[WhatsAppMedia] Cloudinary not configured — cannot store inbound image');

            return null;
        }

        try {
            $binary = $this->downloadMedia($url);
            $upload = $this->cloudinary->uploadBinary(
                $binary,
                'whatsapp-images/u'.$user->id,
                'wa-image.jpg',
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        $stored = [
            'ai_image_url' => (string) ($upload['secure_url'] ?: $upload['url']),
            'ai_image_path' => (string) $upload['public_id'],
            'received_at' => now()->toIso8601String(),
        ];

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $conversation->forceFill([
            'meta' => array_merge($meta, ['pending_whatsapp_image' => $stored]),
        ])->save();

        return [
            'ai_image_url' => $stored['ai_image_url'],
            'ai_image_path' => $stored['ai_image_path'],
        ];
    }

    private function downloadMedia(string $url): string
    {
        $response = Http::timeout(60)->get($url);
        if (! $response->successful()) {
            $apiKey = (string) config('socifusion_ai.zernio.api_key', '');
            if ($apiKey !== '') {
                $response = Http::withToken($apiKey)->timeout(60)->get($url);
            }
        }

        if (! $response->successful()) {
            throw new \RuntimeException('Could not download WhatsApp image.');
        }

        $body = $response->body();
        if ($body === '') {
            throw new \RuntimeException('WhatsApp image download was empty.');
        }

        return $body;
    }
}
