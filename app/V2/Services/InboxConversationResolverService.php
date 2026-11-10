<?php

namespace App\V2\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2OutreachLead;
use Illuminate\Support\Str;

class InboxConversationResolverService
{
    public function findForUser(
        User $user,
        ?int $conversationId = null,
        ?string $email = null,
        ?string $prospectName = null,
        ?string $providerChatId = null,
        ?string $provider = null,
        bool $unifiedInboxOnly = true,
    ): ?V2Conversation {
        if ($conversationId !== null && $conversationId > 0) {
            $byId = $this->baseQuery($user, $unifiedInboxOnly)
                ->whereKey($conversationId)
                ->first();
            if ($byId) {
                return $byId;
            }
        }

        $providerChatId = trim((string) $providerChatId);
        $provider = Str::lower(trim((string) $provider));
        if ($providerChatId !== '') {
            $byChat = $this->baseQuery($user, $unifiedInboxOnly)
                ->where('provider_chat_id', $providerChatId)
                ->when($provider !== '', fn ($q) => $q->where('provider', $provider))
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first();
            if ($byChat) {
                return $byChat;
            }
        }

        $email = $this->normalizeEmail($email);
        if ($email !== '') {
            $byChatId = $this->baseQuery($user, $unifiedInboxOnly)
                ->whereRaw('LOWER(provider_chat_id) = ?', [$email])
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first();

            if ($byChatId) {
                return $byChatId;
            }

            $leadIds = V2OutreachLead::query()
                ->whereHas('campaign', fn ($q) => $q->where('user_id', $user->id))
                ->whereRaw('LOWER(email) = ?', [$email])
                ->pluck('id');

            if ($leadIds->isNotEmpty()) {
                $byLead = $this->baseQuery($user, $unifiedInboxOnly)
                    ->where(function ($q) use ($leadIds) {
                        foreach ($leadIds as $leadId) {
                            $q->orWhere('meta->outreach_lead_id', $leadId);
                        }
                    })
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id')
                    ->first();
                if ($byLead) {
                    return $byLead;
                }
            }
        }

        $name = trim((string) $prospectName);
        if ($name !== '') {
            $needle = Str::lower($name);

            return $this->baseQuery($user, $unifiedInboxOnly)
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->get()
                ->first(function (V2Conversation $conversation) use ($needle) {
                    $meta = is_array($conversation->meta) ? $conversation->meta : [];
                    $prospect = Str::lower(trim((string) ($meta['prospect_name'] ?? '')));
                    $chatId = Str::lower(trim((string) $conversation->provider_chat_id));

                    if ($prospect !== '' && (str_contains($prospect, $needle) || str_contains($needle, $prospect))) {
                        return true;
                    }

                    return $chatId !== '' && str_contains($chatId, $needle);
                });
        }

        return null;
    }

    /**
     * Resolve the inbox thread for a staged reply/booking approval.
     * Staged conversation ids can go stale after reconnect/orphan merges.
     *
     * @throws \RuntimeException
     */
    public function resolveForApproval(AiActionApproval $approval, User $user): V2Conversation
    {
        $payload = is_array($approval->payload) ? $approval->payload : [];
        $owner = $this->approvalOwner($approval, $user);
        $conversationId = (int) ($payload['conversation_id'] ?? 0);
        $provider = trim((string) ($payload['channel'] ?? ''));
        $providerChatId = trim((string) ($payload['provider_chat_id'] ?? ''));
        $email = $this->normalizeEmail(
            (string) ($payload['prospect_email']
                ?? data_get($payload, 'inbox_intel.prospect_email')
                ?? '')
        );
        $name = trim((string) ($payload['prospect_name'] ?? ''));

        $found = $this->findForUser(
            $owner,
            conversationId: $conversationId > 0 ? $conversationId : null,
            email: $email !== '' ? $email : null,
            prospectName: null,
            providerChatId: $providerChatId !== '' ? $providerChatId : null,
            provider: $provider !== '' ? $provider : null,
            unifiedInboxOnly: false,
        );
        if ($found) {
            return $found;
        }

        $fromUrl = $this->conversationIdFromInboxUrl((string) ($payload['inbox_url'] ?? ''));
        if ($fromUrl !== null) {
            $found = $this->findForUser(
                $owner,
                conversationId: $fromUrl['id'],
                provider: $fromUrl['provider'],
                unifiedInboxOnly: false,
            );
            if ($found) {
                return $found;
            }
        }

        if ($name !== '') {
            $found = $this->findForUser(
                $owner,
                prospectName: $name,
                provider: $provider !== '' ? $provider : null,
                unifiedInboxOnly: false,
            );
            if ($found) {
                return $found;
            }
        }

        $label = $name !== '' ? $name : ('#'.$conversationId);
        throw new \RuntimeException(
            "Inbox thread for {$label} is gone (deleted or remapped after a reconnect). "
            .'Open Inbox and ask Soci to draft again for this prospect.'
        );
    }

    /**
     * When an orphan thread is merged into a keeper, retarget staged reply/booking plans.
     */
    public function retargetApprovalConversationIds(int $userId, int $fromConversationId, int $toConversationId): int
    {
        if ($fromConversationId <= 0 || $toConversationId <= 0 || $fromConversationId === $toConversationId) {
            return 0;
        }

        $keeper = V2Conversation::query()->find($toConversationId);
        $updated = 0;

        $approvals = AiActionApproval::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'approved'])
            ->whereIn('tool', ['draft_reply', 'book_meeting'])
            ->get();

        foreach ($approvals as $approval) {
            $payload = is_array($approval->payload) ? $approval->payload : [];
            if ((int) ($payload['conversation_id'] ?? 0) !== $fromConversationId) {
                continue;
            }

            $payload['conversation_id'] = $toConversationId;
            $payload['retargeted_from_conversation_id'] = $fromConversationId;
            if ($keeper) {
                $payload['provider_chat_id'] = $keeper->provider_chat_id;
                $payload['channel'] = $payload['channel'] ?? $keeper->provider;
                $payload['inbox_url'] = url('/inbox/'.$keeper->provider.'/'.$keeper->id);
            }

            $approval->update(['payload' => $payload]);
            $updated++;
        }

        return $updated;
    }

    /**
     * @return array{provider: string, id: int}|null
     */
    private function conversationIdFromInboxUrl(string $inboxUrl): ?array
    {
        if (! preg_match('#/inbox/([a-z0-9_-]+)/(\d+)#i', $inboxUrl, $matches)) {
            return null;
        }

        return [
            'provider' => Str::lower($matches[1]),
            'id' => (int) $matches[2],
        ];
    }

    private function approvalOwner(AiActionApproval $approval, User $user): User
    {
        if ((int) $approval->user_id === (int) $user->id) {
            return $user;
        }

        return User::query()->find((int) $approval->user_id) ?? $user;
    }

    private function baseQuery(User $user, bool $unifiedInboxOnly)
    {
        $query = V2Conversation::query()->where('user_id', $user->id);

        return $unifiedInboxOnly ? $query->forUnifiedInbox() : $query;
    }

    private function normalizeEmail(?string $email): string
    {
        $email = strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}
