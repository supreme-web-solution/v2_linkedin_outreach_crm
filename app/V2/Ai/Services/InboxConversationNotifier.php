<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2Conversation;
use App\V2\Outreach\OutreachChannelRegistry;

class InboxConversationNotifier
{
    public function __construct(
        private readonly CommandCenterPushService $push,
    ) {}

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

        $this->push->postAssistant($user, $organizationId, $content, array_merge([
            'source' => 'inbound_reply_watch',
        ], $meta));
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
        ?array $dossierSummary = null,
    ): void {
        $prospect = (string) ($draftData['prospect_name'] ?? 'Prospect');
        $channel = OutreachChannelRegistry::channelLabel((string) ($draftData['channel'] ?? $v2Conversation->provider));
        $priority = str_replace('_', ' ', (string) ($classification['priority'] ?? 'needs_judgment'));
        $intent = str_replace('_', ' ', (string) ($classification['intent'] ?? 'reply'));
        $preview = trim((string) ($draftData['inbound_preview'] ?? ''));
        $draft = trim((string) ($draftData['draft'] ?? ''));
        $inboxUrl = (string) ($draftData['inbox_url'] ?? url('/inbox/'.$v2Conversation->provider.'/'.$v2Conversation->id));

        $researchLines = [];
        if ($researchRan && is_array($dossierSummary)) {
            $pages = is_array($dossierSummary['scraped_pages'] ?? null) ? $dossierSummary['scraped_pages'] : [];
            foreach (array_slice($pages, -2) as $page) {
                if (! is_array($page)) {
                    continue;
                }
                $title = trim((string) ($page['title'] ?? ''));
                $url = trim((string) ($page['url'] ?? ''));
                $excerpt = trim((string) ($page['excerpt'] ?? ''));
                if ($url !== '') {
                    $researchLines[] = '• Researched ['.($title !== '' ? $title : $url).']('.$url.')'
                        .($excerpt !== '' ? ' — '.\Illuminate\Support\Str::limit($excerpt, 120) : '');
                }
            }
            if (($dossierSummary['conversion_stage'] ?? '') !== '' && ($dossierSummary['conversion_stage'] ?? '') !== 'opening') {
                $researchLines[] = '• Prospect memory stage: **'.$dossierSummary['conversion_stage'].'** — [view in inbox]('.$inboxUrl.')';
            }
        }

        $lines = array_filter([
            "📬 **{$channel} reply** from **{$prospect}** ({$priority} · {$intent})",
            $researchRan ? 'Researched links/context from their message before drafting.' : null,
            $researchLines !== [] ? implode("\n", $researchLines) : null,
            $preview !== '' ? "> {$preview}" : null,
        ]);

        if ($autoSent) {
            $lines[] = '✅ Soci sent a tailored reply automatically.';
            if ($draft !== '') {
                $lines[] = "> {$draft}";
            }
            $lines[] = 'No action needed — open SociFusion inbox to review the thread.';
            $approvalId = null;
        } elseif ($approvalId) {
            $lines[] = 'Proposed reply — tap **Send** to approve:';
            if ($draft !== '') {
                $lines[] = "> {$draft}";
            }
            $lines[] = "Or say **LAUNCH {$approvalId}** · [open inbox]({$inboxUrl})";
        } elseif ($draft !== '') {
            $lines[] = 'Draft reply (Copilot — review before sending):';
            $lines[] = "> {$draft}";
            $lines[] = "[Open inbox to send]({$inboxUrl})";
        } else {
            $lines[] = "[Open inbox to reply]({$inboxUrl})";
        }

        $whatsappBody = \App\V2\Ai\Support\WhatsAppNotificationFormatter::plain(implode("\n", array_filter([
            "📬 {$channel} from {$prospect} ({$priority})",
            $autoSent ? 'Soci sent a reply automatically.' : ($approvalId ? "Draft ready — Launch {$approvalId}." : 'Draft ready for review.'),
            $autoSent && $draft !== '' ? \Illuminate\Support\Str::limit($draft, 200, '…') : null,
        ])));

        $this->push->postAssistant($user, $organizationId, implode("\n\n", $lines), [
            'source' => 'proactive_inbound',
            'v2_conversation_id' => $v2Conversation->id,
            'auto_sent' => $autoSent,
            'classification_priority' => $classification['priority'] ?? null,
            'tool' => $approvalId ? 'draft_reply' : null,
            'payload' => $approvalId ? ['type' => 'draft_reply'] : null,
            'whatsapp_body' => $whatsappBody,
        ], $approvalId);
    }
}
