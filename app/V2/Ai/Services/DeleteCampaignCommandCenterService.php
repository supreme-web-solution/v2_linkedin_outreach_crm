<?php

namespace App\V2\Ai\Services;

use App\Jobs\V2\CleanupDeletedCampaignArtifactsJob;
use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\SnLeadsCompany;
use App\Models\User;
use App\Models\V2Campaign;
use App\Models\V2CampaignLeadProgress;
use App\Models\V2CampaignRun;
use App\Models\V2ContentPost;
use App\Models\V2Conversation;
use App\Models\V2Lead;
use App\Models\V2LeadSource;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use App\Models\V2OutreachLeadProgress;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Services\LeadListService;
use App\V2\Support\DeletedCampaignArtifactCleaner;
use Illuminate\Support\Str;

/**
 * Destructive deletes always stage for Review & Launch — never auto-run, any autonomy level.
 */
class DeleteCampaignCommandCenterService
{
    public const KINDS = [
        'outreach',
        'outreach_campaign',
        'linkedin',
        'linkedin_campaign',
        'lead_list',
        'content_post',
        'inbox_conversation',
        'outreach_template',
    ];

    public function __construct(
        private readonly OutreachCampaignCommandService $outreach,
        private readonly ActionApprovalService $approvals,
        private readonly CommandCenterService $commandCenter,
        private readonly AiEmployeeSettingsService $settingsService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function stage(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $kind,
        int $campaignId,
        ?string $reason = null,
        string $surface = 'web',
    ): array {
        $kind = $this->normalizeKind($kind);

        return $this->stageResource(
            $user,
            $organizationId,
            $conversation,
            $kind,
            (string) $campaignId,
            null,
            null,
            $reason,
            $surface,
            'delete_campaign',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function stageResource(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        string $kind,
        string $resourceId,
        ?string $listSrc = null,
        ?string $platform = null,
        ?string $reason = null,
        string $surface = 'web',
        string $toolName = 'delete_resource',
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);
        if ($this->settingsService->isBlocked($settings)) {
            return [
                'blocked' => true,
                'message' => 'AI Employee is currently disabled for this workspace.',
            ];
        }

        $kind = $this->normalizeKind($kind);
        $preview = $this->preview($user, $organizationId, $kind, $resourceId, $listSrc, $platform);

        $plan = [
            'type' => in_array($kind, ['outreach', 'linkedin'], true) && $toolName === 'delete_campaign'
                ? 'campaign_delete'
                : 'resource_delete',
            'kind' => $kind,
            // Backward-compatible fields for campaign deletes
            'campaign_id' => in_array($kind, ['outreach', 'linkedin', 'outreach_template'], true)
                ? (int) $resourceId
                : null,
            'resource_id' => $resourceId,
            'list_src' => $preview['list_src'] ?? $listSrc,
            'platform' => $preview['platform'] ?? $platform,
            'resource_name' => $preview['name'],
            'campaign_name' => $preview['name'],
            'detail' => $preview['detail'],
            'reason' => $reason,
            'goal' => $preview['goal'],
            'steps' => $preview['steps'],
            'destructive' => true,
            'requires_explicit_approval' => true,
            'status' => 'awaiting_review',
            'resource_url' => $preview['url'] ?? null,
            'campaign_url' => $preview['url'] ?? null,
        ];

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            $toolName,
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        return [
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $this->commandCenter->formatPlanCard($plan, $approval->id, $surface),
            'cta' => 'Confirm Delete in Review & Launch (or LAUNCH '.$approval->id.' on WhatsApp). Deletes never auto-run.',
        ];
    }

    /**
     * Stage one Confirm Delete for many resources (campaigns, lists, posts, etc.).
     *
     * @param  list<array{kind:string,resource_id:string|int,list_src?:string|null,platform?:string|null}>  $items
     * @return array<string, mixed>
     */
    public function stageBulk(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        array $items,
        ?string $reason = null,
        string $surface = 'web',
        string $toolName = 'delete_resource',
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);
        if ($this->settingsService->isBlocked($settings)) {
            return [
                'blocked' => true,
                'message' => 'AI Employee is currently disabled for this workspace.',
            ];
        }

        if ($items === []) {
            throw new \InvalidArgumentException('No items to delete.');
        }

        $normalizedItems = [];
        $lines = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $kind = $this->normalizeKind((string) ($item['kind'] ?? 'outreach'));
            $resourceId = trim((string) ($item['resource_id'] ?? $item['campaign_id'] ?? ''));
            if ($resourceId === '') {
                throw new \InvalidArgumentException('Item #'.($index + 1).' is missing resource_id.');
            }
            $listSrc = isset($item['list_src']) ? (string) $item['list_src'] : null;
            $platform = isset($item['platform']) ? (string) $item['platform'] : null;
            $preview = $this->preview($user, $organizationId, $kind, $resourceId, $listSrc, $platform);
            $normalizedItems[] = [
                'kind' => $kind,
                'resource_id' => $resourceId,
                'campaign_id' => in_array($kind, ['outreach', 'linkedin', 'outreach_template'], true)
                    ? (int) $resourceId
                    : null,
                'list_src' => $preview['list_src'] ?? $listSrc,
                'platform' => $preview['platform'] ?? $platform,
                'resource_name' => $preview['name'],
                'detail' => $preview['detail'],
                'resource_url' => $preview['url'] ?? null,
            ];
            $lines[] = '• '.$kind.' #'.$resourceId.' — '.$preview['name']
                .(! empty($preview['detail']) ? ' ('.$preview['detail'].')' : '');
        }

        if ($normalizedItems === []) {
            throw new \InvalidArgumentException('No valid items to delete.');
        }

        $count = count($normalizedItems);
        $plan = [
            'type' => 'bulk_delete',
            'kind' => 'bulk',
            'items' => $normalizedItems,
            'item_count' => $count,
            'resource_id' => 'bulk-'.$count,
            'resource_name' => $count.' resource'.($count === 1 ? '' : 's'),
            'campaign_name' => $count.' resource'.($count === 1 ? '' : 's'),
            'detail' => implode("\n", $lines),
            'reason' => $reason,
            'goal' => "Permanently delete {$count} item".($count === 1 ? '' : 's'),
            'steps' => array_merge(
                ['Confirm once to delete all of the following:'],
                $lines,
                ['Cannot be undone from Command Center.'],
            ),
            'destructive' => true,
            'requires_explicit_approval' => true,
            'status' => 'awaiting_review',
        ];

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            $toolName,
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        return [
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => $this->commandCenter->formatPlanCard($plan, $approval->id, $surface),
            'cta' => 'Reply Confirm Delete once to remove all '.$count.' item(s). Deletes never auto-run.',
        ];
    }

    /**
     * @return array{message:string, kind:string, resource_id?:string, campaign_id?:int, deleted?:list<array<string,mixed>>}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $orgId = (int) $approval->organization_id;

        if (($payload['type'] ?? '') === 'bulk_delete' || ($payload['kind'] ?? '') === 'bulk') {
            return $this->applyBulkItems($user, $orgId, is_array($payload['items'] ?? null) ? $payload['items'] : []);
        }

        $kind = $this->normalizeKind((string) ($payload['kind'] ?? 'outreach'));
        $resourceId = trim((string) ($payload['resource_id'] ?? $payload['campaign_id'] ?? ''));

        if ($resourceId === '') {
            throw new \RuntimeException('Missing resource_id on delete plan.');
        }

        return $this->applySingle($user, $orgId, $kind, $resourceId, $payload);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{message:string, kind:string, deleted:list<array<string,mixed>>}
     */
    public function applyBulkItems(User $user, int $organizationId, array $items): array
    {
        $deleted = [];
        $errors = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $kind = $this->normalizeKind((string) ($item['kind'] ?? 'outreach'));
            $resourceId = trim((string) ($item['resource_id'] ?? $item['campaign_id'] ?? ''));
            if ($resourceId === '') {
                continue;
            }
            try {
                $result = $this->applySingle($user, $organizationId, $kind, $resourceId, $item);
                $deleted[] = [
                    'kind' => $kind,
                    'resource_id' => $resourceId,
                    'message' => $result['message'],
                ];
            } catch (\Throwable $e) {
                $errors[] = $kind.' #'.$resourceId.': '.$e->getMessage();
            }
        }

        if ($deleted === [] && $errors !== []) {
            throw new \RuntimeException('Bulk delete failed: '.implode('; ', $errors));
        }

        $lines = array_map(fn (array $row) => $row['message'], $deleted);
        if ($errors !== []) {
            $lines[] = 'Some items failed:';
            $lines = array_merge($lines, $errors);
        }

        return [
            'message' => implode("\n", $lines),
            'kind' => 'bulk',
            'deleted' => $deleted,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message:string, kind:string, resource_id?:string, campaign_id?:int}
     */
    private function applySingle(
        User $user,
        int $organizationId,
        string $kind,
        string $resourceId,
        array $payload = [],
    ): array {
        return match ($kind) {
            'linkedin' => $this->deleteLinkedInCampaign($user, $organizationId, (int) $resourceId),
            'outreach_template' => $this->deleteOutreachTemplate($user, $organizationId, (int) $resourceId),
            'lead_list' => $this->deleteLeadList(
                $user,
                $resourceId,
                (string) ($payload['list_src'] ?? 'aud'),
            ),
            'content_post' => $this->deleteContentPost($user, $organizationId, (int) $resourceId),
            'inbox_conversation' => $this->deleteInboxConversation(
                $user,
                (string) ($payload['platform'] ?? ''),
                (int) $resourceId,
            ),
            default => $this->deleteOutreachCampaign($user, $organizationId, (int) $resourceId),
        };
    }

    /**
     * Every saved lead list for bulk delete (aud, sn, csv — matches Leads page).
     *
     * @return list<array{kind:string,resource_id:string,list_src:string,name:string,total_leads:int}>
     */
    public function listDeletableLeadLists(User $user): array
    {
        return app(LeadListService::class)->listsForUser($user->id)
            ->map(fn (array $list) => [
                'kind' => 'lead_list',
                'resource_id' => (string) $list['list_id'],
                'list_src' => (string) $list['src'],
                'name' => (string) $list['list_name'],
                'total_leads' => (int) ($list['total_leads'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * List outreach campaigns eligible for bulk delete (excludes saved templates).
     *
     * @return list<array{kind:string,resource_id:string,name:string,status:string}>
     */
    /**
     * @return list<array{kind:string,resource_id:string,name:string,status:string}>
     */
    public function listDeletableOutreachCampaigns(
        User $user,
        int $organizationId,
        bool $createdToday = false,
    ): array {
        $query = V2OutreachCampaign::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'template');
            });

        if ($createdToday) {
            $query->whereDate('created_at', now()->toDateString());
        }

        return $query
            ->orderByDesc('id')
            ->get(['id', 'name', 'status'])
            ->map(fn (V2OutreachCampaign $c) => [
                'kind' => 'outreach',
                'resource_id' => (string) $c->id,
                'name' => (string) $c->name,
                'status' => (string) ($c->status ?? ''),
            ])
            ->all();
    }

    /**
     * Every outreach + LinkedIn campaign eligible for bulk delete (excludes templates).
     *
     * @return list<array{kind:string,resource_id:string,name:string,status:string}>
     */
    public function listAllDeletableCampaigns(User $user, int $organizationId): array
    {
        $outreach = $this->listDeletableOutreachCampaigns($user, $organizationId);

        $linkedin = V2Campaign::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->get(['id', 'name', 'status'])
            ->map(fn (V2Campaign $c) => [
                'kind' => 'linkedin',
                'resource_id' => (string) $c->id,
                'name' => (string) $c->name,
                'status' => (string) ($c->status ?? ''),
            ])
            ->all();

        return array_values(array_merge($outreach, $linkedin));
    }

    /**
     * Outreach + LinkedIn extension campaigns created today (local app date).
     *
     * @return list<array{kind:string,resource_id:string,name:string,status:string}>
     */
    public function listDeletableCampaignsCreatedToday(User $user, int $organizationId): array
    {
        $outreach = $this->listDeletableOutreachCampaigns($user, $organizationId, true);

        $linkedin = V2Campaign::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->whereDate('created_at', now()->toDateString())
            ->orderByDesc('id')
            ->get(['id', 'name', 'status'])
            ->map(fn (V2Campaign $c) => [
                'kind' => 'linkedin',
                'resource_id' => (string) $c->id,
                'name' => (string) $c->name,
                'status' => (string) ($c->status ?? ''),
            ])
            ->all();

        return array_values(array_merge($outreach, $linkedin));
    }

    /**
     * @return list<array{kind:string,resource_id:string,name:string,status:string}>
     */
    public function listDeletableCampaignsInWindow(
        User $user,
        int $organizationId,
        string $createdAfter,
        string $createdBefore,
    ): array {
        $from = \Illuminate\Support\Carbon::parse($createdAfter);
        $to = \Illuminate\Support\Carbon::parse($createdBefore);

        $outreach = V2OutreachCampaign::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to])
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'template');
            })
            ->orderByDesc('id')
            ->get(['id', 'name', 'status'])
            ->map(fn (V2OutreachCampaign $c) => [
                'kind' => 'outreach',
                'resource_id' => (string) $c->id,
                'name' => (string) $c->name,
                'status' => (string) ($c->status ?? ''),
            ])
            ->all();

        $linkedin = V2Campaign::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('id')
            ->get(['id', 'name', 'status'])
            ->map(fn (V2Campaign $c) => [
                'kind' => 'linkedin',
                'resource_id' => (string) $c->id,
                'name' => (string) $c->name,
                'status' => (string) ($c->status ?? ''),
            ])
            ->all();

        return array_values(array_merge($outreach, $linkedin));
    }

    public function normalizeKind(string $kind): string
    {
        $kind = Str::lower(trim($kind));

        return match ($kind) {
            'outreach_campaign', 'multichannel', 'multi_channel' => 'outreach',
            'linkedin_campaign', 'extension_campaign' => 'linkedin',
            'list', 'audience', 'audience_list' => 'lead_list',
            'post', 'linkedin_post', 'content' => 'content_post',
            'conversation', 'inbox', 'thread' => 'inbox_conversation',
            'template', 'sequence_template' => 'outreach_template',
            'bulk', 'bulk_delete' => 'bulk',
            default => $kind,
        };
    }

    /**
     * @return array{name:string, detail:string, goal:string, steps:list<string>, url?:string, list_src?:string, platform?:string}
     */
    private function preview(
        User $user,
        int $organizationId,
        string $kind,
        string $resourceId,
        ?string $listSrc,
        ?string $platform,
    ): array {
        return match ($kind) {
            'outreach' => $this->previewOutreach($user, $organizationId, (int) $resourceId),
            'linkedin' => $this->previewLinkedIn($user, $organizationId, (int) $resourceId),
            'outreach_template' => $this->previewTemplate($user, $organizationId, (int) $resourceId),
            'lead_list' => $this->previewLeadList($user, $resourceId, $listSrc ?? 'aud'),
            'content_post' => $this->previewContentPost($user, $organizationId, (int) $resourceId),
            'inbox_conversation' => $this->previewInbox($user, $platform ?? '', (int) $resourceId),
            default => throw new \InvalidArgumentException(
                'Unsupported delete kind. Use: outreach, linkedin, lead_list, content_post, inbox_conversation, outreach_template.',
            ),
        };
    }

    /**
     * @return array{name:string, detail:string, goal:string, steps:list<string>, url:string}
     */
    private function previewOutreach(User $user, int $organizationId, int $campaignId): array
    {
        $campaign = $this->outreach->findOwned($user, $organizationId, $campaignId);
        if (! $campaign) {
            throw new \RuntimeException("Outreach campaign #{$campaignId} not found.");
        }
        $leads = (int) $campaign->outreachLeads()->count();

        return [
            'name' => (string) $campaign->name,
            'detail' => 'status='.$campaign->status.', leads='.$leads,
            'goal' => "Delete outreach campaign #{$campaignId}",
            'steps' => [
                'Permanently delete outreach campaign "'.$campaign->name.'" (#'.$campaignId.').',
                $leads > 0 ? "Removes {$leads} campaign lead assignment(s)." : 'No lead rows attached.',
                'Cannot be undone from Command Center.',
            ],
            'url' => url('/outreach/'.$campaign->id),
        ];
    }

    /**
     * @return array{name:string, detail:string, goal:string, steps:list<string>, url:string}
     */
    private function previewLinkedIn(User $user, int $organizationId, int $campaignId): array
    {
        $campaign = V2Campaign::query()
            ->where('id', $campaignId)
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->first();
        if (! $campaign) {
            throw new \RuntimeException("LinkedIn campaign #{$campaignId} not found.");
        }
        $leads = (int) $campaign->campaignLeads()->count();

        return [
            'name' => (string) $campaign->name,
            'detail' => 'status='.$campaign->status.', leads='.$leads,
            'goal' => "Delete LinkedIn campaign #{$campaignId}",
            'steps' => [
                'Permanently delete LinkedIn campaign "'.$campaign->name.'" (#'.$campaignId.').',
                $leads > 0 ? "Removes {$leads} campaign lead assignment(s)." : 'No lead rows attached.',
                'Cannot be undone from Command Center.',
            ],
            'url' => url('/campaigns/'.$campaign->id),
        ];
    }

    /**
     * @return array{name:string, detail:string, goal:string, steps:list<string>, url:string}
     */
    private function previewTemplate(User $user, int $organizationId, int $campaignId): array
    {
        $campaign = V2OutreachCampaign::query()
            ->where('id', $campaignId)
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'template')
            ->first();
        if (! $campaign) {
            throw new \RuntimeException("Outreach template #{$campaignId} not found.");
        }

        return [
            'name' => (string) $campaign->name,
            'detail' => 'saved sequence template',
            'goal' => "Delete outreach template #{$campaignId}",
            'steps' => [
                'Delete saved template "'.$campaign->name.'" (#'.$campaignId.').',
                'Existing campaigns already created from it are not affected.',
            ],
            'url' => url('/outreach/create'),
        ];
    }

    /**
     * @return array{name:string, detail:string, goal:string, steps:list<string>, url:string, list_src:string}
     */
    private function previewLeadList(User $user, string $listHash, string $src): array
    {
        $src = Str::lower(trim($src));
        if (! in_array($src, ['aud', 'sn', 'csv'], true)) {
            throw new \InvalidArgumentException('list_src must be aud, sn, or csv.');
        }

        $name = $listHash;
        $count = 0;

        if ($src === 'aud') {
            $audience = Audience::query()->where('audience_id', $listHash)->where('user_id', $user->id)->first();
            if (! $audience) {
                throw new \RuntimeException("Audience list {$listHash} not found.");
            }
            $name = (string) ($audience->audience_name ?: $listHash);
            $count = (int) AudienceList::query()->where('audience_id', $listHash)->count();
        } elseif ($src === 'csv') {
            $import = V2OutreachImportList::query()->where('list_hash', $listHash)->where('user_id', $user->id)->first();
            if (! $import) {
                throw new \RuntimeException("Imported list {$listHash} not found.");
            }
            $name = (string) ($import->name ?: $listHash);
            $count = (int) V2OutreachImportLead::query()->where('import_list_id', $import->id)->count();
        } else {
            $list = SnLeadList::query()->where('list_hash', $listHash)->where('user_id', $user->id)->first();
            if (! $list) {
                throw new \RuntimeException("Sales Navigator list {$listHash} not found.");
            }
            $name = (string) ($list->name ?: $listHash);
            $count = (int) SnLead::query()->where('sn_list_id', $listHash)->count();
        }

        return [
            'name' => $name,
            'detail' => "src={$src}, leads≈{$count}",
            'goal' => "Delete lead list {$name}",
            'steps' => [
                "Permanently delete lead list \"{$name}\" ({$src}).",
                $count > 0 ? "This removes about {$count} lead(s) in the list." : 'List appears empty.',
                'Campaigns that referenced this list keep running but lose that attachment source.',
            ],
            'url' => url('/leads'),
            'list_src' => $src,
        ];
    }

    /**
     * @return array{name:string, detail:string, goal:string, steps:list<string>, url:string}
     */
    private function previewContentPost(User $user, int $organizationId, int $postId): array
    {
        $post = V2ContentPost::query()
            ->where('id', $postId)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();
        if (! $post) {
            throw new \RuntimeException("Content post #{$postId} not found.");
        }

        $title = Str::limit(trim((string) ($post->content ?? 'Untitled post')), 60, '…');

        return [
            'name' => $title,
            'detail' => 'status='.(string) ($post->status ?? 'unknown'),
            'goal' => "Delete content post #{$postId}",
            'steps' => [
                "Delete SociFusion content post #{$postId}: {$title}.",
                'This removes the local draft/schedule record — it does not unpublish from LinkedIn automatically.',
            ],
            'url' => url('/content'),
        ];
    }

    /**
     * @return array{name:string, detail:string, goal:string, steps:list<string>, url:string, platform:string}
     */
    private function previewInbox(User $user, string $platform, int $conversationId): array
    {
        $platform = Str::lower(trim($platform));
        if ($platform === '') {
            throw new \InvalidArgumentException('platform is required for inbox_conversation (e.g. linkedin, email, whatsapp).');
        }

        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forInboxPlatform($platform)
            ->where('id', $conversationId)
            ->first();
        if (! $conversation) {
            throw new \RuntimeException("Inbox conversation #{$conversationId} on {$platform} not found.");
        }

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $name = (string) ($meta['prospect_name'] ?? $meta['name'] ?? "Conversation #{$conversationId}");

        return [
            'name' => $name,
            'detail' => "platform={$platform}",
            'goal' => "Delete inbox conversation #{$conversationId}",
            'steps' => [
                "Remove \"{$name}\" from your {$platform} inbox in SociFusion.",
                'Local messages are deleted. The provider thread may still exist outside SociFusion.',
            ],
            'url' => url('/inbox/'.$platform),
            'platform' => $platform,
        ];
    }

    /**
     * @return array{message:string, campaign_id:int, kind:string, resource_id:string}
     */
    private function deleteOutreachCampaign(User $user, int $organizationId, int $campaignId): array
    {
        $campaign = $this->outreach->findOwned($user, $organizationId, $campaignId);
        if (! $campaign) {
            throw new \RuntimeException("Outreach campaign #{$campaignId} not found (may already be deleted).");
        }

        $name = (string) $campaign->name;
        $userId = (int) $campaign->user_id;

        if (in_array($campaign->status, ['active', 'running', 'preparing'], true)) {
            $campaign->update(['status' => 'stopped']);
        }

        V2OutreachLeadProgress::where('outreach_campaign_id', $campaignId)->delete();
        $campaign->outreachLeads()->delete();
        $campaign->outreachLists()->delete();
        $campaign->delete();

        CleanupDeletedCampaignArtifactsJob::dispatch(
            DeletedCampaignArtifactCleaner::KIND_OUTREACH,
            $campaignId,
            $userId,
        );

        return [
            'message' => "Deleted outreach campaign #{$campaignId}: {$name}.",
            'campaign_id' => $campaignId,
            'kind' => 'outreach',
            'resource_id' => (string) $campaignId,
        ];
    }

    /**
     * @return array{message:string, campaign_id:int, kind:string, resource_id:string}
     */
    private function deleteLinkedInCampaign(User $user, int $organizationId, int $campaignId): array
    {
        $campaign = V2Campaign::query()
            ->where('id', $campaignId)
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->first();

        if (! $campaign) {
            throw new \RuntimeException("LinkedIn campaign #{$campaignId} not found (may already be deleted).");
        }

        $name = (string) $campaign->name;
        $userId = (int) $campaign->user_id;

        if (in_array($campaign->status, ['active', 'running', 'preparing'], true)) {
            $campaign->update(['status' => 'stopped']);
        }

        $runIds = V2CampaignRun::query()
            ->where('legacy_campaign_id', $campaignId)
            ->pluck('id')
            ->map(fn ($runId) => (int) $runId)
            ->all();

        V2CampaignLeadProgress::where('campaign_id', $campaignId)->delete();
        $campaign->campaignLeads()->delete();
        $campaign->campaignLists()->delete();
        $campaign->delete();

        CleanupDeletedCampaignArtifactsJob::dispatch(
            DeletedCampaignArtifactCleaner::KIND_CAMPAIGN,
            $campaignId,
            $userId,
            $runIds,
        );

        return [
            'message' => "Deleted LinkedIn campaign #{$campaignId}: {$name}.",
            'campaign_id' => $campaignId,
            'kind' => 'linkedin',
            'resource_id' => (string) $campaignId,
        ];
    }

    /**
     * @return array{message:string, campaign_id:int, kind:string, resource_id:string}
     */
    private function deleteOutreachTemplate(User $user, int $organizationId, int $campaignId): array
    {
        $campaign = V2OutreachCampaign::query()
            ->where('id', $campaignId)
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'template')
            ->first();

        if (! $campaign) {
            throw new \RuntimeException("Outreach template #{$campaignId} not found (may already be deleted).");
        }

        $name = (string) $campaign->name;
        $campaign->delete();

        return [
            'message' => "Deleted outreach template #{$campaignId}: {$name}.",
            'campaign_id' => $campaignId,
            'kind' => 'outreach_template',
            'resource_id' => (string) $campaignId,
        ];
    }

    /**
     * @return array{message:string, kind:string, resource_id:string}
     */
    private function deleteLeadList(User $user, string $listHash, string $src): array
    {
        $src = Str::lower(trim($src));
        $ok = $this->deleteOwnedList((int) $user->id, $listHash, $src);
        if (! $ok) {
            throw new \RuntimeException("Lead list {$listHash} ({$src}) not found (may already be deleted).");
        }

        return [
            'message' => "Deleted lead list {$listHash} ({$src}).",
            'kind' => 'lead_list',
            'resource_id' => $listHash,
        ];
    }

    /**
     * @return array{message:string, kind:string, resource_id:string}
     */
    private function deleteContentPost(User $user, int $organizationId, int $postId): array
    {
        $post = V2ContentPost::query()
            ->where('id', $postId)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();
        if (! $post) {
            throw new \RuntimeException("Content post #{$postId} not found (may already be deleted).");
        }

        $post->delete();

        return [
            'message' => "Deleted content post #{$postId}.",
            'kind' => 'content_post',
            'resource_id' => (string) $postId,
        ];
    }

    /**
     * @return array{message:string, kind:string, resource_id:string}
     */
    private function deleteInboxConversation(User $user, string $platform, int $conversationId): array
    {
        $platform = Str::lower(trim($platform));
        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forInboxPlatform($platform)
            ->where('id', $conversationId)
            ->first();
        if (! $conversation) {
            throw new \RuntimeException("Inbox conversation #{$conversationId} not found (may already be deleted).");
        }

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $name = (string) ($meta['prospect_name'] ?? $meta['name'] ?? "#{$conversationId}");
        $conversation->delete();

        return [
            'message' => "Removed {$name} from your {$platform} inbox.",
            'kind' => 'inbox_conversation',
            'resource_id' => (string) $conversationId,
        ];
    }

    private function deleteOwnedList(int $userId, string $listId, string $src): bool
    {
        if ($src === 'aud') {
            $audience = Audience::where('audience_id', $listId)->where('user_id', $userId)->first();
            if (! $audience) {
                return false;
            }

            AudienceList::query()
                ->where('audience_id', $listId)
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    AudienceList::whereIn('id', $rows->pluck('id'))->delete();
                });
            $audience->delete();

            return true;
        }

        if ($src === 'csv') {
            $importList = V2OutreachImportList::where('list_hash', $listId)->where('user_id', $userId)->first();
            if (! $importList) {
                return false;
            }

            V2OutreachImportLead::query()
                ->where('import_list_id', $importList->id)
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    V2OutreachImportLead::whereIn('id', $rows->pluck('id'))->delete();
                });
            $importList->delete();

            return true;
        }

        $list = SnLeadList::where('list_hash', $listId)->where('user_id', $userId)->first();
        if (! $list) {
            return false;
        }

        SnLead::query()
            ->where('sn_list_id', $listId)
            ->orderBy('id')
            ->chunkById(500, function ($leads) use ($listId, $userId) {
                $leadIds = $leads->pluck('id');
                $snLids = $leads->pluck('sn_lid')->filter()->values();

                SnLeadsCompany::whereIn('sn_lead_id', $leadIds)->delete();

                if ($snLids->isNotEmpty()) {
                    $v2LeadIds = V2Lead::query()
                        ->where('user_id', $userId)
                        ->whereIn('provider_profile_id', $snLids)
                        ->pluck('id');
                    if ($v2LeadIds->isNotEmpty()) {
                        V2LeadSource::query()
                            ->where('source_external_id', $listId)
                            ->whereIn('lead_id', $v2LeadIds)
                            ->delete();
                    }
                }

                SnLead::whereIn('id', $leadIds)->delete();
            });

        $list->delete();

        return true;
    }
}
