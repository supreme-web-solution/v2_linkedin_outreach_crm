<?php

namespace App\V2\Services;

use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\User;
use App\Models\V2Lead;
use App\Models\V2LeadContactOverlay;
use App\V2\Enrichment\LeadEnrichmentInput;
use App\V2\Enrichment\LeadEnrichmentResult;
use App\V2\Outreach\OutreachLeadContactResolver;

class LeadEnrichmentPersister
{
    public function __construct(
        private readonly OutreachLeadContactResolver $contactResolver,
    ) {}

    public function persistAudienceLead(AudienceList $item, LeadEnrichmentResult $result, int $userId): void
    {
        $updates = [];

        if ($result->isSoftTimeout()) {
            $updates['email_fetch_status'] = 'timed_out';
            $updates['email_fetch_attempted_at'] = now();
        } elseif ($result->emailLookupAttempted) {
            $updates['email_fetch_status'] = 'completed';
            $updates['email_fetch_attempted_at'] = now();
        }

        if ($result->phoneLookupAttempted && ! $result->isSoftTimeout()) {
            $updates['phone_fetch_status'] = 'completed';
            $updates['phone_fetch_attempted_at'] = now();
        }

        if (! empty($result->email) && empty($item->con_email)) {
            $updates['con_email'] = $result->email;
        }

        if (! empty($result->phone) && empty($item->con_phone)) {
            $updates['con_phone'] = $result->phone;
        }

        if ($updates !== []) {
            $item->update($updates);
        }

        $this->persistOverlayFromAudience($item, $result, $userId);
    }

    public function persistSnLead(SnLead $lead, LeadEnrichmentResult $result, int $userId): void
    {
        $updates = [];

        if (! empty($result->email) && empty($lead->email)) {
            $updates['email'] = $result->email;
        }

        if (! empty($result->phone) && empty($lead->phone)) {
            $updates['phone'] = $result->phone;
        }

        if ($result->phoneLookupAttempted && ! $result->isSoftTimeout()) {
            $updates['phone_fetch_status'] = 'completed';
            $updates['phone_fetch_attempted_at'] = now();
        }

        if ($result->isSoftTimeout()) {
            $updates['email_fetch_status'] = 'timed_out';
            $updates['email_fetch_attempted_at'] = now();
        } elseif ($result->emailLookupAttempted) {
            $updates['email_fetch_status'] = 'completed';
            $updates['email_fetch_attempted_at'] = now();
        }

        foreach ([
            'instagram_handle' => $result->instagramHandle,
            'twitter_handle' => $result->twitterHandle,
            'telegram_handle' => $result->telegramHandle,
        ] as $field => $value) {
            if ($value && empty($lead->{$field})) {
                $updates[$field] = $value;
            }
        }

        if ($updates !== []) {
            $lead->update($updates);
        }

        if (! empty($result->email)) {
            $identifier = trim((string) ($lead->lid ?: $lead->sn_lid ?: ''));
            if ($identifier !== '') {
                V2Lead::query()
                    ->where('user_id', $userId)
                    ->where(function ($query) use ($lead, $identifier) {
                        $query->where('public_identifier', $identifier)
                            ->orWhere('provider_profile_id', $identifier)
                            ->orWhere('provider_profile_id', $lead->sn_lid);
                    })
                    ->update(['email' => $result->email]);
            }
        }

        $this->persistOverlayFromSnLead($lead, $result, $userId);
    }

    private function persistOverlayFromAudience(AudienceList $item, LeadEnrichmentResult $result, int $userId): void
    {
        $linkedinKey = $this->contactResolver->normalizeLinkedinKey(
            $item->con_public_identifier ?: $item->con_id
        );

        if ($linkedinKey === '') {
            return;
        }

        $payload = array_filter([
            'email' => $result->email,
            'phone' => $result->phone,
            'instagram_handle' => $result->instagramHandle,
            'twitter_handle' => $result->twitterHandle,
            'telegram_handle' => $result->telegramHandle,
        ], fn ($value) => $value !== null && $value !== '');

        if ($payload === []) {
            return;
        }

        $overlay = V2LeadContactOverlay::query()->firstOrNew([
            'user_id' => $userId,
            'linkedin_key' => $linkedinKey,
        ]);

        foreach ($payload as $field => $value) {
            if (empty($overlay->{$field})) {
                $overlay->{$field} = $value;
            }
        }

        $overlay->save();
    }

    private function persistOverlayFromSnLead(SnLead $lead, LeadEnrichmentResult $result, int $userId): void
    {
        $linkedinKey = $this->contactResolver->normalizeLinkedinKey($lead->lid ?: $lead->sn_lid);
        if ($linkedinKey === '') {
            return;
        }

        $payload = array_filter([
            'email' => $result->email,
            'phone' => $result->phone,
            'instagram_handle' => $result->instagramHandle,
            'twitter_handle' => $result->twitterHandle,
            'telegram_handle' => $result->telegramHandle,
        ], fn ($value) => $value !== null && $value !== '');

        if ($payload === []) {
            return;
        }

        $overlay = V2LeadContactOverlay::query()->firstOrNew([
            'user_id' => $userId,
            'linkedin_key' => $linkedinKey,
        ]);

        foreach ($payload as $field => $value) {
            if (empty($overlay->{$field})) {
                $overlay->{$field} = $value;
            }
        }

        $overlay->save();
    }
}
