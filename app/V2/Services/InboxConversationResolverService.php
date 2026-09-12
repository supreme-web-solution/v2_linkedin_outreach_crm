<?php

namespace App\V2\Services;

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
    ): ?V2Conversation {
        if ($conversationId !== null && $conversationId > 0) {
            return V2Conversation::query()
                ->where('user_id', $user->id)
                ->forUnifiedInbox()
                ->whereKey($conversationId)
                ->first();
        }

        $email = $this->normalizeEmail($email);
        if ($email !== '') {
            $byChatId = V2Conversation::query()
                ->where('user_id', $user->id)
                ->forUnifiedInbox()
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
                return V2Conversation::query()
                    ->where('user_id', $user->id)
                    ->forUnifiedInbox()
                    ->where(function ($q) use ($leadIds) {
                        foreach ($leadIds as $leadId) {
                            $q->orWhere('meta->outreach_lead_id', $leadId);
                        }
                    })
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id')
                    ->first();
            }
        }

        $name = trim((string) $prospectName);
        if ($name !== '') {
            $needle = Str::lower($name);

            return V2Conversation::query()
                ->where('user_id', $user->id)
                ->forUnifiedInbox()
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

    private function normalizeEmail(?string $email): string
    {
        $email = strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}
