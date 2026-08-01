<?php

namespace Tests\Feature\V2;

use App\Models\User;
use App\Models\V2ExtensionToken;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Services\OpenAIContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PostCommentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_generate_comment_requires_auth(): void
    {
        $this->postJson('/api/v2/posts/generate-comment', [
            'post_content' => 'Hello world from a LinkedIn post.',
        ])->assertStatus(401);
    }

    public function test_generate_comment_returns_ai_text(): void
    {
        [$user, $org] = $this->authenticatedContext();
        $token = 'v2ext_post_comment_test';

        V2ExtensionToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
        ]);

        $mock = Mockery::mock(OpenAIContentService::class);
        $mock->shouldReceive('generateLinkedInComment')
            ->once()
            ->with('Great insights on sales automation.', 'professional')
            ->andReturn('Love this take — especially the point about consistency.');
        $this->app->instance(OpenAIContentService::class, $mock);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Organization-Id' => (string) $org->id,
        ])->postJson('/api/v2/posts/generate-comment', [
            'post_content' => 'Great insights on sales automation.',
            'tone' => 'professional',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.comment', 'Love this take — especially the point about consistency.');
    }

    public function test_generate_comment_hides_openai_quota_errors(): void
    {
        [$user, $org] = $this->authenticatedContext();
        $token = 'v2ext_post_comment_quota';

        V2ExtensionToken::query()->create([
            'user_id' => $user->id,
            'name' => 'test',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
        ]);

        $mock = Mockery::mock(OpenAIContentService::class);
        $mock->shouldReceive('generateLinkedInComment')
            ->once()
            ->andThrow(new \RuntimeException(
                'OpenAI request failed: { "error": { "message": "You have no credits remaining.", "code": "credit_balance_exhausted" } }'
            ));
        $this->app->instance(OpenAIContentService::class, $mock);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Organization-Id' => (string) $org->id,
        ])->postJson('/api/v2/posts/generate-comment', [
            'post_content' => 'Great insights on sales automation.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'AI features are temporarily unavailable. Please contact your administrator.');
        $response->assertJsonMissing(['message' => 'You have no credits remaining.']);
    }

    /** @return array{0: User, 1: V2Organization} */
    private function authenticatedContext(): array
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Comment Org',
            'slug' => 'comment-org-'.$user->id,
            'status' => 'active',
        ]);

        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['*'],
            'status' => 'active',
        ]);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return [$user, $organization];
    }
}
