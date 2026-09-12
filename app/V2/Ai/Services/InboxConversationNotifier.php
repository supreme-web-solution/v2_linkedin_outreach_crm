<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Models\V2Conversation;
use App\V2\Outreach\OutreachChannelRegistry;

class InboxConversationNotifier
{
    public function notify(
        User $user,
        int $organizationId,
        string $content,
        array $meta = [],
    ): void {
        $content = trim($content);
        if ($content === '' || $organizationId <= 0) {
            return;
        }

        $conversation = app(CommandCenterService::class)->conversation($user, $organizationId);

        AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $content,
            'meta' => array_merge([
                'source' => 'inbound_reply_watch',
                'channel' => 'web',
            ], $meta),
        ]);
    }

    /**
     * @param  array<string, mixed>  $draftData
     * @param  array<string, mixed>  $classification
     */
    public function notifyInboundReplyPrepared(
        User $user,
        int $organizationId,
        V2Conversation $v2Conversation,
        array $draftData,
        array $classification,
        ?int $approvalId,
        bool $autoSent,
        bool $researchRan,
    ): void {
        $prospect = (string) ($draftData['prospect_name'] ?? 'Prospect');
        $channel = OutreachChannelRegistry::channelLabel((string) ($draftData['channel'] ?? $v2Conversation->provider));
        $priority = str_replace('_', ' ', (string) ($classification['priority'] ?? 'needs_judgment'));
        $intent = str_replace('_', ' ', (string) ($classification['intent'] ?? 'reply'));
        $preview = trim((string) ($draftData['inbound_preview'] ?? ''));
        $draft = trim((string) ($draftData['draft'] ?? ''));
        $inboxUrl = (string) ($draftData['inbox_url'] ?? url('/inbox/'.$v2Conversation->provider.'/'.$v2Conversation->id));

        $lines = array_filter([
            "📬 **{$channel} reply** from **{$prospect}** ({$priority} · {$intent})",
            $researchRan ? 'Researched links/context from their message before drafting.' : null,
            $preview !== '' ? "> {$preview}" : null,
        ]);

        if ($autoSent) {
            $lines[] = 'Soci sent a tailored reply automatically.';
            $lines[] = "[Open thread]({$inboxUrl})";
        } elseif ($approvalId) {
            $lines[] = 'Proposed reply:';
            if ($draft !== '') {
                $lines[] = "> {$draft}";
            }
            $lines[] = "Review in **Launch {$approvalId}** or [open inbox]({$inboxUrl}).";
        } elseif ($draft !== '') {
            $lines[] = 'Draft reply (Copilot — review before sending):';
            $lines[] = "> {$draft}";
            $lines[] = "[Open inbox to send]({$inboxUrl})";
        } else {
            $lines[] = "[Open inbox to reply]({$inboxUrl})";
        }

        $this->notify($user, $organizationId, implode("\n\n", $lines), [
            'v2_conversation_id' => $v2Conversation->id,
            'approval_id' => $approvalId,
            'auto_sent' => $autoSent,
            'classification_priority' => $classification['priority'] ?? null,
        ]);
    }
}
