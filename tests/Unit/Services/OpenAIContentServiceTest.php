<?php

namespace Tests\Unit\Services;

use App\V2\Services\OpenAIContentService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIContentServiceTest extends TestCase
{
    public function test_generate_linkedin_post_strips_markdown_bold(): void
    {
        config(['services.openai.key' => 'test-key']);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "**Mastering cooking** is a great skill.\n\n#Food #Cooking",
                    ],
                ]],
            ], 200),
        ]);

        $service = app(OpenAIContentService::class);
        $result = $service->generateLinkedInPost('cooking');

        $this->assertStringNotContainsString('**', $result['content']);
        $this->assertSame('Mastering cooking is a great skill.', $result['content']);
        $this->assertSame('#Food #Cooking', $result['hashtags']);
    }

    public function test_generate_linkedin_post_can_include_image_when_requested(): void
    {
        config(['services.openai.key' => 'test-key']);
        config(['services.openai.image_model' => 'gpt-image-2']);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Post body text'],
                ]],
            ], 200),
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode('png-bytes')]],
            ], 200),
        ]);

        $this->mock(\App\V2\Services\CloudinaryMediaService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('uploadBinary')->once()->andReturn([
                'url' => 'https://res.cloudinary.com/demo/image/upload/v1/ai.png',
                'secure_url' => 'https://res.cloudinary.com/demo/image/upload/v1/ai.png',
                'public_id' => 'content-ai-images/u7_ai',
                'resource_type' => 'image',
            ]);
        });

        $service = app(OpenAIContentService::class);
        $result = $service->generateLinkedInPost('cooking', 'professional', 'medium', true, 7);

        $this->assertSame('Post body text', $result['content']);
        $this->assertArrayHasKey('image', $result);
        $this->assertSame('content-ai-images/u7_ai', $result['image']['path']);
    }
}
