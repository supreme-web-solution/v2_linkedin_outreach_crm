<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\V2\Ai\Enums\AiAutonomyLevel;
use Illuminate\Support\Carbon;

/**
 * Posts periodic owner-attention digests into the Command Center chat thread.
 */
class OwnerAttentionDigestService
{
    public function __construct(
        private readonly CommandCenterAwarenessService $awareness,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly CommandCenterPushService $push,
    ) {}

    public function maybePost(User $user, int $organizationId, string $trigger = 'scheduled'): bool
    {
        if (! (bool) config('socifusion_ai.attention_digest.enabled', true)) {
            return false;
        }

        if ($organizationId <= 0) {
            return false;
        }

        $settings = $this->settingsService->for($user, $organizationId);
        if ($this->settingsService->isBlocked($settings)) {
            return false;
        }

        $autonomy = $this->settingsService->autonomy($settings);
        if ($autonomy->value >= AiAutonomyLevel::Autopilot->value) {
            $this->maybeAutoHandleHotThreads($user, $organizationId);
        }

        $content = $this->buildDigestMessage($user, $organizationId, $autonomy);
        if ($content === null) {
            return false;
        }

        $conversation = $this->commandCenter->conversation($user, $organizationId);
        $hash = hash('sha256', $content);

        if (! $this->shouldPost($conversation, $hash, $trigger)) {
            return false;
        }

        $approvalId = $this->latestPendingDraftReplyId($user, $organizationId);

        $this->push->postAssistant($user, $organizationId, $content, [
            'source' => 'attention_digest',
            'trigger' => $trigger,
            'digest_hash' => $hash,
            'tool' => $approvalId ? 'draft_reply' : null,
            'payload' => $approvalId ? ['type' => 'draft_reply'] : null,
        ], $approvalId);

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $meta['attention_digest'] = [
            'hash' => $hash,
            'posted_at' => now()->toIso8601String(),
            'trigger' => $trigger,
        ];
        $conversation->forceFill(['meta' => $meta])->save();

        return true;
    }

    public function shouldPost(AiConversation $conversation, string $contentHash, string $trigger): bool
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $last = is_array($meta['attention_digest'] ?? null) ? $meta['attention_digest'] : [];
        $lastHash = (string) ($last['hash'] ?? '');
        $lastAt = trim((string) ($last['posted_at'] ?? ''));

        if ($lastHash !== $contentHash) {
            return true;
        }

        $minMinutes = (int) config('socifusion_ai.attention_digest.interval_minutes', 30);
        if ($trigger === 'command_center_open') {
            $minMinutes = (int) config('socifusion_ai.attention_digest.open_interval_minutes', 15);
        }

        if ($lastAt === '') {
            return true;
        }

        try {
            return Carbon::parse($lastAt)->lte(now()->subMinutes(max(5, $minMinutes)));
        } catch (\Throwable) {
            return true;
        }
    }

    public function buildDigestMessage(User $user, int $organizationId, ?AiAutonomyLevel $autonomy = null): ?string
    {
        $snap = $this->awareness->snapshot($user, $organizationId);
        $brief = is_array($snap['inbox_brief'] ?? null) ? $snap['inbox_brief'] : [];
        $needYou = (int) ($brief['need_you'] ?? 0);
        $hot = (int) ($brief['hot'] ?? 0);
        $pending = $snap['pending_approvals'] ?? [];
        $workflows = $snap['workflows'] ?? [];
        $nurture = is_array($snap['nurture'] ?? null) ? $snap['nurture'] : [];
        $nurtureDue = (int) ($nurture['overdue'] ?? 0) + (int) ($nurture['due_this_week'] ?? 0);

        if ($needYou <= 0 && $pending === [] && $workflows === [] && $nurtureDue <= 0) {
            return null;
        }

        $autonomy ??= $this->settingsService->autonomy($this->settingsService->for($user, $organizationId));
        $employee = $this->settingsService->for($user, $organizationId)->employee_name ?: 'Soci';
        $lines = ["📋 **{$employee} attention digest**"];

        if ($needYou > 0) {
            $lines[] = "**{$needYou} need you**".($hot > 0 ? " ({$hot} hot)" : '').' — inbox threads awaiting your reply (includes ones you already opened).';
        }

        $hotItems = [];
        $otherItems = [];
        foreach ($snap['awaiting_reply'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $line = $this->formatAttentionLine($row);
            if (($row['priority'] ?? '') === 'hot') {
                $hotItems[] = $line;
            } else {
                $otherItems[] = $line;
            }
        }

        if ($hotItems !== []) {
            $lines[] = '**Hot**';
            foreach (array_slice($hotItems, 0, 5) as $line) {
                $lines[] = $line;
            }
        }

        if ($otherItems !== []) {
            $lines[] = '**Also waiting**';
            foreach (array_slice($otherItems, 0, 5) as $line) {
                $lines[] = $line;
            }
        }

        if ($pending !== []) {
            $lines[] = '**Pending Launch**';
            foreach ($pending as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lines[] = '• Launch #'.($row['approval_id'] ?? '?').' — '.($row['type'] ?? 'plan')
                    .((($row['goal'] ?? '') !== '') ? ': '.$row['goal'] : '');
            }
        }

        foreach ($workflows as $run) {
            if (! is_array($run)) {
                continue;
            }
            $lines[] = '⏳ Workflow #'.($run['id'] ?? '?').' is '.($run['status'] ?? 'running')
                .((($run['outcome'] ?? '') !== '') ? ' ('.$run['outcome'].')' : '').'.';
        }

        if ($nurtureDue > 0) {
            $lines[] = "🌱 **Nurture:** {$nurtureDue} follow-up(s) due — ask me to show the nurture queue.";
        }

        if ($autonomy->value >= AiAutonomyLevel::Autopilot->value) {
            $lines[] = 'Autopilot is on — Soci drafts and sends inbox replies automatically when possible. Tap **Send** below if a reply is waiting for approval.';
        } else {
            $lines[] = 'Reply here: **LAUNCH #** to approve, or tell me who to draft for (email/name works even if you read the thread).';
        }

        return implode("\n\n", $lines);
    }

    private function maybeAutoHandleHotThreads(User $user, int $organizationId): void
    {
        if (! (bool) config('socifusion_ai.proactive_inbound_reply', true)) {
            return;
        }

        $snap = $this->awareness->snapshot($user, $organizationId);
        $handling = app(InboxSociHandlingService::class);

        foreach ($snap['awaiting_reply'] ?? [] as $row) {
            if (! is_array($row) || ($row['priority'] ?? '') !== 'hot') {
                continue;
            }

            $convId = (int) ($row['conversation_id'] ?? 0);
            if ($convId <= 0) {
                continue;
            }

            $v2Conversation = V2Conversation::query()
                ->where('user_id', $user->id)
                ->whereKey($convId)
                ->first();

            if (! $v2Conversation) {
                continue;
            }

            $latestInbound = V2Message::query()
                ->where('conversation_id', $v2Conversation->id)
                ->where('direction', 'inbound')
                ->orderByDesc('id')
                ->first();

            if (! $latestInbound || $handling->isAlreadyHandled($v2Conversation, (int) $latestInbound->id)) {
                continue;
            }

            app(ProactiveInboundReplyService::class)->handle(
                $v2Conversation->id,
                $user->id,
                (int) $latestInbound->id,
            );

            return;
        }
    }

    private function latestPendingDraftReplyId(User $user, int $organizationId): ?int
    {
        $approval = AiActionApproval::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->where('tool', 'draft_reply')
            ->orderByDesc('id')
            ->first();

        return $approval ? (int) $approval->id : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function formatAttentionLine(array $row): string
    {
        $name = (string) ($row['prospect_name'] ?? 'Prospect');
        $email = trim((string) ($row['prospect_email'] ?? ''));
        $channel = (string) ($row['channel_label'] ?? $row['channel'] ?? 'inbox');
        $preview = (string) ($row['preview'] ?? '');
        $inboxUrl = (string) ($row['inbox_url'] ?? '');
        $campaign = trim((string) ($row['campaign_name'] ?? ''));
        $readNote = ($row['is_unread'] ?? true) ? '' : ' *(read — still needs reply)*';

        $identity = $email !== '' ? "**{$name}** <{$email}>" : "**{$name}**";
        $tail = $campaign !== '' ? " · {$campaign}" : '';

        $line = "• {$channel}: {$identity}{$readNote}{$tail}";
        if ($preview !== '') {
            $line .= "\n  > ".str_replace("\n", "\n  > ", $preview);
        }
        if ($inboxUrl !== '') {
            $line .= "\n  [Open inbox]({$inboxUrl})";
        }

        return $line;
    }
}
