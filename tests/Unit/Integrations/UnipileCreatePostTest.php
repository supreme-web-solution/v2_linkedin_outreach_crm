<?php

namespace Tests\Unit\Integrations;

use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UnipileCreatePostTest extends TestCase
{
    public function test_create_post_uses_json_account_scoped_path(): void
    {
        Config::set('services.unipile.base_url', 'https://unipile.test/v2');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);

        Http::fake([
            'unipile.test/v2/acc_123/posts' => Http::response(['id' => 'post_123'], 201),
        ]);

        $provider = app(UnipileProvider::class);
        $result = $provider->createPost('acc_123', 'Hello LinkedIn');

        $this->assertSame('post_123', $result['id']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->url() === 'https://unipile.test/v2/acc_123/posts'
                && ($payload['text'] ?? null) === 'Hello LinkedIn'
                && ! array_key_exists('account_id', $payload);
        });
    }

    public function test_create_post_encodes_attachments_as_base64_json(): void
    {
        Config::set('services.unipile.base_url', 'https://unipile.test/v2');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);

        Storage::fake('public');
        Storage::disk('public')->put('content-images/test.jpg', 'fake-image-bytes');
        $absolutePath = Storage::disk('public')->path('content-images/test.jpg');

        Http::fake([
            'unipile.test/v2/acc_123/posts' => Http::response(['id' => 'post_456'], 201),
        ]);

        $provider = app(UnipileProvider::class);
        $provider->createPost('acc_123', 'Image post', [
            'attachments' => [[
                'path' => $absolutePath,
                'filename' => 'test.jpg',
                'mime' => 'image/jpeg',
            ]],
        ]);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $attachment = $payload['attachments'][0] ?? null;

            return is_array($attachment)
                && ($attachment['filename'] ?? null) === 'test.jpg'
                && ($attachment['content_type'] ?? null) === 'image/jpeg'
                && ($attachment['data'] ?? null) === base64_encode('fake-image-bytes');
        });
    }
}
