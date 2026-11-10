<?php

namespace Tests\Unit\Campaign;

use App\V2\Campaign\CampaignLeadProfileService;
use App\V2\Integrations\Unipile\UnipileProvider;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class CampaignLeadInviteListV2Test extends TestCase
{
    public function test_sent_invites_match_v2_relation_request_user_fields(): void
    {
        $provider = Mockery::mock(UnipileProvider::class);
        $provider->shouldReceive('listSentInvitations')
            ->once()
            ->andReturn([
                'object' => 'RelationRequestList',
                'data' => [[
                    'id' => 'req_1',
                    'user' => [
                        'id' => 'ACoAAProspect',
                        'public_identifier' => 'jane-doe',
                    ],
                ]],
            ]);

        $service = app(CampaignLeadProfileService::class);
        $stillPending = $this->invokePrivate($service, 'isStillInSentInvitations', [
            $provider,
            'acc_li',
            'ACoAAProspect',
            'jane-doe',
        ]);

        $this->assertTrue($stillPending);
    }

    public function test_empty_v2_data_list_means_invite_cleared_not_parse_failure(): void
    {
        $provider = Mockery::mock(UnipileProvider::class);
        $provider->shouldReceive('listSentInvitations')
            ->once()
            ->andReturn([
                'object' => 'RelationRequestList',
                'data' => [],
            ]);

        $service = app(CampaignLeadProfileService::class);
        $stillPending = $this->invokePrivate($service, 'isStillInSentInvitations', [
            $provider,
            'acc_li',
            'ACoAAProspect',
            'jane-doe',
        ]);

        $this->assertFalse($stillPending);
    }

    public function test_relations_match_nested_user_ids_on_v2_envelope(): void
    {
        $provider = Mockery::mock(UnipileProvider::class);
        $provider->shouldReceive('listRelations')
            ->once()
            ->andReturn([
                'object' => 'UserRelationsList',
                'data' => [[
                    'user' => [
                        'id' => 'ACoAAProspect',
                        'public_identifier' => 'jane-doe',
                    ],
                ]],
            ]);

        $service = app(CampaignLeadProfileService::class);
        $found = $this->invokePrivate($service, 'isInRecentRelations', [
            $provider,
            'acc_li',
            'ACoAAProspect',
            'jane-doe',
        ]);

        $this->assertTrue($found);
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function invokePrivate(object $service, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($service, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($service, $args);
    }
}
