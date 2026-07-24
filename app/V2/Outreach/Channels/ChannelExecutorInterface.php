<?php

namespace App\V2\Outreach\Channels;

use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;

interface ChannelExecutorInterface
{
    public function channel(): string;

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function execute(
        string $action,
        V2OutreachCampaign $campaign,
        V2OutreachLead $lead,
        array $node,
        array $context,
    ): array;
}
