<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\V2Campaign;
use App\Models\V2CampaignLead;
use App\Models\V2CampaignLeadProgress;
use App\Models\V2CampaignList;
use App\Jobs\V2\SyncCampaignLeadsAndRunJob;
use App\V2\Campaign\CampaignActivityLogger;
use App\V2\Campaign\CampaignCompletionService;
use App\V2\Campaign\CampaignConcurrencyLimiter;
use App\V2\Campaign\CampaignLeadSyncService;
use App\V2\Campaign\CampaignLinkedInGuard;
use App\V2\Campaign\CampaignRunDispatcher;
use App\V2\Campaign\CampaignSequenceResolver;
use App\V2\Services\LeadListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampaignsWebController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));

        $query = $orgId
            ? V2Campaign::where('organization_id', $orgId)->withCount(['campaignLeads', 'campaignLists'])
            : null;

        if ($query && $search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        if ($query && $status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $campaigns = $query
            ? $query->latest()->paginate(12)->appends($request->query())
            : collect()->paginate(1);

        if ($query) {
            $campaigns->getCollection()->transform(function (V2Campaign $c) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'sequence_type' => $c->sequence_type,
                    'status' => $c->status,
                    'created_at' => $c->created_at?->toIso8601String(),
                    'campaign_leads_count' => $c->campaign_leads_count,
                    'campaign_lists_count' => $c->campaign_lists_count,
                    'accept_rate' => $c->acceptRate(),
                ];
            });
        }

        $stats = $orgId ? [
            'total_campaigns' => V2Campaign::where('organization_id', $orgId)->count(),
            'running_campaigns' => V2Campaign::where('organization_id', $orgId)->whereIn('status', ['active', 'running'])->count(),
            'completed_campaigns' => V2Campaign::where('organization_id', $orgId)->where('status', 'completed')->count(),
            'total_leads' => V2CampaignLead::whereIn('campaign_id', V2Campaign::where('organization_id', $orgId)->pluck('id'))->count(),
        ] : ['total_campaigns' => 0, 'running_campaigns' => 0, 'completed_campaigns' => 0, 'total_leads' => 0];

        return Inertia::render('crm/Campaigns', [
            'campaigns' => $campaigns,
            'stats' => $stats,
            'hasOrg' => (bool) $orgId,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'status' => $status !== '' ? $status : null,
            ],
        ]);
    }

    public function statusUpdates(CampaignCompletionService $completion): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        V2Campaign::query()
            ->where('organization_id', $orgId)
            ->whereIn('status', ['active', 'running'])
            ->each(fn (V2Campaign $campaign) => $completion->maybeFinish($campaign));

        $campaigns = V2Campaign::where('organization_id', $orgId)
            ->withCount('campaignLeads')
            ->latest()
            ->get()
            ->map(fn (V2Campaign $c) => [
                'id' => $c->id,
                'status' => $c->status,
                'campaign_leads_count' => $c->campaign_leads_count,
                'accept_rate' => $c->acceptRate(),
            ]);

        return response()->json(['success' => true, 'campaigns' => $campaigns, 'timestamp' => now()->toIso8601String()]);
    }

    public function create(): Response
    {
        return Inertia::render('crm/CampaignBuilder', [
            'templates' => V2Campaign::templates(),
            'campaign' => null,
            'availableLeadLists' => $this->availableLeadLists(),
            'attachedLists' => [],
            'initialStep' => 'template',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'sequence_type' => ['required', 'string', 'in:lead_gen,endorse,profile_views,custom'],
            'node_model' => ['required', 'array'],
            'link_model' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'in:draft,active,paused'],
            'meta' => ['nullable', 'array'],
            'lead_lists' => ['nullable', 'array'],
            'lead_lists.*.list_hash' => ['required_with:lead_lists', 'string'],
            'lead_lists.*.list_src' => ['required_with:lead_lists', 'in:aud,sn'],
            'lead_lists.*.list_name' => ['nullable', 'string'],
        ]);

        $campaign = V2Campaign::create([
            'user_id' => $user->id,
            'organization_id' => $orgId,
            'name' => $data['name'],
            'sequence_type' => $data['sequence_type'],
            'node_model' => $data['node_model'],
            'link_model' => $data['link_model'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'meta' => $data['meta'] ?? null,
        ]);

        foreach ($data['lead_lists'] ?? [] as $list) {
            $this->attachListToCampaign($campaign, $list['list_hash'], $list['list_src'], $list['list_name'] ?? null);
        }

        if (($data['status'] ?? 'draft') === 'active') {
            $this->syncLeadsFromLists($campaign);
            $this->initProgressForCampaign($campaign);
        }

        return redirect("/campaigns/{$campaign->id}")->with('success', 'Campaign created.');
    }

    public function show(Request $request, int $id, CampaignSequenceResolver $resolver): Response
    {
        $campaign = $this->findOwnedCampaign($id);
        $campaign->loadCount(['campaignLeads', 'campaignLists']);
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];

        $leadSearch = trim((string) $request->query('lead_search', ''));
        $leadStatus = trim((string) $request->query('lead_status', ''));

        $leadsQuery = $campaign->campaignLeads()->with('progress')->latest();

        if ($leadSearch !== '') {
            $leadsQuery->where(function ($q) use ($leadSearch) {
                $q->where('full_name', 'like', '%'.$leadSearch.'%')
                    ->orWhere('headline', 'like', '%'.$leadSearch.'%');
            });
        }

        if ($leadStatus !== '' && $leadStatus !== 'all') {
            $leadsQuery->where('status', $leadStatus);
        }

        $leads = $leadsQuery->paginate(20)->appends($request->query());

        $leads->getCollection()->transform(function (V2CampaignLead $lead) use ($nodes, $resolver) {
            $progress = $lead->progress;
            $currentKey = $progress ? (int) ($progress->next_node_key ?: $progress->current_node_key) : 0;
            $currentNode = $currentKey > 0 ? $resolver->findNodeByKey($nodes, $currentKey) : null;

            return [
                'id' => $lead->id,
                'full_name' => $lead->full_name,
                'headline' => $lead->headline,
                'status' => $lead->status,
                'profile_url' => $lead->profile_url,
                'progress' => $progress ? [
                    'run_status' => $progress->run_status,
                    'current_node_key' => $progress->current_node_key,
                    'next_node_key' => $progress->next_node_key,
                    'current_node_label' => $currentNode ? $resolver->nodeLabel($currentNode) : null,
                    'completed_keys' => $progress->completed_keys ?? [],
                    'acceptance_status' => $progress->acceptance_status,
                    'next_run_at' => $progress->next_run_at?->toIso8601String(),
                ] : null,
            ];
        });

        $attachedLists = $campaign->campaignLists()
            ->get()
            ->map(fn (V2CampaignList $l) => [
                'id' => $l->id,
                'list_hash' => $l->list_hash,
                'list_src' => $l->list_src,
                'list_name' => $l->list_name,
                'lead_count' => $this->countListLeads($l->list_hash, $l->list_src),
            ]);

        return Inertia::render('crm/CampaignDetail', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'sequence_type' => $campaign->sequence_type,
                'status' => $campaign->status,
                'node_model' => $campaign->node_model ?? [],
                'meta' => $campaign->meta,
                'campaign_leads_count' => $campaign->campaign_leads_count,
                'campaign_lists_count' => $campaign->campaign_lists_count,
                'accept_rate' => $campaign->acceptRate(),
                'created_at' => $campaign->created_at?->toIso8601String(),
            ],
            'leads' => $leads,
            'attachedLists' => $attachedLists,
            'templates' => V2Campaign::templates(),
            'leadFilters' => [
                'search' => $leadSearch !== '' ? $leadSearch : null,
                'status' => $leadStatus !== '' ? $leadStatus : null,
            ],
            'concurrency' => app(CampaignConcurrencyLimiter::class)->snapshot((int) $campaign->user_id),
        ]);
    }

    public function edit(int $id): Response
    {
        $campaign = $this->findOwnedCampaign($id);

        $attachedLists = $campaign->campaignLists()->get()->map(fn (V2CampaignList $l) => [
            'id' => $l->id,
            'list_hash' => $l->list_hash,
            'list_src' => $l->list_src,
            'list_name' => $l->list_name,
            'lead_count' => $this->countListLeads($l->list_hash, $l->list_src),
        ]);

        return Inertia::render('crm/CampaignBuilder', [
            'templates' => V2Campaign::templates(),
            'campaign' => $campaign,
            'availableLeadLists' => $this->availableLeadLists(),
            'attachedLists' => $attachedLists,
            'initialStep' => request()->query('step', 'build'),
        ]);
    }

    public function update(Request $request, int $id, CampaignRunDispatcher $dispatcher, CampaignLinkedInGuard $linkedInGuard): RedirectResponse
    {
        $campaign = $this->findOwnedCampaign($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'sequence_type' => ['sometimes', 'string', 'in:lead_gen,endorse,profile_views,custom'],
            'node_model' => ['sometimes', 'array'],
            'link_model' => ['nullable', 'array'],
            'status' => ['sometimes', 'string', 'in:draft,active,paused,running,stopped,completed,preparing'],
            'meta' => ['nullable', 'array'],
            'lead_lists' => ['nullable', 'array'],
            'lead_lists.*.list_hash' => ['required_with:lead_lists', 'string'],
            'lead_lists.*.list_src' => ['required_with:lead_lists', 'in:aud,sn'],
            'lead_lists.*.list_name' => ['nullable', 'string'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $activate = (bool) ($data['activate'] ?? false);
        unset($data['activate']);

        $previousStatus = $campaign->status;

        if (isset($data['lead_lists'])) {
            $campaign->campaignLists()->delete();
            foreach ($data['lead_lists'] as $list) {
                $this->attachListToCampaign($campaign, $list['list_hash'], $list['list_src'], $list['list_name'] ?? null);
            }
            unset($data['lead_lists']);
        }

        if ($activate) {
            unset($data['status']);
        }

        if ($data !== []) {
            $campaign->update($data);
            $campaign->refresh();
        }

        if ($activate) {
            $this->queueLeadSyncAndRun($campaign, (int) auth()->user()->current_organization_id);

            return redirect("/campaigns/{$campaign->id}")->with(
                'success',
                'Preparing leads in the background — the run starts automatically when ready.'
            );
        }

        if (($data['status'] ?? '') === 'active') {
            app(CampaignLeadSyncService::class)->syncAllLists($campaign);
            app(CampaignLeadSyncService::class)->initProgress($campaign);
        }

        $newStatus = $data['status'] ?? $campaign->status;
        if (
            in_array($newStatus, ['active', 'running'], true)
            && in_array($previousStatus, ['paused', 'stopped', 'draft', 'preparing'], true)
            && !$activate
        ) {
            if ($linkedInGuard->isUserDisconnected((int) auth()->id())) {
                return redirect("/campaigns/{$campaign->id}")->with(
                    'error',
                    'Your LinkedIn account is disconnected. Reconnect on Integrations before resuming campaigns.',
                );
            }

            $dispatcher->dispatch($campaign, (int) auth()->user()->current_organization_id);
        }

        return redirect("/campaigns/{$campaign->id}")->with('success', 'Campaign updated.');
    }

    public function attachList(Request $request, int $id): JsonResponse
    {
        $campaign = $this->findOwnedCampaign($id);

        $data = $request->validate([
            'list_hash' => ['required', 'string'],
            'list_src' => ['required', 'in:aud,sn'],
            'list_name' => ['nullable', 'string'],
        ]);

        $entry = $this->attachListToCampaign($campaign, $data['list_hash'], $data['list_src'], $data['list_name'] ?? null);

        return response()->json([
            'data' => [
                'id' => $entry->id,
                'list_hash' => $entry->list_hash,
                'list_src' => $entry->list_src,
                'list_name' => $entry->list_name,
                'lead_count' => $this->countListLeads($entry->list_hash, $entry->list_src),
            ],
        ]);
    }

    public function detachList(int $id, int $listId): RedirectResponse
    {
        $campaign = $this->findOwnedCampaign($id);
        V2CampaignList::where('campaign_id', $campaign->id)->where('id', $listId)->delete();

        return back()->with('success', 'Lead list removed from campaign.');
    }

    public function activate(int $id, CampaignRunDispatcher $dispatcher, CampaignLinkedInGuard $linkedInGuard): RedirectResponse
    {
        $campaign = $this->findOwnedCampaign($id);

        if ($linkedInGuard->isUserDisconnected((int) auth()->id())) {
            return redirect("/campaigns/{$campaign->id}")->with(
                'error',
                'Your LinkedIn account is disconnected. Reconnect on Integrations before launching this campaign.',
            );
        }

        if ($campaign->campaignLists()->count() === 0) {
            return back()->withErrors(['campaign' => 'Add at least one lead list before launching.']);
        }

        $this->queueLeadSyncAndRun($campaign, (int) auth()->user()->current_organization_id);

        return redirect("/campaigns/{$campaign->id}")->with(
            'success',
            'Preparing leads in the background — up to '.app(CampaignConcurrencyLimiter::class)->maxInFlight().' will run at once once sync finishes.'
        );
    }

    public function activity(int $id, CampaignActivityLogger $logger): JsonResponse
    {
        $campaign = $this->findOwnedCampaign($id);
        $afterId = request()->query('after_id') ? (int) request()->query('after_id') : null;
        $leadId = request()->query('lead_id') ? (int) request()->query('lead_id') : null;
        $limit = min(100, max(10, (int) (request()->query('limit') ?? 50)));

        return response()->json([
            'success' => true,
            'campaign_id' => $campaign->id,
            'lead_id' => $leadId,
            'events' => $logger->recentForCampaign($campaign->id, $limit, $afterId, $leadId),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function destroy(int $id): RedirectResponse
    {
        $campaign = $this->findOwnedCampaign($id);
        V2CampaignLeadProgress::where('campaign_id', $campaign->id)->delete();
        $campaign->campaignLeads()->delete();
        $campaign->campaignLists()->delete();
        $campaign->delete();

        return redirect('/campaigns')->with('success', 'Campaign deleted.');
    }

    private function findOwnedCampaign(int $id): V2Campaign
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return V2Campaign::where('organization_id', (int) $user->current_organization_id)
            ->where('user_id', $user->id)
            ->findOrFail($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableLeadLists(): array
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return app(LeadListService::class)->listsForUser($user->id)
            ->map(fn (array $list) => [
                'id' => $list['id'],
                'list_name' => $list['list_name'],
                'list_hash' => $list['list_id'],
                'total_leads' => $list['total_leads'],
                'source' => $list['source'],
                'src' => $list['src'],
                'type' => $list['list_id'].'-'.$list['src'],
            ])
            ->values()
            ->all();
    }

    private function attachListToCampaign(V2Campaign $campaign, string $listHash, string $listSrc, ?string $listName): V2CampaignList
    {
        return V2CampaignList::firstOrCreate(
            [
                'campaign_id' => $campaign->id,
                'list_hash' => $listHash,
                'list_src' => $listSrc,
            ],
            ['list_name' => $listName]
        );
    }

    private function countListLeads(string $listHash, string $listSrc): int
    {
        if ($listSrc === 'aud') {
            return AudienceList::where('audience_id', $listHash)->count();
        }

        return SnLead::where('sn_list_id', $listHash)->count();
    }

    private function queueLeadSyncAndRun(V2Campaign $campaign, ?int $organizationId): void
    {
        $sync = app(CampaignLeadSyncService::class);
        $sync->markSyncing($campaign);
        SyncCampaignLeadsAndRunJob::dispatch($campaign->id, $organizationId);
    }

    private function syncLeadsFromLists(V2Campaign $campaign): int
    {
        return app(CampaignLeadSyncService::class)->syncAllLists($campaign);
    }

    private function initProgressForCampaign(V2Campaign $campaign): void
    {
        app(CampaignLeadSyncService::class)->initProgress($campaign);
    }
}
