<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Support\WhatsAppNotificationFormatter;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
        private readonly InboxSociHandlingService $handling,
    ) {}

    public function maybePost(User $user, int $organizationId, string $trigger = 'scheduled'): bool
    {
        if (! (bool) config('socifusion_ai.attention_digest.enabled', true)) {
            return false;
        }

        if ($organizationId <= 0) {
            return false;
        }

        $lock = Cache::lock('attention_digest:'.$user->id.':'.$organizationId, 15);
        if (! $lock->get()) {
            return false;
        }

        try {
            return $this->postIfNeeded($user, $organizationId, $trigger);
        } finally {
            $lock->release();
        }
    }

    private function postIfNeeded(User $user, int $organizationId, string $trigger): bool
    {
        $settings = $this->settingsService->for($user, $organizationId);
        if ($this->settingsService->isBlocked($settings)) {
            return false;
        }

        $autonomy = $this->settingsService->autonomy($settings);
        if ($autonomy->value >= AiAutonomyLevel::Autopilot->value) {
            $this->maybeAutoHandleHotThreads($user, $organizationId);
        }

        $snap = $this->awareness->snapshot($user, $organizationId);
        $content = $this->buildDigestMessage($user, $organizationId, $autonomy, $snap);
        if ($content === null) {
            return false;
        }

        $conversation = $this->commandCenter->conversation($user, $organizationId);
        $hash = hash('sha256', $content);

        if (! $this->shouldPost($conversation, $hash, $trigger)) {
            return false;
        }

        $approvalId = $this->latestPendingDraftReplyId($user, $organizationId);
        $whatsappBody = $this->buildWhatsAppDigest($snap, $autonomy, $approvalId, $user);

        $this->push->postAssistant($user, $organizationId, $content, [
            'source' => 'attention_digest',
            'trigger' => $trigger,
            'digest_hash' => $hash,
            'tool' => $approvalId ? 'draft_reply' : null,
            'payload' => $approvalId ? ['type' => 'draft_reply'] : null,
            'whatsapp_body' => $whatsappBody,
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

    /**
     * @param  array<string, mixed>  $snap
     */
    public function buildDigestMessage(
        User $user,
        int $organizationId,
        ?AiAutonomyLevel $autonomy = null,
        ?array $snap = null,
    ): ?string {
        $snap ??= $this->awareness->snapshot($user, $organizationId);
        $brief = is_array($snap['inbox_brief'] ?? null) ? $snap['inbox_brief'] : [];
        $needYou = (int) ($brief['need_you'] ?? 0);
        $hot = (int) ($brief['hot'] ?? 0);
        $awaiting = (int) ($brief['awaiting_reply'] ?? 0);
        $pending = $snap['pending_approvals'] ?? [];
        $workflows = $snap['workflows'] ?? [];
        $nurture = is_array($snap['nurture'] ?? null) ? $snap['nurture'] : [];
        $nurtureDue = (int) ($nurture['overdue'] ?? 0) + (int) ($nurture['due_this_week'] ?? 0);
        $recentHandled = $this->handling->recentHandledBriefs($user, 6, 2);

        if ($needYou <= 0 && $pending === [] && $workflows === [] && $nurtureDue <= 0 && $recentHandled === []) {
            return null;
        }

        $autonomy ??= $this->settingsService->autonomy($this->settingsService->for($user, $organizationId));
        $employee = $this->settingsService->for($user, $organizationId)->employee_name ?: 'Soci';
        $maxItems = (int) config('socifusion_ai.attention_digest.max_items', 2);

        $lines = ["📋 **{$employee} attention digest**"];

        if ($recentHandled !== []) {
            $lines[] = '**Soci handled recently**';
            foreach ($recentHandled as $row) {
                $channel = OutreachChannelRegistry::channelLabel((string) ($row['channel'] ?? 'inbox'));
                $mode = (string) ($row['mode'] ?? '');
                $action = str_contains($mode, 'auto_sent') ? 'reply sent' : 'draft ready';
                $lines[] = '• '.$channel.': **'.($row['name'] ?? 'Prospect').'** — '.$action;
            }
        }

        if ($awaiting > 0) {
            $lines[] = "**{$awaiting} inbox thread(s) need you**".($hot > 0 ? " ({$hot} hot)" : '').'.';
        }

        $allItems = is_array($snap['awaiting_reply'] ?? null) ? $snap['awaiting_reply'] : [];
        $shown = array_slice($allItems, 0, max(1, $maxItems));
        $remaining = max(0, count($allItems) - count($shown));

        foreach ($shown as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lines[] = $this->formatAttentionLine($row, compact: true);
        }

        if ($remaining > 0) {
            $lines[] = "_+ {$remaining} other thread(s) waiting — ask Soci for the full attention queue._";
        }

        if ($pending !== []) {
            $first = is_array($pending[0] ?? null) ? $pending[0] : null;
            if ($first) {
                $lines[] = 'Pending: Launch #'.($first['approval_id'] ?? '?')
                    .((($first['goal'] ?? '') !== '') ? ' — '.$first['goal'] : '');
            }
            if (count($pending) > 1) {
                $lines[] = '_+ '.(count($pending) - 1).' other pending Launch item(s)._';
            }
        }

        if ($nurtureDue > 0) {
            $lines[] = "🌱 Nurture: {$nurtureDue} due — ask for the nurture queue.";
        }

        $pendingReplyApproval = $this->latestPendingDraftReplyId($user, $organizationId);
        if ($autonomy->value >= AiAutonomyLevel::Autopilot->value) {
            $lines[] = $pendingReplyApproval
                ? 'Autopilot on — tap **Send** below if a reply still needs approval.'
                : 'Autopilot on — Soci handles hot replies; you oversee.';
        } else {
            $lines[] = 'Say who to draft for, or **LAUNCH #** to approve.';
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    public function buildWhatsAppDigest(
        array $snap,
        AiAutonomyLevel $autonomy,
        ?int $approvalId,
        User $user,
    ): string {
        $brief = is_array($snap['inbox_brief'] ?? null) ? $snap['inbox_brief'] : [];
        $awaiting = (int) ($brief['awaiting_reply'] ?? 0);
        $hot = (int) ($brief['hot'] ?? 0);
        $maxItems = (int) config('socifusion_ai.attention_digest.whatsapp_max_items', 2);

        $lines = ['Soci digest'];
        $recentHandled = $this->handling->recentHandledBriefs($user, 6, 2);
        foreach ($recentHandled as $row) {
            $channel = OutreachChannelRegistry::channelLabel((string) ($row['channel'] ?? 'inbox'));
            $lines[] = 'Handled: '.$channel.' '.($row['name'] ?? 'Prospect');
        }

        if ($awaiting > 0) {
            $lines[] = "{$awaiting} need you".($hot > 0 ? " ({$hot} hot)" : '').':';
        }

        $allItems = is_array($snap['awaiting_reply'] ?? null) ? $snap['awaiting_reply'] : [];
        $shown = array_slice($allItems, 0, max(1, $maxItems));
        foreach ($shown as $row) {
            if (is_array($row)) {
                $lines[] = WhatsAppNotificationFormatter::compactThreadLine($row);
            }
        }

        $remaining = max(0, count($allItems) - count($shown));
        if ($remaining > 0) {
            $lines[] = "+ {$remaining} others — open SociFusion inbox or ask Soci.";
        }

        if ($approvalId) {
            $lines[] = "Reply needs approval — Launch {$approvalId}.";
        } elseif ($autonomy->value >= AiAutonomyLevel::Autopilot->value) {
            $lines[] = 'Autopilot is on.';
        }

        return implode("\n", $lines);
    }

    private function maybeAutoHandleHotThreads(User $user, int $organizationId): void
    {
        if (! (bool) config('socifusion_ai.proactive_inbound_reply', true)) {
            return;
        }

        $snap = $this->awareness->snapshot($user, $organizationId);
        $handling = $this->handling;

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
    private function formatAttentionLine(array $row, bool $compact = false): string
    {
        $name = (string) ($row['prospect_name'] ?? 'Prospect');
        $email = trim((string) ($row['prospect_email'] ?? ''));
        $channel = (string) ($row['channel_label'] ?? $row['channel'] ?? 'inbox');
        $preview = (string) ($row['preview'] ?? '');
        $inboxUrl = (string) ($row['inbox_url'] ?? '');
        $campaign = trim((string) ($row['campaign_name'] ?? ''));
        $readNote = ($row['is_unread'] ?? true) ? '' : ' *(read — still needs reply)*';
        $hot = ($row['priority'] ?? '') === 'hot' ? '🔥 ' : '';

        $identity = $email !== '' ? "**{$name}** <{$email}>" : "**{$name}**";
        $tail = $campaign !== '' ? " · {$campaign}" : '';

        if ($compact) {
            $shortPreview = \Illuminate\Support\Str::limit(
                trim(preg_replace('/\s+/', ' ', preg_replace('/^URL:\s*/m', '', $preview) ?? '') ?? ''),
                90,
                '…',
            );

            return $hot."• {$channel}: {$identity}{$readNote}{$tail}"
                .($shortPreview !== '' ? "\n  > {$shortPreview}" : '')
                .($inboxUrl !== '' ? "\n  [Open inbox]({$inboxUrl})" : '');
        }

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
