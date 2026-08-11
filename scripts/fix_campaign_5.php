<?php

/**
 * One-off recovery for SEO Agency Campaign (id=5).
 *
 * 1. Restores node_model (nulled by broken tinker session)
 * 2. Patches wait node 103 → 5 minutes (instead of 1 day)
 * 3. Resets 100 error leads and re-queues Like Post (node 102)
 *
 * Usage on Forge:
 *   cd ~/v2_linkedin_outreach_crm-ddc26gz1.on-forge.com/current
 *   php scripts/fix_campaign_5.php
 *
 * After leads pass the 5-min wait (~30–60 min), restore wait node:
 *   php scripts/fix_campaign_5.php --restore-wait
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$campaignId = 5;
$likeNodeKey = 102;
$waitNodeKey = 103;

$messageAccepted = <<<'MSG'
Hey {{first_name}} — thanks for accepting!

Genuinely curious: as an agency owner, is new client acquisition something you have systemized, or is it still mostly manual/referral-based?

Reason I ask — built a tool that automates targeted LinkedIn outreach to your most ideal prospects. A few agencies are using it to fill their pipeline hands-free.

Open to a quick look if it's relevant?
MSG;

$messageFollowUp = <<<'MSG'
Hey {{first_name}}, this might be the part that actually gets you to try it —

You can grab the entire follower list of any SEO influencer or competitor agency, then run outreach straight to that list. These are people already interested in SEO/AEO — not cold traffic.

Most agency owners I've shown this to didn't realize how fast that turns into booked calls. Takes less time to set up than writing this message did.

Want a quick look at how it's done?
MSG;

function originalNodeModel(string $messageAccepted, string $messageFollowUp): array
{
    return [
        ['key' => 100, 'type' => 'action', 'label' => 'Visit Profile', 'action' => 'visit_profile', 'config' => [], 'channel' => 'linkedin'],
        ['key' => 101, 'time' => 'days', 'type' => 'delay', 'label' => 'Wait 1 day', 'value' => 1],
        ['key' => 102, 'type' => 'action', 'label' => 'Like Post', 'action' => 'like_post', 'config' => [], 'channel' => 'linkedin'],
        ['key' => 103, 'time' => 'days', 'type' => 'delay', 'label' => 'Wait 1 day', 'value' => 1],
        ['key' => 104, 'type' => 'action', 'label' => 'Visit Profile', 'action' => 'visit_profile', 'config' => [], 'channel' => 'linkedin'],
        ['key' => 105, 'type' => 'action', 'label' => 'Send Invite', 'action' => 'send_invite', 'config' => ['message' => null], 'channel' => 'linkedin'],
        [
            'key' => 106,
            'type' => 'condition',
            'label' => 'Invite accepted',
            'config' => ['timeout_days' => 1],
            'channel' => 'linkedin',
            'branches' => [
                'accepted' => [
                    ['key' => 107, 'time' => 'days', 'type' => 'delay', 'label' => 'Wait 1 day', 'value' => 1],
                    ['key' => 108, 'type' => 'action', 'label' => 'Send Message', 'action' => 'send_message', 'config' => ['message' => $messageAccepted], 'channel' => 'linkedin'],
                    ['key' => 113, 'time' => 'days', 'type' => 'delay', 'label' => 'Wait 3 days', 'value' => 3],
                    ['key' => 114, 'type' => 'action', 'label' => 'Send Message', 'action' => 'send_message', 'config' => ['message' => $messageFollowUp], 'channel' => 'linkedin'],
                ],
                'not_accepted' => [
                    ['key' => 109, 'time' => 'days', 'type' => 'delay', 'label' => 'Wait 1 day', 'value' => 1],
                    ['key' => 110, 'type' => 'action', 'label' => 'Visit Profile', 'action' => 'visit_profile', 'config' => [], 'channel' => 'linkedin'],
                    ['key' => 111, 'time' => 'days', 'type' => 'delay', 'label' => 'Wait 1 day', 'value' => 1],
                    ['key' => 112, 'type' => 'action', 'label' => 'Send Message', 'action' => 'send_message', 'config' => ['message' => $messageAccepted], 'channel' => 'linkedin'],
                ],
            ],
            'condition' => 'invite_accepted',
        ],
        ['key' => 99, 'type' => 'end', 'label' => 'End'],
    ];
}

function patchWaitNode(array $nodes, int $waitNodeKey, int $value, string $time): array
{
    foreach ($nodes as $i => $node) {
        if ((int) ($node['key'] ?? 0) === $waitNodeKey) {
            $nodes[$i]['value'] = $value;
            $nodes[$i]['time'] = $time;

            break;
        }
    }

    return $nodes;
}

$restoreWaitOnly = in_array('--restore-wait', $argv ?? [], true);

$campaign = App\Models\V2OutreachCampaign::findOrFail($campaignId);

if ($restoreWaitOnly) {
    $nodes = is_array($campaign->node_model) && count($campaign->node_model) > 0
        ? $campaign->node_model
        : originalNodeModel($messageAccepted, $messageFollowUp);

    $nodes = patchWaitNode($nodes, $waitNodeKey, 1, 'days');
    $campaign->update(['node_model' => $nodes]);
    echo "Restored wait node {$waitNodeKey} to 1 day.\n";

    exit(0);
}

$nodes = originalNodeModel($messageAccepted, $messageFollowUp);
$nodes = patchWaitNode($nodes, $waitNodeKey, 5, 'minutes');
$campaign->update(['node_model' => $nodes, 'status' => 'running']);

echo 'Restored node_model ('.count($nodes)." nodes).\n";
echo "Patched wait node {$waitNodeKey} to 5 minutes.\n";

$runId = App\Models\V2OutreachRun::query()
    ->where('outreach_campaign_id', $campaignId)
    ->where('status', 'running')
    ->latest('id')
    ->value('id');

$leads = App\Models\V2OutreachLead::query()
    ->where('outreach_campaign_id', $campaignId)
    ->where('status', 'error')
    ->with('progress')
    ->get();

$queued = 0;
foreach ($leads as $i => $lead) {
    $lead->update(['status' => 'pending']);

    if ($p = $lead->progress) {
        $completed = array_values(array_filter(
            $p->completed_keys ?? [],
            fn ($k) => (int) $k !== $likeNodeKey,
        ));

        $p->update([
            'run_status' => 0,
            'next_node_key' => $likeNodeKey,
            'completed_keys' => $completed,
            'next_run_at' => null,
        ]);
    }

    App\Jobs\V2\ProcessOutreachLeadJob::dispatch($campaignId, $lead->id, $runId)
        ->delay(now()->addSeconds($i * 5));

    $queued++;
}

echo "Queued {$queued} leads to retry Like Post (node {$likeNodeKey}).\n";
echo "Flow: Like Post → 5 min wait → Visit Profile → rest.\n";
echo "After recovery, run: php scripts/fix_campaign_5.php --restore-wait\n";
