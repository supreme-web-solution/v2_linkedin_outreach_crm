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

    public function test_instagram_prose_follow_up_stays_on_instagram_channel(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'channels' => 'Instagram',
            'primary_channel' => 'instagram',
            'sequence' => [
                'Instagram DM — personalized after research, no pitch',
                'Wait 4 days',
                'Light follow-up if no reply',
            ],
        ]);

        $actions = collect($resolved['node_model'])
            ->where('type', 'action')
            ->values();

        $this->assertCount(2, $actions);
        $this->assertSame('instagram', $actions[0]['channel']);
        $this->assertSame('instagram', $actions[1]['channel']);
        $this->assertSame('send_message', $actions[1]['action']);
    }

    public function test_one_shot_email_has_no_waits_or_followups(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'channels' => 'Email',
            'one_shot' => true,
            'goal' => 'Send a one-time webinar invitation email',
            'subject' => 'You are invited',
            'message' => 'Hi, join our webinar.',
        ]);

        $types = collect($resolved['node_model'])->pluck('type')->all();
        $this->assertSame(['action', 'end'], $types);
        $this->assertSame('send_email', $resolved['node_model'][0]['action']);
        $this->assertSame('You are invited', $resolved['node_model'][0]['config']['subject']);
        $this->assertStringContainsString('webinar', $resolved['node_model'][0]['config']['body']);
    }

    public function test_one_shot_linkedin_greeting_is_single_dm(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'channels' => 'LinkedIn',
            'goal' => 'Send a greeting message to Eleazar',
            'message' => 'Hello Eleazar, good evening.',
            'first_degree_only' => true,
        ]);

        $this->assertTrue(app(PlanSequenceNodeBuilder::class)->isOneShotIntent([
            'goal' => 'Send a greeting message to Eleazar',
        ]));
        $actions = collect($resolved['node_model'])->where('type', 'action')->values();
        $this->assertCount(1, $actions);
        $this->assertSame('send_message', $actions[0]['action']);
        $this->assertSame('Hello Eleazar, good evening.', $actions[0]['config']['message']);
    }

    public function test_one_time_greeting_goal_never_uses_wait_template(): void
    {
        $resolved = app(PlanSequenceNodeBuilder::class)->resolve([
            'channels' => 'LinkedIn',
            'goal' => 'Send a one-time greeting to Eleazar Nzerem at https://www.linkedin.com/in/eleazarnzerem',
            'message' => 'Hello Eleazar, good evening.',
            'first_degree_only' => true,
            'profile_url' => 'https://www.linkedin.com/in/eleazarnzerem',
        ]);

        $types = collect($resolved['node_model'])->pluck('type')->all();
        $this->assertSame(['action', 'end'], $types);
        $this->assertFalse(collect($resolved['node_model'])->contains(fn ($n) => ($n['type'] ?? '') === 'delay'));
    }
}
