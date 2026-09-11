<?php

namespace Tests\Feature\Console;

use App\Models\AiActionLog;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PromptRegressionWeeklyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_weekly_prompt_expansion_report_from_observer_misses(): void
    {
        $path = base_path('docs/WeeklyPromptExpansion.md');
        File::delete($path);

        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Prompt Report Org',
            'slug' => 'prompt-report-org-'.uniqid(),
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
            'channel' => 'command_center',
            'status' => 'open',
            'title' => 'Prompt report',
        ]);

        AiActionLog::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'tool' => 'prompt_observer',
            'permission' => 'read',
            'status' => 'unknown_pre_llm',
            'input' => ['message' => 'can you do this magic flow?'],
            'output' => null,
        ]);
        AiActionLog::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'tool' => 'prompt_observer',
            'permission' => 'read',
            'status' => 'fallback_clarifier',
            'input' => ['message' => 'do it'],
            'output' => ['reply' => 'Can you clarify one thing...'],
        ]);

        $this->artisan('ai:prompt-regression-weekly --days=7 --limit=10')
            ->assertSuccessful();

        $this->assertTrue(File::exists($path));
        $contents = File::get($path);
        $this->assertStringContainsString('Weekly Prompt Expansion', $contents);
        $this->assertStringContainsString('can you do this magic flow?', $contents);
    }
}
