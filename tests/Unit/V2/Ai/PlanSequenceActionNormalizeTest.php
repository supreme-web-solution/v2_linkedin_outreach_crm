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

        $topActions = collect($resolved['node_model'])
            ->where('type', 'action')
            ->pluck('action')
            ->all();

        $this->assertSame(['send_invite'], $topActions);
        $this->assertSame('Send Invite', $resolved['node_model'][0]['label']);

        $condition = collect($resolved['node_model'])->firstWhere('type', 'condition');
        $this->assertNotNull($condition);
        $this->assertSame('invite_accepted', $condition['condition']);
        $this->assertSame(
            ['send_email'],
            collect($condition['branches']['not_accepted'])->where('type', 'action')->pluck('action')->all(),
        );
    }

    public function test_after_acceptance_prose_uses_invite_accepted_not_second_invite(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'channels' => 'LinkedIn',
            'sequence' => [
                'Empty invite',
                'Wait 2 days',
                'After acceptance',
                'Wait 3 days',
                'Diagnostic message',
                'Wait 5 days',
                'Value follow-up',
            ],
        ]);

        $topTypes = collect($resolved['node_model'])->pluck('type')->all();
        $this->assertSame(['action', 'delay', 'condition', 'end'], $topTypes);

        $invites = collect($resolved['node_model'])
            ->where('type', 'action')
            ->where('action', 'send_invite')
            ->count();
        $this->assertSame(1, $invites);

        $condition = collect($resolved['node_model'])->firstWhere('type', 'condition');
        $this->assertSame('invite_accepted', $condition['condition']);

        $acceptedActions = collect($condition['branches']['accepted'])
            ->where('type', 'action')
            ->pluck('action')
            ->all();
        $this->assertSame(['send_message', 'send_message'], $acceptedActions);
    }

    public function test_structured_invite_accepted_condition_is_preserved(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'channels' => 'LinkedIn',
            'sequence_steps' => [
                ['type' => 'action', 'channel' => 'linkedin', 'action' => 'send_invite'],
                [
                    'type' => 'condition',
                    'channel' => 'linkedin',
                    'condition' => 'has_accept',
                    'branches' => [
                        'accepted' => [
                            ['type' => 'action', 'channel' => 'linkedin', 'action' => 'send_message', 'message' => 'Hi'],
                        ],
                        'not_accepted' => [],
                    ],
                ],
            ],
        ]);

        $condition = collect($resolved['node_model'])->firstWhere('type', 'condition');
        $this->assertSame('invite_accepted', $condition['condition']);
        $this->assertCount(1, $condition['branches']['accepted']);
    }

    public function test_first_degree_audience_strips_invites(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'channels' => 'LinkedIn',
            'first_degree_only' => true,
            'sequence' => [
                'Send Invite (empty note)',
                'After acceptance',
                'Diagnostic message',
                'Wait 3 days',
                'Follow-up',
            ],
        ]);

        $actions = collect($resolved['node_model'])
            ->filter(fn ($n) => ($n['type'] ?? '') === 'action')
            ->pluck('action')
            ->all();

        $this->assertNotContains('send_invite', $actions);
        $this->assertContains('send_message', $actions);
        $this->assertNull(collect($resolved['node_model'])->firstWhere('condition', 'invite_accepted'));
    }
}
