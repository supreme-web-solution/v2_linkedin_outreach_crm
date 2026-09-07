<?php

namespace App\V2\Ai\Services;

use App\Models\AiChannelIdentity;
use App\Models\AiChannelLinkCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ChannelIdentityService
{
    public function createLinkCode(User $user, int $organizationId, string $channel = 'whatsapp'): AiChannelLinkCode
    {
        $ttl = (int) config('socifusion_ai.link_code_ttl_minutes', 15);

        return AiChannelLinkCode::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'channel' => $channel,
            'code' => 'LINK-'.Str::upper(Str::random(5)),
            'expires_at' => Carbon::now()->addMinutes($ttl),
        ]);
    }

    public function findValidCode(string $raw): ?AiChannelLinkCode
    {
        $code = Str::upper(trim($raw));
        if (! str_starts_with($code, 'LINK-')) {
            if (preg_match('/\b(LINK-[A-Z0-9]+)\b/i', $raw, $m)) {
                $code = Str::upper($m[1]);
            } else {
                return null;
            }
        }

        $row = AiChannelLinkCode::query()->where('code', $code)->first();

        return $row && $row->isValid() ? $row : null;
    }

    public function linkFromCode(AiChannelLinkCode $link, string $externalId): AiChannelIdentity
    {
        $identity = AiChannelIdentity::query()->updateOrCreate(
            [
                'channel' => $link->channel,
                'external_id' => $this->normalizeExternalId($link->channel, $externalId),
            ],
            [
                'organization_id' => $link->organization_id,
                'user_id' => $link->user_id,
                'status' => 'active',
                'verified_at' => Carbon::now(),
            ]
        );

        $link->update(['consumed_at' => Carbon::now()]);

        return $identity;
    }

    public function resolve(string $channel, string $externalId): ?AiChannelIdentity
    {
        return AiChannelIdentity::query()
            ->where('channel', $channel)
            ->where('external_id', $this->normalizeExternalId($channel, $externalId))
            ->where('status', 'active')
            ->first();
    }

    public function disconnect(User $user, int $organizationId, string $channel = 'whatsapp'): bool
    {
        $updated = AiChannelIdentity::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('channel', $channel)
            ->where('status', 'active')
            ->update([
                'status' => 'disconnected',
                'verified_at' => null,
            ]);

        return $updated > 0;
    }

    public function normalizeExternalId(string $channel, string $externalId): string
    {
        $id = trim($externalId);

        if ($channel === 'whatsapp') {
            // Accept "+1 929 532 0453", "19295320453", etc. → E.164-ish "+19295320453"
            $digits = preg_replace('/\D+/', '', $id) ?? '';

            return $digits !== '' ? '+'.$digits : $id;
        }

        return $id;
    }

    public function syncZernioInboxContext(
        AiChannelIdentity $identity,
        ?string $conversationId,
        ?string $accountId,
    ): void {
        $meta = is_array($identity->meta) ? $identity->meta : [];
        $changed = false;

        if ($conversationId !== null && $conversationId !== '' && ($meta['zernio_conversation_id'] ?? null) !== $conversationId) {
            $meta['zernio_conversation_id'] = $conversationId;
            $changed = true;
        }

        if ($accountId !== null && $accountId !== '' && ($meta['zernio_account_id'] ?? null) !== $accountId) {
            $meta['zernio_account_id'] = $accountId;
            $changed = true;
        }

        if ($changed) {
            $identity->update(['meta' => $meta]);
        }
    }
}
