<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Audience;
use App\Models\AudienceList;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\Models\V2OutreachList;
use App\V2\Outreach\OutreachActivityLogger;
use App\V2\Outreach\OutreachCampaignStatsService;
use App\V2\Outreach\OutreachChannelGuard;
use App\V2\Outreach\OutreachChannelRegistry;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use App\Models\V2Conversation;
use App\V2\Outreach\OutreachImportListService;
use App\V2\Outreach\OutreachLeadContactResolver;
use App\V2\Outreach\OutreachLeadReadinessService;
use App\V2\Outreach\OutreachProgressReconciler;
use App\V2\Outreach\OutreachRunDispatcher;
use App\V2\Outreach\OutreachSequenceResolver;
use App\V2\Services\ChannelConnectionService;
use App\V2\Services\OutreachChannelInboxSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OutreachWebController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        $query = $orgId
            ? V2OutreachCampaign::where('organization_id', $orgId)
                ->where('status', '!=', 'template')
                ->withCount(['outreachLeads', 'outreachLists'])
            : null;

        $search = trim((string) $request->query('search', ''));
        if ($query && $search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $campaigns = $query
            ? $query->latest()->paginate(12)->appends($request->query())
            : collect()->paginate(1);

        if ($query) {
            $campaigns->getCollection()->transform(fn (V2OutreachCampaign $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'template_type' => $c->template_type,
                'status' => $c->status,
                'created_at' => $c->created_at?->toIso8601String(),
                'outreach_leads_count' => $c->outreach_leads_count,
                'outreach_lists_count' => $c->outreach_lists_count,
            ]);
        }

        return Inertia::render('crm/outreach/OutreachCampaigns', [
            'campaigns' => $campaigns,
            'hasOrg' => (bool) $orgId,
            'connectedChannels' => app(ChannelConnectionService::class)->summarizeForUser($user),
            'filters' => ['search' => $search !== '' ? $search : null],
        ]);
    }

    public function create(ChannelConnectionService $channels): Response
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return Inertia::render('crm/outreach/OutreachBuilder', [
            'templates' => $this->mergeTemplatesWithSaved(),
            'channelRegistry' => [
                'channels' => OutreachChannelRegistry::channels(),
                'actions' => OutreachChannelRegistry::actionsByChannel(),
                'conditions' => OutreachChannelRegistry::conditionsByChannel(),
            ],
            'connectedChannels' => $channels->summarizeForUser($user),
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
            'template_type' => ['required', 'string'],
            'node_model' => ['required', 'array'],
            'status' => ['nullable', 'string', 'in:draft,active,paused'],
            'meta' => ['nullable', 'array'],
            'lead_lists' => ['nullable', 'array'],
            'lead_lists.*.list_hash' => ['required_with:lead_lists', 'string'],
            'lead_lists.*.list_src' => ['required_with:lead_lists', 'in:aud,sn,csv'],
            'lead_lists.*.list_name' => ['nullable', 'string'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $campaign = V2OutreachCampaign::create([
            'user_id' => $user->id,
            'organization_id' => $orgId,
            'name' => $data['name'],
            'template_type' => $data['template_type'],
            'node_model' => $data['node_model'],
            'status' => ($data['activate'] ?? false) ? 'active' : ($data['status'] ?? 'draft'),
            'meta' => $data['meta'] ?? null,
        ]);

        foreach ($data['lead_lists'] ?? [] as $list) {
            $this->attachList($campaign, $list['list_hash'], $list['list_src'], $list['list_name'] ?? null);
        }

        if (($data['activate'] ?? false) || ($data['status'] ?? '') === 'active') {
            $this->syncLeadsFromLists($campaign);
            $this->initProgress($campaign);
        }

        if ($data['activate'] ?? false) {
            $result = app(OutreachRunDispatcher::class)->dispatch($campaign, $orgId);
            if ($result['blocked'] ?? false) {
                return redirect("/outreach/{$campaign->id}")->with(
                    'error',
                    'Connect required channels on Integrations before launching.'
                );
            }
        }

        return redirect("/outreach/{$campaign->id}")->with('success', 'Outreach campaign created.');
    }

    public function show(
        Request $request,
        int $id,
        OutreachSequenceResolver $resolver,
        OutreachChannelInboxSettingsService $inboxSettings,
        OutreachCampaignStatsService $statsService,
    ): Response
    {
        $campaign = $this->findOwned($id);
        $campaign->loadCount(['outreachLeads', 'outreachLists']);
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $leads = $campaign->outreachLeads()->with('progress')->latest()->paginate(20);
        $leads->getCollection()->transform(function (V2OutreachLead $lead) use ($resolver, $campaign) {
            $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
            $progress = $lead->progress;
            $currentKey = $progress ? (int) ($progress->next_node_key ?: $progress->current_node_key) : 0;
            $node = $currentKey > 0 ? $resolver->findNodeByKey($nodes, $currentKey) : null;

            return [
                'id' => $lead->id,
                'full_name' => $lead->full_name,
                'headline' => $lead->headline,
                'email' => $lead->email,
                'status' => $lead->status,
                'profile_url' => $lead->profile_url,
                'progress' => $progress ? [
                    'run_status' => $progress->run_status,
                    'current_node_key' => $progress->current_node_key,
                    'next_node_key' => $progress->next_node_key,
                    'current_node_label' => $node ? $resolver->nodeLabel($node) : null,
                    'next_run_at' => $progress->next_run_at?->toIso8601String(),
                    'paused_reason' => is_array($progress->meta) ? ($progress->meta['paused_reason'] ?? null) : null,
                ] : null,
            ];
        });

        $attachedLists = $campaign->outreachLists()->get()->map(fn (V2OutreachList $l) => [
            'id' => $l->id,
            'list_hash' => $l->list_hash,
            'list_src' => $l->list_src,
            'list_name' => $l->list_name,
            'lead_count' => $this->countListLeads($l->list_hash, $l->list_src),
        ]);

        $inboxChannels = $inboxSettings->inboxChannelsForCampaign($campaign);
        $channelInboxSettings = $inboxSettings->allForCampaign($campaign);
        $repliedCount = $campaign->outreachLeads()->where('status', 'replied')->count();

        $inboxByPlatform = [];
        foreach ($inboxChannels as $channel) {
            $config = OutreachChannelRegistry::channels()[$channel] ?? [];
            $threadCount = V2Conversation::query()
                ->where('user_id', $user->id)
                ->forInboxPlatform($channel)
                ->where('meta->outreach_campaign_id', $campaign->id)
                ->count();

            $recentThreads = V2Conversation::query()
                ->where('user_id', $user->id)
                ->forInboxPlatform($channel)
                ->where('meta->outreach_campaign_id', $campaign->id)
                ->orderByDesc('last_message_at')
                ->limit(5)
                ->get()
                ->map(fn (V2Conversation $c) => [
                    'id' => $c->id,
                    'prospect_name' => is_array($c->meta) ? ($c->meta['prospect_name'] ?? null) : null,
                    'last_message_at' => $c->last_message_at?->toIso8601String(),
                    'href' => route('inbox.show', [$channel, $c->id]),
                ])
                ->all();

            $inboxByPlatform[] = [
                'channel' => $channel,
                'label' => (string) ($config['label'] ?? ucfirst($channel)),
                'color' => (string) ($config['color'] ?? '#64748b'),
                'threads_count' => $threadCount,
                'inbox_href' => route('inbox.platform', $channel).'?campaign='.$campaign->id,
                'recent_threads' => $recentThreads,
                'settings' => $channelInboxSettings[$channel] ?? $inboxSettings->defaults(),
                'settings_update_url' => route('outreach.channel-inbox', [$campaign->id, $channel]),
            ];
        }

        return Inertia::render('crm/outreach/OutreachDetail', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'template_type' => $campaign->template_type,
                'status' => $campaign->status,
                'node_model' => $campaign->node_model ?? [],
                'meta' => $campaign->meta,
                'outreach_leads_count' => $campaign->outreach_leads_count,
                'outreach_lists_count' => $campaign->outreach_lists_count,
                'created_at' => $campaign->created_at?->toIso8601String(),
            ],
            'leads' => $leads,
            'attachedLists' => $attachedLists,
            'connectedChannels' => app(ChannelConnectionService::class)->summarizeForUser($user),
            'inboxSummary' => [
                'replied_leads_count' => $repliedCount,
                'platforms' => $inboxByPlatform,
            ],
            'aiConfigured' => app(\App\V2\Services\OpenAIContentService::class)->isConfigured(),
            'stats' => $statsService->statsFor($campaign),
        ]);
    }

    public function edit(int $id, ChannelConnectionService $channels): Response
    {
        $campaign = $this->findOwned($id);
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $attachedLists = $campaign->outreachLists()->get()->map(fn (V2OutreachList $l) => [
            'id' => $l->id,
            'list_hash' => $l->list_hash,
            'list_src' => $l->list_src,
            'list_name' => $l->list_name,
            'lead_count' => $this->countListLeads($l->list_hash, $l->list_src),
        ]);

        return Inertia::render('crm/outreach/OutreachBuilder', [
            'templates' => $this->mergeTemplatesWithSaved(),
            'channelRegistry' => [
                'channels' => OutreachChannelRegistry::channels(),
                'actions' => OutreachChannelRegistry::actionsByChannel(),
                'conditions' => OutreachChannelRegistry::conditionsByChannel(),
            ],
            'connectedChannels' => $channels->summarizeForUser($user),
            'campaign' => $campaign,
            'availableLeadLists' => $this->availableLeadLists(),
            'attachedLists' => $attachedLists,
            'initialStep' => request()->query('step', 'build'),
        ]);
    }

    public function duplicate(Request $request, int $id): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $source = $this->findOwned($id);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        $copy = V2OutreachCampaign::create([
            'user_id' => $user->id,
            'organization_id' => (int) $user->current_organization_id,
            'name' => $data['name'] ?? ($source->name.' (copy)'),
            'template_type' => $source->template_type,
            'node_model' => $source->node_model,
            'meta' => $source->meta,
            'status' => 'draft',
        ]);

        return redirect("/outreach/{$copy->id}/edit")->with('success', 'Campaign duplicated as draft.');
    }

    public function saveAsTemplate(Request $request, int $id): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $source = $this->findOwned($id);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $meta = is_array($source->meta) ? $source->meta : [];
        if (! empty($data['description'])) {
            $meta['template_description'] = $data['description'];
        }

        V2OutreachCampaign::create([
            'user_id' => $user->id,
            'organization_id' => (int) $user->current_organization_id,
            'name' => $data['name'] ?? ('Template: '.$source->name),
            'template_type' => $source->template_type ?: 'custom',
            'node_model' => $source->node_model,
            'meta' => $meta,
            'status' => 'template',
        ]);

        return redirect('/outreach/create')->with('success', 'Saved as template — pick it when creating a new outreach.');
    }

    public function duplicateTemplate(Request $request, int $id): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $source = $this->findOwned($id);

        if ($source->status !== 'template') {
            abort(404);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        $copy = V2OutreachCampaign::create([
            'user_id' => $user->id,
            'organization_id' => (int) $user->current_organization_id,
            'name' => $data['name'] ?? ($source->name.' (copy)'),
            'template_type' => $source->template_type ?: 'custom',
            'node_model' => $source->node_model,
            'meta' => $source->meta,
            'status' => 'template',
        ]);

        return redirect('/outreach/create')->with('success', 'Template duplicated.');
    }

    public function destroyTemplate(int $id): RedirectResponse
    {
        $campaign = $this->findOwned($id);

        if ($campaign->status !== 'template') {
            abort(404);
        }

        $campaign->delete();

        return redirect('/outreach/create')->with('success', 'Template deleted.');
    }

    public function update(Request $request, int $id, OutreachRunDispatcher $dispatcher, OutreachChannelGuard $guard, OutreachProgressReconciler $reconciler): RedirectResponse|JsonResponse
    {
        $campaign = $this->findOwned($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'template_type' => ['sometimes', 'string'],
            'node_model' => ['sometimes', 'array'],
            'status' => ['sometimes', 'string', 'in:draft,active,paused,running,stopped,completed'],
            'meta' => ['nullable', 'array'],
            'lead_lists' => ['nullable', 'array'],
            'lead_lists.*.list_hash' => ['required_with:lead_lists', 'string'],
            'lead_lists.*.list_src' => ['required_with:lead_lists', 'in:aud,sn,csv'],
            'lead_lists.*.list_name' => ['nullable', 'string'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $activate = (bool) ($data['activate'] ?? false);
        unset($data['activate']);
        $previousStatus = $campaign->status;
        $sequenceChanged = array_key_exists('node_model', $data)
            && json_encode($data['node_model']) !== json_encode($campaign->node_model);

        if (isset($data['lead_lists'])) {
            $campaign->outreachLists()->delete();
            foreach ($data['lead_lists'] as $list) {
                $this->attachList($campaign, $list['list_hash'], $list['list_src'], $list['list_name'] ?? null);
            }
            unset($data['lead_lists']);
        }

        if ($activate) {
            $data['status'] = 'active';
        }

        $campaign->update($data);
        $campaign->refresh();

        if ($activate || ($data['status'] ?? '') === 'active') {
            $this->syncLeadsFromLists($campaign);
            $this->initProgress($campaign);
        }

        $newStatus = $data['status'] ?? $campaign->status;
        if (
            in_array($newStatus, ['active', 'running'], true)
            && in_array($previousStatus, ['paused', 'stopped', 'draft'], true)
            && ! $activate
        ) {
            $missing = $guard->missingChannels((int) auth()->id(), is_array($campaign->node_model) ? $campaign->node_model : []);
            if ($missing !== []) {
                return redirect("/outreach/{$campaign->id}")->with('error', 'Connect required channels on Integrations before resuming.');
            }
            $dispatcher->dispatch($campaign, (int) auth()->user()->current_organization_id);
        } elseif (in_array($campaign->status, ['active', 'running'], true) && ($sequenceChanged || $request->wantsJson())) {
            $reconciler->reconcile($campaign);
        }

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        if ($sequenceChanged && in_array($campaign->status, ['active', 'running'], true)) {
            return redirect("/outreach/{$campaign->id}")->with('success', 'Outreach updated — waiting leads were rescheduled.');
        }

        return redirect("/outreach/{$campaign->id}")->with('success', $activate ? 'Outreach launched.' : 'Outreach updated.');
    }

    public function activate(int $id, OutreachRunDispatcher $dispatcher, OutreachChannelGuard $guard): RedirectResponse
    {
        $campaign = $this->findOwned($id);

        $missing = $guard->missingChannels((int) auth()->id(), is_array($campaign->node_model) ? $campaign->node_model : []);
        if ($missing !== []) {
            return redirect("/outreach/{$campaign->id}")->with(
                'error',
                'Connect '.implode(', ', $missing).' on Integrations before launching.'
            );
        }

        if ($campaign->outreachLists()->count() === 0) {
            return back()->withErrors(['campaign' => 'Add at least one lead list before launching.']);
        }

        $added = $this->syncLeadsFromLists($campaign);
        $this->initProgress($campaign);
        $result = $dispatcher->dispatch($campaign, (int) auth()->user()->current_organization_id);

        if ($result['blocked'] ?? false) {
            return redirect("/outreach/{$campaign->id}")->with('error', 'Could not launch — required channels are not connected.');
        }

        return redirect("/outreach/{$campaign->id}")->with(
            'success',
            "Launched with {$added} new lead(s). Processing {$result['queued_leads']} lead(s)."
        );
    }

    public function activity(int $id, OutreachActivityLogger $logger): JsonResponse
    {
        $campaign = $this->findOwned($id);

        return response()->json([
            'success' => true,
            'events' => $logger->recentForCampaign(
                $campaign->id,
                min(100, max(10, (int) (request()->query('limit') ?? 50))),
                request()->query('after_id') ? (int) request()->query('after_id') : null,
            ),
        ]);
    }

    public function updateChannelInbox(Request $request, int $id, string $channel, OutreachChannelInboxSettingsService $inboxSettings): RedirectResponse
    {
        $campaign = $this->findOwned($id);
        $inboxSettings->assertInboxChannel($channel);

        $data = $request->validate([
            'ai_context' => ['nullable', 'string', 'max:4000'],
            'auto_reply_enabled' => ['sometimes', 'boolean'],
            'pause_on_reply' => ['sometimes', 'boolean'],
        ]);

        $inboxSettings->saveCampaignChannel($campaign, $channel, $data);

        return back()->with('success', OutreachChannelRegistry::channelLabel($channel).' inbox settings saved.');
    }

    public function readinessPreview(Request $request, OutreachLeadReadinessService $readiness): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $data = $request->validate([
            'lead_lists' => ['required', 'array', 'min:1'],
            'lead_lists.*.list_hash' => ['required', 'string'],
            'lead_lists.*.list_src' => ['required', 'in:aud,sn,csv'],
            'node_model' => ['required', 'array'],
        ]);

        $limiter = app(\App\V2\Services\EmailEnrichmentLimiter::class);

        return response()->json([
            'success' => true,
            'readiness' => $readiness->previewForLists($data['lead_lists'], $data['node_model'], (int) $user->id),
            'enrichment_limits' => $limiter->limitsPayloadForUser($user),
        ]);
    }

    public function destroy(int $id): RedirectResponse
    {
        $campaign = $this->findOwned($id);
        V2OutreachLeadProgress::where('outreach_campaign_id', $campaign->id)->delete();
        $campaign->outreachLeads()->delete();
        $campaign->outreachLists()->delete();
        $campaign->delete();

        return redirect('/outreach')->with('success', 'Outreach campaign deleted.');
    }

    private function findOwned(int $id): V2OutreachCampaign
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return V2OutreachCampaign::where('organization_id', (int) $user->current_organization_id)
            ->where('user_id', $user->id)
            ->findOrFail($id);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mergeTemplatesWithSaved(): array
    {
        $templates = V2OutreachCampaign::templates();

        /** @var \App\Models\User $user */
        $user = auth()->user();
        $orgId = (int) $user->current_organization_id;

        if ($orgId <= 0) {
            return $templates;
        }

        $saved = V2OutreachCampaign::query()
            ->where('organization_id', $orgId)
            ->where('user_id', $user->id)
            ->where('status', 'template')
            ->latest()
            ->get();

        foreach ($saved as $campaign) {
            $meta = is_array($campaign->meta) ? $campaign->meta : [];
            $templates['saved_'.$campaign->id] = [
                'label' => $campaign->name,
                'description' => (string) ($meta['template_description'] ?? 'Your saved sequence template.'),
                'icon' => 'bookmark',
                'color' => 'violet',
                'node_model' => is_array($campaign->node_model) ? $campaign->node_model : [],
                'saved' => true,
            ];
        }

        return $templates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableLeadLists(): array
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $audiences = Audience::where('user_id', $user->id)
            ->select('id', 'audience_name', 'audience_id', 'created_at')
            ->selectRaw('(select count(*) from audience_lists where audience_lists.audience_id = audiences.audience_id) as total_leads')
            ->get()
            ->map(fn ($a) => [
                'list_name' => $a->audience_name ?: 'Untitled audience',
                'list_hash' => (string) $a->audience_id,
                'total_leads' => (int) $a->total_leads,
                'source' => 'Audience',
                'src' => 'aud',
                'type' => $a->audience_id.'-aud',
            ]);

        $snLists = SnLeadList::where('user_id', $user->id)
            ->select('id', 'name', 'list_hash', 'created_at')
            ->selectRaw('(select count(*) from sn_leads where sn_leads.sn_list_id = sn_leads_lists.list_hash) as total_leads')
            ->get()
            ->map(fn ($l) => [
                'list_name' => $l->name ?: 'Untitled list',
                'list_hash' => (string) $l->list_hash,
                'total_leads' => (int) $l->total_leads,
                'source' => 'Sales Navigator',
                'src' => 'sn',
                'type' => $l->list_hash.'-sn',
            ]);

        $importLists = collect(app(OutreachImportListService::class)->listsForUser($user->id));

        return $audiences->concat($snLists)->concat($importLists)->sortBy('list_name')->values()->all();
    }

    private function attachList(V2OutreachCampaign $campaign, string $listHash, string $listSrc, ?string $listName): V2OutreachList
    {
        return V2OutreachList::firstOrCreate(
            ['outreach_campaign_id' => $campaign->id, 'list_hash' => $listHash, 'list_src' => $listSrc],
            ['list_name' => $listName]
        );
    }

    private function countListLeads(string $listHash, string $listSrc): int
    {
        if ($listSrc === 'csv') {
            return V2OutreachImportList::where('list_hash', $listHash)->value('lead_count') ?? 0;
        }

        return $listSrc === 'aud'
            ? AudienceList::where('audience_id', $listHash)->count()
            : SnLead::where('sn_list_id', $listHash)->count();
    }

    private function syncLeadsFromLists(V2OutreachCampaign $campaign): int
    {
        $added = 0;
        $resolver = app(OutreachLeadContactResolver::class);
        $leadLists = $campaign->outreachLists()->get()->map(fn ($l) => [
            'list_hash' => $l->list_hash,
            'list_src' => $l->list_src,
        ])->all();
        $overlays = $resolver->overlaysForLists((int) $campaign->user_id, $leadLists);

        foreach ($campaign->outreachLists()->get() as $list) {
            if ($list->list_src === 'csv') {
                $importList = V2OutreachImportList::query()
                    ->where('user_id', $campaign->user_id)
                    ->where('list_hash', $list->list_hash)
                    ->first();

                if (! $importList) {
                    continue;
                }

                foreach ($importList->leads()->get() as $row) {
                    $contactRow = [
                        'email' => trim((string) ($row->email ?? '')),
                        'phone' => trim((string) ($row->phone ?? '')),
                        'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
                        'instagram_handle' => trim((string) ($row->instagram_handle ?? '')),
                        'instagram_provider_id' => trim((string) ($row->instagram_provider_id ?? '')),
                        'telegram_handle' => trim((string) ($row->telegram_handle ?? '')),
                        'telegram_provider_id' => trim((string) ($row->telegram_provider_id ?? '')),
                        'twitter_handle' => trim((string) ($row->twitter_handle ?? '')),
                        'twitter_provider_id' => trim((string) ($row->twitter_provider_id ?? '')),
                    ];
                    $attrs = $resolver->toLeadAttributes($contactRow);
                    $meta = array_merge(['list_hash' => $list->list_hash, 'import_list' => true], $attrs['meta']);
                    $profileId = trim((string) ($row->linkedin_id ?? ''));

                    $lead = V2OutreachLead::firstOrCreate(
                        ['outreach_campaign_id' => $campaign->id, 'source_list_src' => 'csv', 'source_record_id' => $row->id],
                        [
                            'provider_profile_id' => $profileId !== '' ? $profileId : null,
                            'email' => $attrs['email'],
                            'phone' => $attrs['phone'],
                            'full_name' => trim((string) ($row->full_name ?? '')) ?: 'Contact',
                            'headline' => null,
                            'profile_url' => $row->profile_url,
                            'status' => 'pending',
                            'meta' => $meta,
                        ]
                    );

                    if (! $lead->wasRecentlyCreated) {
                        $mergedMeta = array_merge($lead->meta ?? [], $meta);
                        $lead->update(array_filter([
                            'email' => $lead->email ?: $attrs['email'],
                            'phone' => $lead->phone ?: $attrs['phone'],
                            'meta' => $mergedMeta,
                        ]));
                    }

                    if ($lead->wasRecentlyCreated) {
                        $added++;
                    }
                }

                continue;
            }

            if ($list->list_src === 'aud') {
                foreach (AudienceList::where('audience_id', $list->list_hash)->get() as $row) {
                    $name = trim(($row->con_first_name ?? '').' '.($row->con_last_name ?? ''));
                    $profileId = $row->con_public_identifier ?: $row->con_id;
                    $linkedinKey = $resolver->normalizeLinkedinKey($profileId);
                    $contactRow = $resolver->mergeRow([
                        'email' => trim((string) ($row->con_email ?? '')),
                        'phone' => trim((string) ($row->con_phone ?? '')),
                        'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
                        'instagram_handle' => '',
                        'instagram_provider_id' => '',
                        'telegram_handle' => '',
                        'telegram_provider_id' => '',
                        'twitter_handle' => '',
                        'twitter_provider_id' => '',
                    ], $overlays[$linkedinKey] ?? null);
                    $attrs = $resolver->toLeadAttributes($contactRow);
                    $meta = array_merge(['list_hash' => $list->list_hash], $attrs['meta']);

                    $lead = V2OutreachLead::firstOrCreate(
                        ['outreach_campaign_id' => $campaign->id, 'source_list_src' => 'aud', 'source_record_id' => $row->id],
                        [
                            'provider_profile_id' => $profileId,
                            'email' => $attrs['email'],
                            'phone' => $attrs['phone'],
                            'full_name' => $name !== '' ? $name : 'Unknown',
                            'headline' => $row->con_job_title,
                            'profile_url' => $row->con_public_identifier ? 'https://www.linkedin.com/in/'.$row->con_public_identifier : $row->con_profile_url,
                            'status' => 'pending',
                            'meta' => $meta,
                        ]
                    );
                    if (! $lead->wasRecentlyCreated) {
                        $updates = [];
                        if (empty($lead->email) && ! empty($attrs['email'])) {
                            $updates['email'] = $attrs['email'];
                        }
                        if (empty($lead->phone) && ! empty($attrs['phone'])) {
                            $updates['phone'] = $attrs['phone'];
                        }
                        $mergedMeta = array_merge($lead->meta ?? [], $meta);
                        if ($mergedMeta !== ($lead->meta ?? [])) {
                            $updates['meta'] = $mergedMeta;
                        }
                        if ($updates !== []) {
                            $lead->update($updates);
                        }
                    }
                    if ($lead->wasRecentlyCreated) {
                        $added++;
                    }
                }
            } else {
                foreach (SnLead::where('sn_list_id', $list->list_hash)->get() as $row) {
                    $name = trim(($row->first_name ?? '').' '.($row->last_name ?? ''));
                    $linkedinKey = $resolver->normalizeLinkedinKey($row->lid ?: $row->sn_lid);
                    $contactRow = $resolver->mergeRow([
                        'email' => trim((string) ($row->email ?? '')),
                        'phone' => trim((string) ($row->phone ?? '')),
                        'whatsapp_provider_id' => trim((string) ($row->whatsapp_provider_id ?? '')),
                        'instagram_handle' => trim((string) ($row->instagram_handle ?? '')),
                        'instagram_provider_id' => trim((string) ($row->instagram_provider_id ?? '')),
                        'telegram_handle' => trim((string) ($row->telegram_handle ?? '')),
                        'telegram_provider_id' => trim((string) ($row->telegram_provider_id ?? '')),
                        'twitter_handle' => trim((string) ($row->twitter_handle ?? '')),
                        'twitter_provider_id' => trim((string) ($row->twitter_provider_id ?? '')),
                    ], $overlays[$linkedinKey] ?? null);
                    $attrs = $resolver->toLeadAttributes($contactRow);
                    $meta = array_merge(['list_hash' => $list->list_hash], $attrs['meta']);

                    $lead = V2OutreachLead::firstOrCreate(
                        ['outreach_campaign_id' => $campaign->id, 'source_list_src' => 'sn', 'source_record_id' => $row->id],
                        [
                            'provider_profile_id' => $row->lid,
                            'email' => $attrs['email'],
                            'phone' => $attrs['phone'],
                            'full_name' => $name !== '' ? $name : 'Unknown',
                            'headline' => $row->headline,
                            'profile_url' => $row->lid ? 'https://www.linkedin.com/in/'.$row->lid : null,
                            'status' => 'pending',
                            'meta' => $meta,
                        ]
                    );
                    if (! $lead->wasRecentlyCreated) {
                        $updates = [];
                        if (empty($lead->email) && ! empty($attrs['email'])) {
                            $updates['email'] = $attrs['email'];
                        }
                        if (empty($lead->phone) && ! empty($attrs['phone'])) {
                            $updates['phone'] = $attrs['phone'];
                        }
                        $mergedMeta = array_merge($lead->meta ?? [], $meta);
                        if ($mergedMeta !== ($lead->meta ?? [])) {
                            $updates['meta'] = $mergedMeta;
                        }
                        if ($updates !== []) {
                            $lead->update($updates);
                        }
                    }
                    if ($lead->wasRecentlyCreated) {
                        $added++;
                    }
                }
            }
        }

        return $added;
    }

    private function initProgress(V2OutreachCampaign $campaign): void
    {
        foreach ($campaign->outreachLeads()->get() as $lead) {
            V2OutreachLeadProgress::firstOrCreate(
                ['outreach_campaign_id' => $campaign->id, 'outreach_lead_id' => $lead->id],
                ['current_node_key' => 0, 'next_node_key' => 1, 'run_status' => 0, 'channel_state' => []]
            );
        }
    }
}
