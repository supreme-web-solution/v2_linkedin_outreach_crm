<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2OutreachLead;
use Illuminate\Support\Str;

/**
 * Pre-stage identity checks for cold outbound — domain-agnostic.
 */
class ColdOutboundIdentityGuardService
{
    /**
     * @param  array<string, mixed>  $identity
     * @return array{
     *     ok:bool,
     *     warnings:list<string>,
     *     inbox_conversation_id:?int,
     *     pending_approval_id:?int,
     *     suggest_draft_reply:bool
     * }
     */
    public function inspect(User $user, int $organizationId, array $identity): array
    {
        $warnings = [];
        $inboxId = $this->findInboxConversationId($user, $identity);
        $pendingId = $this->findPendingApprovalId($user, $organizationId, $identity);
        $contacted = $this->wasRecentlyContacted($user, $identity);

        if ($inboxId !== null) {
            $warnings[] = 'An inbox thread already exists for this contact — prefer draft_reply on conversation '.$inboxId.' instead of a new cold send.';
        }
        if ($pendingId !== null) {
            $warnings[] = 'A pending Review & Launch draft already exists for this contact (approval '.$pendingId.'). Reuse or LAUNCH it instead of staging a duplicate.';
        }
        if ($contacted) {
            $warnings[] = 'This contact appears in recent outreach activity — confirm before another cold touch.';
        }

        return [
            'ok' => $inboxId === null, // hard-suggest inbox path when thread exists
            'warnings' => $warnings,
            'inbox_conversation_id' => $inboxId,
            'pending_approval_id' => $pendingId,
            'suggest_draft_reply' => $inboxId !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function findInboxConversationId(User $user, array $identity): ?int
    {
        $email = strtolower(trim((string) ($identity['email'] ?? '')));
        $phone = preg_replace('/\D+/', '', (string) ($identity['phone'] ?? '')) ?: '';
        $linkedin = strtolower(trim((string) ($identity['linkedin_url'] ?? '')));
        $ig = strtolower(ltrim(trim((string) ($identity['instagram_handle'] ?? '')), '@'));

        if ($email === '' && $phone === '' && $linkedin === '' && $ig === '') {
            return null;
        }

        $query = V2Conversation::query()->where('user_id', $user->id)->orderByDesc('id');

        $match = $query->get(['id', 'meta', 'provider'])->first(function (V2Conversation $c) use ($email, $phone, $linkedin, $ig) {
            $meta = is_array($c->meta) ? $c->meta : [];
            $hay = strtolower(json_encode($meta) ?: '');
            if ($email !== '' && str_contains($hay, $email)) {
                return true;
            }
            if ($phone !== '' && str_contains(preg_replace('/\D+/', '', $hay) ?: '', $phone)) {
                return true;
            }
            if ($linkedin !== '' && str_contains($hay, $linkedin)) {
                return true;
            }
            if ($ig !== '' && str_contains($hay, $ig)) {
                return true;
            }

            return false;
        });

        return $match?->id;
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function findPendingApprovalId(User $user, int $organizationId, array $identity): ?int
    {
        $needles = array_values(array_filter([
            strtolower(trim((string) ($identity['email'] ?? ''))),
            strtolower(trim((string) ($identity['linkedin_url'] ?? ''))),
            strtolower(ltrim(trim((string) ($identity['instagram_handle'] ?? '')), '@')),
            strtolower(ltrim(trim((string) ($identity['telegram_handle'] ?? '')), '@')),
            strtolower(ltrim(trim((string) ($identity['twitter_handle'] ?? '')), '@')),
            preg_replace('/\D+/', '', (string) ($identity['phone'] ?? '')) ?: null,
        ]));

        if ($needles === []) {
            return null;
        }

        $pending = AiActionApproval::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->where(function ($q): void {
                $q->where('payload->source', 'cold_outbound')
                    ->orWhere('payload->one_shot', true);
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'payload']);

        foreach ($pending as $approval) {
            $blob = strtolower(json_encode(is_array($approval->payload) ? $approval->payload : []) ?: '');
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($blob, $needle)) {
                    return (int) $approval->id;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function wasRecentlyContacted(User $user, array $identity): bool
    {
        $email = strtolower(trim((string) ($identity['email'] ?? '')));
        $linkedin = strtolower(trim((string) ($identity['linkedin_url'] ?? '')));
        if ($email === '' && $linkedin === '') {
            return false;
        }

        return V2OutreachLead::query()
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $user->id))
            ->where(function ($q) use ($email, $linkedin): void {
                if ($email !== '') {
                    $q->orWhere('meta->email', $email)
                        ->orWhere('meta->contact_email', $email);
                }
                if ($linkedin !== '') {
                    $q->orWhere('profile_url', 'like', '%'.Str::after($linkedin, 'linkedin.com').'%');
                }
            })
            ->whereNotNull('meta->last_touch_at')
            ->where('meta->last_touch_at', '>=', now()->subDays(30)->toIso8601String())
            ->exists();
    }
}
