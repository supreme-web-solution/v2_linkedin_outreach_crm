<?php

namespace Tests\Unit\V2;

use App\Models\User;
use App\Models\V2AutoResponse;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Services\AutoResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoResponseServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_match_rule_respects_selected_platforms(): void
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Org',
            'slug' => 'org-'.$user->id,
            'status' => 'active',
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'capabilities' => ['*'],
            'status' => 'active',
        ]);

        V2AutoResponse::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'message_type' => 'any',
            'message_body' => 'WhatsApp only',
            'platforms' => ['whatsapp'],
            'enabled' => true,
        ]);

        V2AutoResponse::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'message_type' => 'any',
            'message_body' => 'LinkedIn only',
            'platforms' => ['linkedin'],
            'enabled' => true,
        ]);

        $service = app(AutoResponseService::class);

        $whatsappRule = $service->matchRule($user->id, $organization->id, 'hello', 'whatsapp');
        $linkedinRule = $service->matchRule($user->id, $organization->id, 'hello', 'linkedin');

        $this->assertSame('WhatsApp only', $whatsappRule?->message_body);
        $this->assertSame('LinkedIn only', $linkedinRule?->message_body);
    }

    public function test_empty_platforms_means_all_channels(): void
    {
        $user = User::factory()->create();
        $organization = V2Organization::query()->create([
            'name' => 'Org 2',
            'slug' => 'org2-'.$user->id,
            'status' => 'active',
        ]);

        V2AutoResponse::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'message_type' => 'contains',
            'message_keywords' => 'pricing',
            'message_body' => 'All platforms',
            'platforms' => [],
            'enabled' => true,
        ]);

        $service = app(AutoResponseService::class);

        $this->assertSame(
            'All platforms',
            $service->matchRule($user->id, $organization->id, 'Need pricing info', 'instagram')?->message_body
        );
    }
}
