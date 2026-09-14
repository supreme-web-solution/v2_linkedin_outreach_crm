<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\LongPasteDigestService;
use App\V2\Ai\Services\SemanticTurnPlanService;
use App\V2\Ai\Services\WorkstreamMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LongPasteDigestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_keeps_headings_not_first_600_chars_only(): void
    {
        $body = "update my icp with this:\n\n";
        $body .= "## Company Profile\nEstablished startups and SMEs.\n\n";
        $body .= "## Primary Buyer\n1. Founder\n2. CEO\n3. CTO\n\n";
        $body .= "## Priority Industries\n- SaaS and technology companies\n- Professional services\n- E-commerce\n\n";
        $body .= str_repeat('filler noise about nothing important. ', 80);
        $body .= "\n## Positioning Statement\nCustom technology partner for growing businesses.\n";

        $this->assertGreaterThan(LongPasteDigestService::LARGE_CHARS, strlen($body));

        $digest = app(LongPasteDigestService::class)->digest($body);

        $this->assertStringContainsString('Primary Buyer', $digest);
        $this->assertStringContainsString('Priority Industries', $digest);
        $this->assertStringContainsString('Positioning Statement', $digest);
        $this->assertStringContainsString('long paste retained', $digest);
        $this->assertLessThan(strlen($body), strlen($digest));
    }

    public function test_thread_history_uses_digest_for_long_pastes(): void
    {
        [$user, $conversation] = $this->conversation();

        $long = "## Tier 1\n- Logistics\n- Healthcare technology\n".str_repeat('x', 2000);
        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $long,
            'meta' => app(LongPasteDigestService::class)->enrichUserMeta(['channel' => 'web'], $long),
        ]);
        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Got it.',
            'meta' => ['channel' => 'web'],
        ]);

        $input = app(SemanticTurnPlanService::class)->plannerInput(
            $user,
            (int) $conversation->organization_id,
            'what did I paste about industries?',
            $conversation,
        );

        $threadText = implode("\n", array_column($input['thread'], 'content'));
        $this->assertStringContainsString('Tier 1', $threadText);
        $this->assertStringContainsString('Healthcare technology', $threadText);
        $this->assertLessThan(1800, strlen($threadText));
        $this->assertStringContainsString('long paste retained', $threadText);
    }

    public function test_workstream_remembers_owner_paste_digest(): void
    {
        [, $conversation] = $this->conversation();
        $long = "## Core Problems\n- Manual Operations\n- Disconnected Systems\n".str_repeat('y', 1800);

        app(LongPasteDigestService::class)->rememberOnWorkstream($conversation, $long);
        $conversation->refresh();

        $block = app(WorkstreamMemoryService::class)->promptBlock($conversation);
        $this->assertStringContainsString('Owner long paste', $block);
        $this->assertStringContainsString('Core Problems', $block);
        $this->assertStringContainsString('Manual Operations', $block);
    }

    /**
     * @return array{0: User, 1: AiConversation}
     */
    private function conversation(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Paste Org',
            'slug' => 'paste-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $conversation = AiConversation::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'web',
            'status' => 'active',
            'meta' => [],
        ]);

        return [$user, $conversation];
    }
}
