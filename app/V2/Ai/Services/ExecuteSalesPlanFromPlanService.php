<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2Conversation;
use App\V2\Services\UnifiedInboxReplyService;

class ExecuteSalesPlanFromPlanService
{
    public function __construct(
        private readonly OutreachCampaignCommandService $campaigns,
    ) {}

    /**
     * @return array{message:string, results:list<array<string,mixed>>}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
        $orgId = (int) $approval->organization_id;

        if ($actions === []) {
            throw new \InvalidArgumentException('No actions in sales manager plan.');
        }

        $results = [];

        foreach ($actions as $action) {
            $type = (string) ($action['type'] ?? '');

            if ($type === 'pause_campaign') {
                $campaignId = (int) ($action['campaign_id'] ?? 0);
                $result = $this->campaigns->pause($user, $orgId, $campaignId);
                $results[] = [
                    'type' => 'pause_campaign',
                    'campaign_id' => $campaignId,
                    'ok' => $result['ok'],
                    'message' => $result['message'],
                ];

                continue;
            }

            if ($type === 'draft_reply') {
                $results[] = $this->sendDraftReply($user, $action);
            }
        }

        $approval->update([
            'result' => [
                'status' => 'executed',
                'results' => $results,
            ],
            'status' => 'executed',
        ]);

        $paused = count(array_filter($results, fn ($r) => ($r['type'] ?? '') === 'pause_campaign' && ($r['ok'] ?? false)));
        $sent = count(array_filter($results, fn ($r) => ($r['type'] ?? '') === 'draft_reply' && ($r['ok'] ?? false)));

        return [
            'message' => "Executed sales plan: paused {$paused} campaign(s), sent {$sent} follow-up(s).",
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function sendDraftReply(User $user, array $action): array
    {
        $conversationId = (int) ($action['conversation_id'] ?? 0);
        $draft = trim((string) ($action['draft_text'] ?? ''));

        if ($conversationId <= 0 || $draft === '') {
            return [
                'type' => 'draft_reply',
                'conversation_id' => $conversationId,
                'ok' => false,
                'message' => 'Missing conversation or draft.',
            ];
        }

        try {
            $conversation = V2Conversation::query()
                ->where('user_id', $user->id)
                ->whereKey($conversationId)
                ->firstOrFail();

            $sent = app(UnifiedInboxReplyService::class)->sendApprovedReply($user, $conversation, $draft);

            return [
                'type' => 'draft_reply',
                'conversation_id' => $conversationId,
                'ok' => true,
                'message' => 'Reply sent to '.($action['prospect_name'] ?? 'prospect').'.',
                'v2_message_id' => $sent->id,
                'inbox_url' => (string) ($action['inbox_url'] ?? url('/inbox')),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'type' => 'draft_reply',
                'conversation_id' => $conversationId,
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
