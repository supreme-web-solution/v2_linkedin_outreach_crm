<?php

namespace Tests\Unit\V2\Ai;

use App\V2\Ai\Services\PlanSequenceNodeBuilder;
use App\V2\Outreach\OutreachChannelRegistry;
use Tests\TestCase;

class PlanSequenceActionNormalizeTest extends TestCase
{
    public function test_connect_alias_maps_to_send_invite(): void
    {
        $this->assertSame(
            'send_invite',
            OutreachChannelRegistry::normalizeAction('linkedin', 'connect'),
        );
        $this->assertSame(
            'send_invite',
            OutreachChannelRegistry::normalizeAction('linkedin', 'connection_request'),
        );
    }

    public function test_structured_steps_never_emit_connect(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'channels' => 'LinkedIn + Email',
            'sequence_steps' => [
                ['type' => 'action', 'channel' => 'linkedin', 'action' => 'connect', 'label' => 'Connection request'],
                ['type' => 'delay', 'wait_days' => 3],
                ['type' => 'action', 'channel' => 'email', 'action' => 'email', 'subject' => 'Hi', 'body' => 'Hello'],
            ],
        ]);

        $actions = collect($resolved['node_model'])
            ->where('type', 'action')
            ->pluck('action')
            ->all();

        $this->assertSame(['send_invite', 'send_email'], $actions);
        $this->assertSame('Send Invite', $resolved['node_model'][0]['label']);
    }
}
