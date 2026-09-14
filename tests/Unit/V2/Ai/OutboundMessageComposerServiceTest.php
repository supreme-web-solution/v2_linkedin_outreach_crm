<?php

namespace Tests\Unit\V2\Ai;

use App\Ai\Agents\OutboundCopyAgent;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\OutboundMessageComposerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutboundMessageComposerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_compose_uses_laravel_ai_structured_copy_agent(): void
    {
        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('socifusion_ai.model_failover', ['openai' => null]);

        [$user, $org] = $this->seedOrg();

        OutboundCopyAgent::fake([
            [
                'body' => "Hi team — noticed your trust platform for construction stakeholders.\n\nWe help teams like yours open qualified conversations with the right buyers.\n\nCurious how you're sourcing those intros today?",
                'subject' => 'Quick thought on stakeholder outreach',
            ],
        ]);

        $result = app(OutboundMessageComposerService::class)->compose(
            $user,
            $org->id,
            'email',
            'Send a researched cold email',
            ['email' => 'vicken@example.com', 'display_name' => 'Vicken'],
            'Title: Phanrise\nURL: https://engr.phanrise.com/\nConstruction trust platform for stakeholders.',
        );

        OutboundCopyAgent::assertPromptedTimes(1);
        $this->assertStringContainsString('trust platform', $result['body']);
        $this->assertSame('Quick thought on stakeholder outreach', $result['subject']);
    }

    public function test_compose_from_evidence_first_touch_mode(): void
    {
        config()->set('ai.providers.openai.key', 'sk-test');
        config()->set('socifusion_ai.model_failover', ['openai' => null]);

        OutboundCopyAgent::fake([
            [
                'body' => 'Ada — saw your work on analytical engines. How are you handling model review today?',
                'subject' => null,
            ],
        ]);

        $result = app(OutboundMessageComposerService::class)->composeFromEvidence(
            'linkedin',
            'first_touch',
            'Earn a reply from CTOs',
            [
                'full_name' => 'Ada Lovelace',
                'headline' => 'Analytical engines',
            ],
        );

        $this->assertStringContainsString('Ada', $result['body']);
        $this->assertNull($result['subject']);
    }

    /**
     * @return array{0:User,1:V2Organization}
     */
    private function seedOrg(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Copy Org',
            'slug' => 'copy-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();

        return [$user->fresh(), $org];
    }
}
