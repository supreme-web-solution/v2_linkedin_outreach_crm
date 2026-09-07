<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiChannelIdentity;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\ChannelIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelIdentityDisconnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_disconnects_active_whatsapp_identity_for_user_org(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Test Org',
            'slug' => 'test-org-disconnect',
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        AiChannelIdentity::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'external_id' => '+2349036802727',
            'status' => 'active',
            'verified_at' => now(),
        ]);

        $service = app(ChannelIdentityService::class);
        $this->assertTrue($service->disconnect($user, $org->id, 'whatsapp'));

        $identity = AiChannelIdentity::query()->where('external_id', '+2349036802727')->first();
        $this->assertSame('disconnected', $identity?->status);
        $this->assertNull($identity?->verified_at);
        $this->assertNull($service->resolve('whatsapp', '+2349036802727'));
    }
}
