<?php

namespace App\V2\Services;

use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2Call;
use App\Models\V2Campaign;
use App\Models\V2Conversation;
use App\Models\V2Message;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;

class DashboardStatsService
{
    /**
     * @return array{
     *     leads: int,
     *     linkedin_leads: int,
     *     imported_leads: int,
     *     campaigns: int,
     *     linkedin_campaigns: int,
     *     outreach_campaigns: int,
     *     conversations: int,
     *     calls: int,
     *     messages_sent: int,
     *     unread_conversations: int
     * }
     */
    public function forUser(User $user): array
    {
        $orgId = $user->current_organization_id;
        $linkedinLeads = $this->linkedinLeadCountForUser($user->id);
        $importedLeads = $this->importedLeadCountForUser($user->id);
        $linkedinCampaigns = $this->linkedinCampaignCount($orgId);
        $outreachCampaigns = $this->outreachCampaignCount($orgId, $user->id);

        return [
            'leads' => $linkedinLeads + $importedLeads,
            'linkedin_leads' => $linkedinLeads,
            'imported_leads' => $importedLeads,
            'campaigns' => $linkedinCampaigns + $outreachCampaigns,
            'linkedin_campaigns' => $linkedinCampaigns,
            'outreach_campaigns' => $outreachCampaigns,
            'conversations' => V2Conversation::query()
                ->where('user_id', $user->id)
                ->count(),
            'calls' => $this->activeCallProspectCount($orgId),
            'messages_sent' => V2Message::query()
                ->where('direction', 'outbound')
                ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id))
                ->count(),
            'unread_conversations' => app(InboxUnreadService::class)->unreadCountForUser($user->id),
        ];
    }

    public function linkedinCampaignCount(?int $orgId): int
    {
        if (! $orgId) {
            return 0;
        }

        return V2Campaign::query()->where('organization_id', $orgId)->count();
    }

    public function outreachCampaignCount(?int $orgId, int $userId): int
    {
        $query = V2OutreachCampaign::query();

        if ($orgId) {
            $query->where(function ($q) use ($orgId, $userId) {
                $q->where('organization_id', $orgId)
                    ->orWhere(function ($inner) use ($userId) {
                        $inner->whereNull('organization_id')->where('user_id', $userId);
                    });
            });
        } else {
            $query->where('user_id', $userId);
        }

        return $query->count();
    }

    /**
     * Total contacts: LinkedIn lists + imported CSV/spreadsheet rows.
     */
    public function leadCountForUser(int $userId): int
    {
        return $this->linkedinLeadCountForUser($userId) + $this->importedLeadCountForUser($userId);
    }

    public function linkedinLeadCountForUser(int $userId): int
    {
        $audienceIds = Audience::query()
            ->where('user_id', $userId)
            ->pluck('audience_id');

        $audienceLeads = $audienceIds->isEmpty()
            ? 0
            : AudienceList::query()->whereIn('audience_id', $audienceIds)->count();

        $snListHashes = SnLeadList::query()
            ->where('user_id', $userId)
            ->pluck('list_hash');

        $snLeads = $snListHashes->isEmpty()
            ? 0
            : SnLead::query()->whereIn('sn_list_id', $snListHashes)->count();

        return $audienceLeads + $snLeads;
    }

    public function importedLeadCountForUser(int $userId): int
    {
        $listIds = V2OutreachImportList::query()
            ->where('user_id', $userId)
            ->pluck('id');

        if ($listIds->isEmpty()) {
            return 0;
        }

        return V2OutreachImportLead::query()
            ->whereIn('import_list_id', $listIds)
            ->count();
    }

    /**
     * Active call-manager prospects (in pipeline).
     */
    public function activeCallProspectCount(?int $orgId): int
    {
        if (! $orgId) {
            return 0;
        }

        return V2Call::query()
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['completed', 'lost', 'failed'])
            ->count();
    }
}
