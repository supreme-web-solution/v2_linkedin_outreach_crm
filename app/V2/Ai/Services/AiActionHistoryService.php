<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionLog;
use App\Models\User;
use Illuminate\Support\Arr;

class AiActionHistoryService
{
    /** @var list<string> */
    private const REVERSIBLE_TOOLS = [
        'pause_outreach_campaign',
        'activate_outreach_campaign',
        'move_lead_to_nurture',
        'prepare_linkedin_post',
    ];

    public function __construct(
        private readonly OutreachCampaignCommandService $campaigns,
        private readonly LeadNurtureCommandCenterService $nurture,
        private readonly ContentPostCommandCenterService $contentPosts,
    ) {}

    /**
     * Latest execute actions for the Command Center sidebar (keep this small).
     *
     * @return list<array<string, mixed>>
     */
    public function recent(User $user, int $organizationId, int $limit = 5): array
    {
        $limit = max(1, min(10, $limit));

        return $this->baseQuery($user, $organizationId)
            ->limit($limit)
            ->get()
            ->map(fn (AiActionLog $log) => $this->serialize($log))
            ->all();
    }

    /**
     * Full history for the dedicated Activity page.
     *
     * @return array{
     *   data: list<array<string, mixed>>,
     *   current_page: int,
     *   last_page: int,
     *   per_page: int,
     *   total: int,
     *   from: int|null,
     *   to: int|null,
     *   prev_page_url: string|null,
     *   next_page_url: string|null,
     *   links: list<array{url:?string,label:string,active:bool}>
     * }
     */
    public function paginate(User $user, int $organizationId, int $perPage = 20): array
    {
        $perPage = max(1, min(50, $perPage));

        $paginator = $this->baseQuery($user, $organizationId)
            ->paginate($perPage)
            ->withQueryString();

        $items = collect($paginator->items())
            ->map(fn (AiActionLog $log) => $this->serialize($log))
            ->values()
            ->all();

        $links = collect($paginator->toArray()['links'] ?? [])
            ->map(fn ($link) => [
                'url' => $link['url'] ?? null,
                'label' => (string) ($link['label'] ?? ''),
                'active' => (bool) ($link['active'] ?? false),
            ])
            ->values()
            ->all();

        return [
            'data' => $items,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'next_page_url' => $paginator->nextPageUrl(),
            'links' => $links,
        ];
    }

    private function baseQuery(User $user, int $organizationId)
    {
        return AiActionLog::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            // Execute + prepare: prepare tools (e.g. LinkedIn posts) create durable work users expect to see.
            ->whereIn('permission', ['execute', 'prepare'])
            ->whereIn('status', ['success', 'error', 'denied'])
            ->orderByDesc('id');
    }

    /**
     * @return array{ok:bool, message:string, item?:array<string, mixed>}
     */
    public function undo(User $user, int $organizationId, int $logId): array
    {
        $log = AiActionLog::query()
            ->whereKey($logId)
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->first();

        if ($log === null) {
            return ['ok' => false, 'message' => 'Action not found.'];
        }

        if ($log->undone_at !== null) {
            return ['ok' => false, 'message' => 'This action was already undone.'];
        }

        if ($log->status !== 'success' || ! in_array($log->permission, ['execute', 'prepare'], true)) {
            return ['ok' => false, 'message' => 'Only successful actions can be undone.'];
        }

        if (! in_array($log->tool, self::REVERSIBLE_TOOLS, true)) {
            return ['ok' => false, 'message' => $this->irreversibleReason($log->tool)];
        }

        try {
            $result = match ($log->tool) {
                'pause_outreach_campaign' => $this->undoPause($user, $organizationId, $log),
                'activate_outreach_campaign' => $this->undoActivate($user, $organizationId, $log),
                'move_lead_to_nurture' => $this->undoNurture($user, $log),
                'prepare_linkedin_post' => $this->undoLinkedInPost($user, $organizationId, $log),
                default => ['ok' => false, 'message' => 'Undo is not available for this action.'],
            };
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => (string) ($result['message'] ?? 'Could not undo this action.'),
            ];
        }

        $log->forceFill([
            'undone_at' => now(),
            'undo_result' => $result,
        ])->save();

        return [
            'ok' => true,
            'message' => (string) ($result['message'] ?? 'Undone.'),
            'item' => $this->serialize($log->fresh()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AiActionLog $log): array
    {
        $canUndo = $log->status === 'success'
            && in_array($log->permission, ['execute', 'prepare'], true)
            && $log->undone_at === null
            && in_array($log->tool, self::REVERSIBLE_TOOLS, true);

        return [
            'id' => $log->id,
            'tool' => $log->tool,
            'label' => $this->labelFor($log->tool),
            'status' => $log->status,
            'summary' => $this->summaryFor($log),
            'can_undo' => $canUndo,
            'undo_hint' => $canUndo
                ? $this->undoHint($log->tool)
                : ($log->undone_at ? 'Already undone' : $this->irreversibleReason($log->tool)),
            'undone_at' => $log->undone_at?->toIso8601String(),
            'created_at' => $log->created_at?->toIso8601String(),
            'error' => $log->error,
        ];
    }

    private function labelFor(string $tool): string
    {
        return match ($tool) {
            'pause_outreach_campaign' => 'Paused campaign',
            'activate_outreach_campaign' => 'Activated campaign',
            'move_lead_to_nurture' => 'Moved lead to nurture',
            'send_inbox_reply' => 'Sent inbox reply',
            'prepare_linkedin_post' => 'LinkedIn post',
            'reschedule_content_posts' => 'Rescheduled content',
            'draft_campaign_plan' => 'Drafted campaign plan',
            'propose_strategy' => 'Proposed strategy',
            'build_icp' => 'Built ICP',
            'import_leads_csv' => 'Imported leads CSV',
            default => str_replace('_', ' ', $tool),
        };
    }

    private function undoHint(string $tool): string
    {
        return match ($tool) {
            'pause_outreach_campaign' => 'Resume campaign(s)',
            'activate_outreach_campaign' => 'Pause campaign again',
            'move_lead_to_nurture' => 'Resume lead from nurture',
            'prepare_linkedin_post' => 'Cancel scheduled post',
            default => 'Undo',
        };
    }

    private function irreversibleReason(string $tool): string
    {
        return match ($tool) {
            'send_inbox_reply' => 'Sent messages cannot be unsent',
            'prepare_linkedin_post' => 'Already published or missing post',
            default => 'This action cannot be undone',
        };
    }

    private function summaryFor(AiActionLog $log): string
    {
        if (is_string($log->error) && $log->error !== '') {
            return $log->error;
        }

        $output = is_array($log->output) ? $log->output : [];
        $message = Arr::get($output, 'message');
        if (is_string($message) && $message !== '') {
            return $message;
        }

        if ($log->tool === 'prepare_linkedin_post') {
            $topic = Arr::get($output, 'plan.topic');
            $when = Arr::get($output, 'plan.scheduled_label');
            $parts = [];
            if (is_string($topic) && $topic !== '') {
                $parts[] = $topic;
            }
            if (is_string($when) && $when !== '') {
                $parts[] = 'Scheduled '.$when;
            } elseif (Arr::get($output, 'auto_scheduled')) {
                $parts[] = 'Scheduled automatically';
            } elseif (Arr::get($output, 'approval_id')) {
                $parts[] = 'Awaiting Review & Launch';
            }
            if ($parts !== []) {
                return implode(' · ', $parts);
            }
        }

        if ($log->undone_at) {
            return 'Undone '.$log->undone_at->diffForHumans();
        }

        return $this->labelFor($log->tool);
    }

    /**
     * @return array{ok:bool, message:string, resumed?:list<int>}
     */
    private function undoPause(User $user, int $organizationId, AiActionLog $log): array
    {
        $output = is_array($log->output) ? $log->output : [];
        $paused = Arr::get($output, 'paused', []);
        $ids = is_array($paused) ? $paused : [];
        $previous = Arr::get($output, 'previous_statuses', []);
        if (! is_array($previous)) {
            $previous = [];
        }

        return $this->campaigns->resumePaused($user, $organizationId, $ids, $previous);
    }

    /**
     * @return array{ok:bool, message:string, paused?:list<int>}
     */
    private function undoActivate(User $user, int $organizationId, AiActionLog $log): array
    {
        $output = is_array($log->output) ? $log->output : [];
        $campaignId = (int) (Arr::get($output, 'campaign_id')
            ?? Arr::get($log->input, 'args.campaign_id')
            ?? 0);

        if ($campaignId <= 0) {
            return ['ok' => false, 'message' => 'Missing campaign id for undo.', 'paused' => []];
        }

        return $this->campaigns->pause($user, $organizationId, $campaignId);
    }

    /**
     * @return array{ok:bool, message:string}
     */
    private function undoNurture(User $user, AiActionLog $log): array
    {
        $output = is_array($log->output) ? $log->output : [];
        $leadId = (int) (Arr::get($output, 'outreach_lead_id')
            ?? Arr::get($log->input, 'args.outreach_lead_id')
            ?? 0);

        if ($leadId <= 0) {
            return ['ok' => false, 'message' => 'Missing lead id for undo.'];
        }

        $result = $this->nurture->resumeFromNurture($user, $leadId);

        return [
            'ok' => true,
            'message' => (string) ($result['message'] ?? 'Lead resumed from nurture.'),
        ];
    }

    /**
     * @return array{ok:bool, message:string, post_id?:int}
     */
    private function undoLinkedInPost(User $user, int $organizationId, AiActionLog $log): array
    {
        $output = is_array($log->output) ? $log->output : [];
        $postId = (int) (Arr::get($output, 'plan.post_id')
            ?? Arr::get($output, 'post_id')
            ?? 0);

        if ($postId <= 0) {
            return ['ok' => false, 'message' => 'Missing content post id for undo.'];
        }

        return $this->contentPosts->cancelFromAlex($user, $organizationId, $postId);
    }
}
