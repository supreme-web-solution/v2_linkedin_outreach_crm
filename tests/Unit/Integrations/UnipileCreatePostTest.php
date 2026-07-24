<?php

namespace Tests\Unit\Integrations;

use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UnipileCreatePostTest extends TestCase
{
    public function test_create_post_uses_multipart_form_data(): void
    {
        Config::set('services.unipile.base_url', 'https://unipile.test/api/v1');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);

        Http::fake([
            'unipile.test/api/v1/posts' => Http::response(['id' => 'post_123'], 201),
        ]);

        $provider = app(UnipileProvider::class);
        $result = $provider->createPost('acc_123', 'Hello LinkedIn');

        $this->assertSame('post_123', $result['id']);

        Http::assertSent(function ($request) {
            $contentType = (string) $request->header('Content-Type')[0];

            return $request->url() === 'https://unipile.test/api/v1/posts'
                && str_contains($contentType, 'multipart/form-data')
                && str_contains((string) $request->body(), 'name="account_id"')
                && str_contains((string) $request->body(), 'acc_123')
                && str_contains((string) $request->body(), 'name="text"')
                && str_contains((string) $request->body(), 'Hello LinkedIn');
        });
    }

    public function test_create_post_attaches_image_files(): void
    {
        Config::set('services.unipile.base_url', 'https://unipile.test/api/v1');
        Config::set('services.unipile.api_key', 'test-key');
        Config::set('services.unipile.mock', false);

        Storage::fake('public');
        Storage::disk('public')->put('content-images/test.jpg', 'fake-image-bytes');
        $absolutePath = Storage::disk('public')->path('content-images/test.jpg');

        Http::fake([
            'unipile.test/api/v1/posts' => Http::response(['id' => 'post_456'], 201),
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
            $body = (string) $request->body();

            return str_contains($body, 'name="attachments"')
                && str_contains($body, 'filename="test.jpg"')
                && str_contains($body, 'fake-image-bytes');
        });
    }
}
