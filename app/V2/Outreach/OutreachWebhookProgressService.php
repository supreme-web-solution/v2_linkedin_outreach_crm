<?php

namespace App\V2\Outreach;

use App\Jobs\V2\ProcessOutreachLeadJob;
use App\Models\V2Conversation;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use Illuminate\Support\Arr;

class OutreachWebhookProgressService
{
    public function __construct(
        private readonly OutreachConditionEvaluator $evaluator,
        private readonly OutreachSequenceResolver $resolver,
        private readonly OutreachActivityLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleInvitationAccepted(int $userId, array $payload): void
    {
        $profileId = $this->extractProfileId($payload);
        if ($profileId === '') {
            return;
        }

        $leads = V2OutreachLead::query()
            ->whereHas('campaign', fn ($q) => $q
                ->where('user_id', $userId)
                ->whereIn('status', ['active', 'running']))
            ->whereNotIn('status', ['done', 'skipped', 'replied'])
            ->where(function ($q) use ($profileId) {
                $q->where('provider_profile_id', $profileId)
                    ->orWhere('profile_url', 'like', '%/'.$profileId.'%');
            })
            ->get();

        foreach ($leads as $lead) {
            $this->markLinkedInInviteAccepted($lead);
        }
    }

    public function recordInboundReply(
        V2OutreachLead $lead,
        V2OutreachCampaign $campaign,
        string $channel,
        string $inboundBody,
    ): void {
        $this->updateChannelState($lead, $campaign, $channel, [
            'replied' => true,
            'replied_at' => now()->toIso8601String(),
            'last_inbound_preview' => mb_substr(trim($inboundBody), 0, 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleEmailTrackingEvent(int $userId, string $eventType, array $payload): void
    {
        $email = $this->extractEmailFromPayload($payload);
        if ($email === '') {
            return;
        }

        $updates = match ($eventType) {
            'email.opened', 'mail.opened', 'email.tracked.open' => ['opened' => true, 'opened_at' => now()->toIso8601String()],
            'email.bounced', 'mail.bounced', 'email.failed' => ['bounced' => true, 'bounced_at' => now()->toIso8601String()],
            'email.replied', 'mail.received' => ['replied' => true, 'replied_at' => now()->toIso8601String()],
            default => [],
        };

        if ($updates === []) {
            return;
        }

        $leads = $this->findLeadsByEmail($userId, $email);
        foreach ($leads as $lead) {
            $campaign = $lead->campaign;
            if (! $campaign) {
                continue;
            }

            $this->updateChannelState($lead, $campaign, 'email', $updates);
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function updateChannelState(
        V2OutreachLead $lead,
        V2OutreachCampaign $campaign,
        string $channel,
        array $updates,
    ): void {
        if (in_array($lead->status, ['done', 'skipped'], true)) {
            return;
        }

        if (! in_array($campaign->status, ['active', 'running'], true)) {
            return;
        }

        $progress = V2OutreachLeadProgress::query()->firstOrCreate(
            ['outreach_campaign_id' => $campaign->id, 'outreach_lead_id' => $lead->id],
            ['current_node_key' => 0, 'next_node_key' => 1, 'run_status' => 0, 'channel_state' => []]
        );

        $channelState = is_array($progress->channel_state) ? $progress->channel_state : [];
        $channelState[$channel] = array_merge(
            is_array($channelState[$channel] ?? null) ? $channelState[$channel] : [],
            $updates
        );

        $progress->forceFill([
            'channel_state' => $channelState,
            'next_run_at' => null,
        ])->save();

        if ($channel === 'linkedin' && ! empty($updates['invite_accepted'])) {
            $progress->forceFill(['acceptance_status' => true])->save();
        }

        $this->tryAdvanceWaitingCondition($lead->fresh(), $progress->fresh(), $campaign);
    }

    /**
     * @return \Illuminate\Support\Collection<int, V2OutreachLead>
     */
    private function findLeadsByEmail(int $userId, string $email): \Illuminate\Support\Collection
    {
        $normalized = strtolower(trim($email));

        return V2OutreachLead::query()
            ->whereHas('campaign', fn ($q) => $q
                ->where('user_id', $userId)
                ->whereIn('status', ['active', 'running']))
            ->whereNotIn('status', ['done', 'skipped', 'replied'])
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractEmailFromPayload(array $payload): string
    {
        $inner = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        foreach (['from', 'to', 'email', 'recipient', 'sender'] as $key) {
            $value = Arr::get($inner, $key);
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return strtolower(trim($value));
            }
            if (is_array($value)) {
                $nested = trim((string) ($value['email'] ?? $value['address'] ?? ''));
                if ($nested !== '' && filter_var($nested, FILTER_VALIDATE_EMAIL)) {
                    return strtolower($nested);
                }
            }
        }

        foreach (['from_email', 'to_email', 'recipient_email', 'sender_email'] as $key) {
            $value = trim((string) (Arr::get($inner, $key) ?? Arr::get($payload, $key) ?? ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return strtolower($value);
            }
        }

        return '';
    }

    public function markLinkedInInviteAccepted(V2OutreachLead $lead): void
    {
        $campaign = $lead->campaign;
        if (! $campaign || ! in_array($campaign->status, ['active', 'running'], true)) {
            return;
        }

        $this->updateChannelState($lead, $campaign, 'linkedin', [
            'invite_accepted' => true,
            'invite_accepted_at' => now()->toIso8601String(),
        ]);

        $this->logger->log(
            $campaign->id,
            $lead->id,
            null,
            null,
            'condition_met',
            sprintf('%s accepted your LinkedIn invite.', $lead->full_name ?? 'Lead'),
            ['condition' => 'invite_accepted'],
        );
    }

    public function resolveLeadFromConversation(V2Conversation $conversation): ?V2OutreachLead
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $leadId = (int) (Arr::get($meta, 'outreach_lead_id') ?? 0);

        if ($leadId > 0) {
            return V2OutreachLead::query()->find($leadId);
        }

        return null;
    }

    private function tryAdvanceWaitingCondition(
        V2OutreachLead $lead,
        V2OutreachLeadProgress $progress,
        V2OutreachCampaign $campaign,
    ): void {
        if (in_array($lead->status, ['done', 'skipped', 'replied'], true)) {
            return;
        }

        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $nodeKey = (int) ($progress->next_node_key ?: $progress->current_node_key);
        if ($nodeKey <= 0) {
            return;
        }

        $node = $this->resolver->findNodeByKey($nodes, $nodeKey);
        if (! $node || (string) ($node['type'] ?? '') !== 'condition') {
            return;
        }

        $result = $this->evaluator->evaluate($progress, $node);
        if ($result === null) {
            return;
        }

        ProcessOutreachLeadJob::dispatch($campaign->id, $lead->id)->delay(now()->addSeconds(2));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractProfileId(array $payload): string
    {
        $inner = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        foreach (['profile_id', 'invitee_id', 'provider_id', 'public_identifier', 'linkedin_id'] as $key) {
            $value = trim((string) (Arr::get($inner, $key) ?? Arr::get($payload, $key) ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $profile = Arr::get($inner, 'profile');
        if (is_array($profile)) {
            foreach (['provider_id', 'public_identifier', 'id'] as $key) {
                $value = trim((string) ($profile[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}
