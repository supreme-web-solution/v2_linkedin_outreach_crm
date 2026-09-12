<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2OutreachLead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Loads identity keys for prospects previously contacted via outreach campaigns.
 *
 * Reuses the same contacted definition as AcquisitionFunnelService:
 * v2_outreach_leads.status IN (running, done, replied).
 */
class PreviouslyContactedQueryService
{
    /** @var list<string> */
    private const CONTACTED_STATUSES = ['running', 'done', 'replied'];

    /**
     * @return array<string, true>
     */
    public function contactedIdentityKeys(User $user, int $organizationId): array
    {
        $keys = [];

        $query = V2OutreachLead::query()
            ->whereIn('status', self::CONTACTED_STATUSES)
            ->whereHas('campaign', function (Builder $q) use ($user, $organizationId) {
                if ($organizationId > 0) {
                    $q->where(function (Builder $inner) use ($organizationId, $user) {
                        $inner->where('organization_id', $organizationId)
                            ->orWhere(function (Builder $legacy) use ($user) {
                                $legacy->whereNull('organization_id')->where('user_id', $user->id);
                            });
                    });
                } else {
                    $q->where('user_id', $user->id);
                }
            });

        foreach ($query->cursor() as $lead) {
            foreach ($this->identityKeysForLead($lead) as $key) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function identityKeysForCandidateRow(array $row): array
    {
        $keys = [];

        foreach ([
            strtolower(trim((string) ($row['linkedin_id'] ?? ''))),
            strtolower(trim((string) ($row['linkedin_key'] ?? ''))),
            strtolower(trim((string) ($row['email'] ?? ''))),
            $this->normalizePhone((string) ($row['phone'] ?? '')),
        ] as $candidate) {
            if ($candidate !== '') {
                $keys[] = $candidate;
            }
        }

        $src = strtolower(trim((string) ($row['src'] ?? '')));
        $recordId = (string) ($row['record_id'] ?? '');
        if ($src !== '' && $recordId !== '') {
            $keys[] = "{$src}:{$recordId}";
        }

        return array_values(array_unique($keys));
    }

    public function rowWasContacted(array $row, array $contactedKeys): bool
    {
        foreach ($this->identityKeysForCandidateRow($row) as $key) {
            if (isset($contactedKeys[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function identityKeysForLead(V2OutreachLead $lead): array
    {
        $keys = [];

        $profile = strtolower(trim((string) ($lead->provider_profile_id ?? '')));
        if ($profile !== '') {
            $keys[] = $profile;
        }

        $email = strtolower(trim((string) ($lead->email ?? '')));
        if ($email !== '') {
            $keys[] = $email;
        }

        $phone = $this->normalizePhone((string) ($lead->phone ?? ''));
        if ($phone !== '') {
            $keys[] = $phone;
        }

        $src = strtolower(trim((string) ($lead->source_list_src ?? '')));
        $recordId = (string) ($lead->source_record_id ?? '');
        if ($src !== '' && $recordId !== '') {
            $keys[] = "{$src}:{$recordId}";
        }

        return array_values(array_unique($keys));
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
