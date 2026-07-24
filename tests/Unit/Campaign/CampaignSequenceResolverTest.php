<?php

namespace Tests\Unit\Campaign;

use App\V2\Campaign\CampaignSequenceResolver;
use Tests\TestCase;

class CampaignSequenceResolverTest extends TestCase
{
    public function test_resolve_next_node_after_branch_wait_step(): void
    {
        $resolver = new CampaignSequenceResolver();
        $nodes = [
            ['key' => 1, 'type' => 'action', 'value' => 'send-invite', 'label' => 'Send Invite'],
            ['key' => 2, 'type' => 'condition', 'value' => 'accepted', 'label' => 'Invite Accepted?', 'branches' => [
                'accepted' => [
                    ['key' => 3, 'type' => 'delay', 'value' => 0, 'time' => 'hours', 'label' => 'Wait 0 hours'],
                    ['key' => 4, 'type' => 'action', 'value' => 'endorse', 'label' => 'Endorse Skills'],
                ],
                'not_accepted' => [],
            ]],
            ['key' => 99, 'type' => 'end', 'label' => 'End'],
        ];

        $this->assertSame(4, $resolver->resolveNextNodeKey($nodes, 3, true));
        $this->assertSame(99, $resolver->resolveNextNodeKey($nodes, 4, true));
    }

    public function test_zero_hour_delay_is_three_seconds(): void
    {
        $resolver = new CampaignSequenceResolver();

        $this->assertSame(3, $resolver->delaySeconds([
            'type' => 'delay',
            'value' => 0,
            'time' => 'hours',
        ]));
    }
}
