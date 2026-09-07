<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiConversation;
use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\Models\V2Organization;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Services\AutonomyContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutonomyContextServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_prefix_reflects_current_mode(): void
    {
        $settings = new AiEmployeeSetting([
            'autonomy_level' => AiAutonomyLevel::Autopilot->value,
        ]);

        $prefix = app(AutonomyContextService::class)->promptPrefix($settings);

        $this->assertStringContainsString('AUTOPILOT', $prefix);
        $this->assertStringContainsString('level 3', $prefix);
    }

    public function test_sync_conversation_mode_detects_switch(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Mode Org',
            'slug' => 'mode-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['autonomy_level' => AiAutonomyLevel::Autonomous->value],
        ]);

        $settings = new AiEmployeeSetting([
            'autonomy_level' => AiAutonomyLevel::Copilot->value,
        ]);

        $note = app(AutonomyContextService::class)->syncConversationMode($conversation, $settings);

        $this->assertNotNull($note);
        $this->assertStringContainsString('Copilot', (string) $note);
        $this->assertSame(
            AiAutonomyLevel::Copilot->value,
            (int) ($conversation->fresh()->meta['autonomy_level'] ?? 0),
        );
    }
}
