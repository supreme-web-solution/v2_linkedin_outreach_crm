<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\V2\Outreach\OutreachChannelGuard;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Str;

class PlanFunnelService
{
    public function __construct(
        private readonly AiChannelPolicyService $channelPolicy,
        private readonly OutreachChannelGuard $guard,
    ) {}

    /**
     * Visual funnel for strategy / campaign Review & Launch cards.
     *
     * @return list<array{step:string, label:string, detail:string, status:string}>
     */
    public function forPlan(array $plan, ?User $user = null): array
    {
        $type = (string) ($plan['type'] ?? '');
        if (! in_array($type, ['strategy', 'campaign'], true)) {
            return [];
        }

        $audience = trim((string) ($plan['icp']['summary'] ?? $plan['icp_notes'] ?? $plan['audience'] ?? ''));
        $hasList = trim((string) ($plan['list_hash'] ?? '')) !== '';
        $listLabel = trim((string) ($plan['list_name'] ?? $plan['audience_note'] ?? ''));
        $targetCount = (int) ($plan['target_count'] ?? 0);
        $channels = trim((string) ($plan['preferred_channels'] ?? $plan['channels'] ?? $this->channelPolicy->defaultChannelsLabel()));
        $source = trim((string) ($plan['source'] ?? 'LinkedIn search + saved lists'));
        $goal = trim((string) ($plan['goal'] ?? ''));
        $sequence = is_array($plan['sequence'] ?? null) ? $plan['sequence'] : [];
        $sequenceDetail = $sequence !== []
            ? Str::limit(implode(' → ', array_map('strval', array_slice($sequence, 0, 4))), 120, '…')
            : 'Connection → wait → message → follow-up';

        $audienceStatus = $audience !== '' ? 'ready' : (($plan['audience_status'] ?? '') === 'missing' ? 'blocked' : 'pending');
        $contactsDetail = $hasList
            ? ($listLabel !== '' ? $listLabel : (string) $plan['list_hash'])
            : ($targetCount > 0 ? "~{$targetCount} prospects" : 'Attach a list before Launch');
        $contactsStatus = $hasList ? 'ready' : 'blocked';

        $missingIntegrations = $user ? $this->missingIntegrationLabels($plan, $user) : [];
        $channelsStatus = $missingIntegrations === [] ? 'ready' : 'blocked';
        $channelsDetail = $missingIntegrations === []
            ? $channels
            : $channels.' · connect '.implode(', ', $missingIntegrations);

        return [
            [
                'step' => 'audience',
                'label' => 'Audience',
                'detail' => $audience !== '' ? Str::limit($audience, 80, '…') : 'Confirm ICP / audience',
                'status' => $audienceStatus,
            ],
            [
                'step' => 'source',
                'label' => 'Source',
                'detail' => Str::limit($source, 80, '…'),
                'status' => 'ready',
            ],
            [
                'step' => 'contacts',
                'label' => 'Contacts',
                'detail' => $contactsDetail,
                'status' => $contactsStatus,
            ],
            [
                'step' => 'channels',
                'label' => 'Channels',
                'detail' => Str::limit($channelsDetail, 100, '…'),
                'status' => $channelsStatus,
            ],
            [
                'step' => 'sequence',
                'label' => 'Sequence',
                'detail' => $sequenceDetail,
                'status' => 'ready',
            ],
            [
                'step' => 'goal',
                'label' => 'Goal',
                'detail' => Str::limit($goal, 80, '…'),
                'status' => $goal !== '' ? 'ready' : 'pending',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function missingIntegrationLabels(array $plan, User $user): array
    {
        $mentioned = $this->channelPolicy->mentionedInPlan($plan);
        if ($mentioned === []) {
            $mentioned = $this->channelPolicy->primaryKeys();
        }

        $missing = [];
        foreach ($mentioned as $channelKey) {
            if (! OutreachChannelRegistry::isEnabled($channelKey)) {
                continue;
            }
            if (! $this->guard->isChannelConnected($user->id, $channelKey)) {
                $missing[] = OutreachChannelRegistry::channelLabel($channelKey);
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function attachToPlan(array $plan, ?User $user = null): array
    {
        $funnel = $this->forPlan($plan, $user);
        if ($funnel !== []) {
            $plan['funnel'] = $funnel;
        }

        return $plan;
    }
}
