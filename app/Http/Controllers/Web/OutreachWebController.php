<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AudienceList;
use App\Models\SnLead;
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
use App\Jobs\V2\CleanupDeletedCampaignArtifactsJob;
use App\Jobs\V2\SyncOutreachLeadsAndRunJob;
use App\V2\Outreach\OutreachLeadSyncService;
use App\V2\Outreach\OutreachLeadReadinessService;
use App\V2\Outreach\OutreachProgressReconciler;
use App\V2\Outreach\OutreachRunDispatcher;
use App\V2\Outreach\OutreachSequenceResolver;
use App\V2\Services\ChannelConnectionService;
use App\V2\Services\LeadListService;
use App\V2\Services\OutreachChannelInboxSettingsService;
use App\V2\Support\DeletedCampaignArtifactCleaner;
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
            'connectedChannels' => app(ChannelConnectionService::class)->summarizeSequenceForUser($user),
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
                'channels' => OutreachChannelRegistry::sequenceChannels(),
                'actions' => OutreachChannelRegistry::sequenceActionsByChannel(),
                'conditions' => OutreachChannelRegistry::sequenceConditionsByChannel(),
            ],
            'connectedChannels' => $channels->summarizeSequenceForUser($user),
            'campaign' => null,
            'availableLeadLists' => $this->availableLeadLists(),
            'attachedLists' => [],
            'initialStep' => 'template',
            'aiConfigured' => app(\App\V2\Services\OpenAIContentService::class)->isConfigured(),
        ]);
    }

    public function store(Request $request, OutreachChannelInboxSettingsService $inboxSettings): RedirectResponse
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

        if (! empty($data['meta']['channel_inbox']) && is_array($data['meta']['channel_inbox'])) {
            $data['meta'] = $inboxSettings->mergeMetaChannelInbox(
                is_array($data['meta'] ?? null) ? $data['meta'] : [],
                $data['meta']['channel_inbox'],
            );
        }

        $activate = (bool) ($data['activate'] ?? false);
        unset($data['activate']);

        // Draft when launching — queueLeadSyncAndRun moves it to preparing/running.
        $campaign = V2OutreachCampaign::create([
            'user_id' => $user->id,
            'organization_id' => $orgId,
            'name' => $data['name'],
            'template_type' => $data['template_type'],
            'node_model' => $data['node_model'],
            'status' => $activate || ($data['status'] ?? '') === 'active'
                ? 'draft'
                : ($data['status'] ?? 'draft'),
            'meta' => $data['meta'] ?? null,
        ]);

        foreach ($data['lead_lists'] ?? [] as $list) {
            $this->attachList($campaign, $list['list_hash'], $list['list_src'], $list['list_name'] ?? null);
        }

        if ($activate || ($data['status'] ?? '') === 'active') {
            if ($campaign->outreachLists()->count() === 0) {
                return redirect("/outreach/{$campaign->id}")->withErrors([
                    'campaign' => 'Add at least one lead list before launching.',
                ]);
            }

            $this->queueLeadSyncAndRun($campaign, $orgId);

            return redirect("/outreach/{$campaign->id}")->with(
                'success',
                'Outreach created — preparing leads in the background, then the run starts automatically.'
            );
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

        $leads = $campaign->outreachLeads()
            ->with('progress')
            // Active / progressed leads first so the first page isn't only "Pending".
            ->orderByRaw("CASE status
                WHEN 'running' THEN 0
                WHEN 'replied' THEN 1
                WHEN 'error' THEN 2
                WHEN 'done' THEN 3
                WHEN 'skipped' THEN 4
                WHEN 'pending' THEN 5
                ELSE 6
            END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->appends($request->query());
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
            'connectedChannels' => app(ChannelConnectionService::class)->summarizeSequenceForUser($user),
            'inboxSummary' => [
                'replied_leads_count' => $repliedCount,
                'platforms' => $inboxByPlatform,
            ],
            'aiConfigured' => app(\App\V2\Services\OpenAIContentService::class)->isConfigured(),
            'stats' => $statsService->statsFor($campaign),
            'concurrency' => app(\App\V2\Outreach\OutreachConcurrencyLimiter::class)->snapshot((int) $campaign->user_id),
            'channel_limits' => app(\App\V2\Services\UnipileTemporaryLimitGuard::class)->snapshotsForChannels(
                (int) $campaign->user_id,
                OutreachChannelRegistry::requiredChannelsForNodes(
                    is_array($campaign->node_model) ? $campaign->node_model : [],
                ),
            ),
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
                'channels' => OutreachChannelRegistry::sequenceChannels(),
                'actions' => OutreachChannelRegistry::sequenceActionsByChannel(),
                'conditions' => OutreachChannelRegistry::sequenceConditionsByChannel(),
            ],
            'connectedChannels' => $channels->summarizeSequenceForUser($user),
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'template_type' => $campaign->template_type,
                'status' => $campaign->status,
                'node_model' => $campaign->node_model ?? [],
                'meta' => $campaign->meta,
            ],
            'availableLeadLists' => $this->availableLeadLists(),
            'attachedLists' => $attachedLists,
            'initialStep' => request()->query('step', 'build'),
            'aiConfigured' => app(\App\V2\Services\OpenAIContentService::class)->isConfigured(),
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

    public function update(Request $request, int $id, OutreachRunDispatcher $dispatcher, OutreachChannelGuard $guard, OutreachProgressReconciler $reconciler, OutreachChannelInboxSettingsService $inboxSettings): RedirectResponse|JsonResponse
    {
        $campaign = $this->findOwned($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'template_type' => ['sometimes', 'string'],
            'node_model' => ['sometimes', 'array'],
            'status' => ['sometimes', 'string', 'in:draft,active,paused,running,stopped,completed,preparing'],
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

        if (isset($data['meta']) && is_array($data['meta'])) {
            $mergedMeta = array_merge(is_array($campaign->meta) ? $campaign->meta : [], $data['meta']);
            if (! empty($data['meta']['channel_inbox']) && is_array($data['meta']['channel_inbox'])) {
                $mergedMeta = $inboxSettings->mergeMetaChannelInbox($mergedMeta, $data['meta']['channel_inbox']);
            }
            $data['meta'] = $mergedMeta;
        }

        if (isset($data['lead_lists'])) {
            $campaign->outreachLists()->delete();
            foreach ($data['lead_lists'] as $list) {
                $this->attachList($campaign, $list['list_hash'], $list['list_src'], $list['list_name'] ?? null);
            }
            unset($data['lead_lists']);
        }

        if ($activate) {
            unset($data['status']);
        }

        $campaign->update($data);
        $campaign->refresh();

        if ($activate) {
            $this->queueLeadSyncAndRun($campaign, (int) auth()->user()->current_organization_id);

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'preparing' => true]);
            }

            return redirect("/outreach/{$campaign->id}")->with(
                'success',
                'Preparing leads in the background — the run starts automatically when ready.'
            );
        }

        if (($data['status'] ?? '') === 'active') {
            app(OutreachLeadSyncService::class)->syncAllLists($campaign);
            app(OutreachLeadSyncService::class)->initProgress($campaign);
        }

        $newStatus = $data['status'] ?? $campaign->status;
        if (
            in_array($newStatus, ['active', 'running'], true)
            && in_array($previousStatus, ['paused', 'stopped', 'draft', 'preparing'], true)
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

        return redirect("/outreach/{$campaign->id}")->with('success', 'Outreach updated.');
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

        $this->queueLeadSyncAndRun($campaign, (int) auth()->user()->current_organization_id);

        return redirect("/outreach/{$campaign->id}")->with(
            'success',
            'Preparing leads in the background — up to '.app(\App\V2\Outreach\OutreachConcurrencyLimiter::class)->maxInFlight().' will run at once once sync finishes.'
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
        $campaignId = (int) $campaign->id;
        $userId = (int) $campaign->user_id;

        // Stop new work immediately; pending queue jobs are purged in the background.
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

        $base = app(LeadListService::class)->listsForUser($user->id)
            ->map(fn (array $list) => [
                'list_name' => $list['list_name'],
                'list_hash' => $list['list_id'],
                'total_leads' => $list['total_leads'],
                'source' => $list['source'],
                'src' => $list['src'],
                'type' => $list['list_id'].'-'.$list['src'],
            ]);

        $importLists = collect(app(OutreachImportListService::class)->listsForUser($user->id));

        return $base->concat($importLists)->sortBy('list_name')->values()->all();
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

    private function queueLeadSyncAndRun(V2OutreachCampaign $campaign, ?int $organizationId): void
    {
        $sync = app(OutreachLeadSyncService::class);
        $sync->markSyncing($campaign);
        SyncOutreachLeadsAndRunJob::dispatch($campaign->id, $organizationId);
    }

    private function syncLeadsFromLists(V2OutreachCampaign $campaign): int
    {
        return app(OutreachLeadSyncService::class)->syncAllLists($campaign);
    }

    private function initProgress(V2OutreachCampaign $campaign): void
    {
        app(OutreachLeadSyncService::class)->initProgress($campaign);
    }
}
