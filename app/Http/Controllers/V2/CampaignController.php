<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2Campaign;
use App\Models\V2CampaignLead;
use App\Models\V2CampaignLeadProgress;
use App\Models\V2CampaignRun;
use App\V2\Campaign\CampaignActivityLogger;
use App\V2\Campaign\CampaignRunDispatcher;
use App\V2\Campaign\CampaignSequenceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $campaigns = V2Campaign::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->latest('id')
            ->get();

        return response()->json(['data' => $campaigns]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'sequence_type' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'node_model' => ['nullable', 'array'],
            'link_model' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
        ]);

        $campaign = V2Campaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'sequence_type' => $data['sequence_type'] ?? 'lead_gen',
            'status' => $data['status'] ?? 'active',
            'node_model' => $data['node_model'] ?? [],
            'link_model' => $data['link_model'] ?? [],
            'meta' => $data['meta'] ?? [],
        ]);

        return response()->json(['data' => $campaign], 201);
    }

    public function show(Request $request, int $campaignId): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $campaign = V2Campaign::query()
            ->where('id', $campaignId)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$campaign) {
            return response()->json(['message' => 'Campaign not found.'], 404);
        }

        $runs = V2CampaignRun::query()
            ->where('legacy_campaign_id', $campaign->id)
            ->latest('id')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $campaign,
            'runs' => $runs,
        ]);
    }

    public function updateStatus(Request $request, int $campaignId): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'status' => ['required', 'string', 'max:50'],
        ]);

        $campaign = V2Campaign::query()
            ->where('id', $campaignId)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$campaign) {
            return response()->json(['message' => 'Campaign not found.'], 404);
        }

        $campaign->forceFill(['status' => $data['status']])->save();

        return response()->json(['data' => $campaign]);
    }

    public function run(Request $request, int $campaignId, CampaignRunDispatcher $dispatcher): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $campaign = V2Campaign::query()
            ->where('id', $campaignId)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$campaign) {
            return response()->json(['message' => 'Campaign not found.'], 404);
        }

        $result = $dispatcher->dispatch($campaign, $organizationId);

        return response()->json([
            'data' => [
                'queued' => true,
                'campaign_run_id' => $result['run_id'],
                'queued_leads' => $result['queued_leads'],
            ],
        ], 202);
    }

    /** Return predefined campaign templates (used by extension to offer "New from template"). */
    public function templates(): JsonResponse
    {
        return response()->json(['data' => V2Campaign::templates()]);
    }

    /** Return the full node_model sequence for the extension runner. */
    public function sequence(Request $request, int $campaignId): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $campaign = V2Campaign::where('id', $campaignId)->where('user_id', $user->id)->firstOrFail();
        return response()->json([
            'data' => [
                'campaign_id'   => $campaign->id,
                'name'          => $campaign->name,
                'sequence_type' => $campaign->sequence_type,
                'status'        => $campaign->status,
                'node_model'    => $campaign->node_model ?? [],
                'meta'          => $campaign->meta ?? [],
            ],
        ]);
    }

    /** Mark a specific node as completed / update runStatus on a node in node_model. */
    public function updateNode(Request $request, int $campaignId): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $data = $request->validate([
            'node_key'   => ['required', 'integer'],
            'run_status' => ['required', 'boolean'],
        ]);

        $campaign = V2Campaign::where('id', $campaignId)->where('user_id', $user->id)->firstOrFail();
        $nodes = $campaign->node_model ?? [];
        foreach ($nodes as &$node) {
            if ((int) ($node['key'] ?? -1) === $data['node_key']) {
                $node['runStatus'] = $data['run_status'];
            }
        }
        unset($node);
        $campaign->node_model = $nodes;
        $campaign->save();
        return response()->json(['updated' => true, 'node_key' => $data['node_key']]);
    }

    /** List leads attached to this campaign, with their execution progress. */
    public function leads(Request $request, int $campaignId): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $campaign = V2Campaign::where('id', $campaignId)->where('user_id', $user->id)->firstOrFail();

        $leads = V2CampaignLead::where('campaign_id', $campaign->id)
            ->with('progress')
            ->latest()
            ->paginate(100);

        return response()->json(['data' => $leads]);
    }

    /** Add leads to a campaign. */
    public function addLeads(Request $request, int $campaignId): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $campaign = V2Campaign::where('id', $campaignId)->where('user_id', $user->id)->firstOrFail();

        $data = $request->validate([
            'leads'   => ['required', 'array', 'max:500'],
            'leads.*.provider_profile_id' => ['required', 'string', 'max:191'],
            'leads.*.full_name'   => ['nullable', 'string', 'max:191'],
            'leads.*.headline'    => ['nullable', 'string', 'max:255'],
            'leads.*.profile_url' => ['nullable', 'string', 'max:512'],
            'leads.*.lead_id'     => ['nullable', 'integer'],
        ]);

        $stored = 0;
        foreach ($data['leads'] as $lead) {
            V2CampaignLead::firstOrCreate(
                ['campaign_id' => $campaign->id, 'provider_profile_id' => $lead['provider_profile_id']],
                [
                    'lead_id'     => $lead['lead_id'] ?? null,
                    'full_name'   => $lead['full_name'] ?? null,
                    'headline'    => $lead['headline'] ?? null,
                    'profile_url' => $lead['profile_url'] ?? null,
                    'status'      => 'pending',
                ]
            );
            $stored++;
        }

        return response()->json(['added' => $stored], 201);
    }

    /** Get per-lead execution progress for the extension runner. */
    public function progress(Request $request, int $campaignId, CampaignSequenceResolver $resolver): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $campaign = V2Campaign::where('id', $campaignId)->where('user_id', $user->id)->firstOrFail();
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];

        $progress = V2CampaignLeadProgress::where('campaign_id', $campaign->id)
            ->with('campaignLead')
            ->get()
            ->map(function (V2CampaignLeadProgress $row) use ($nodes, $resolver) {
                $currentKey = (int) ($row->next_node_key ?: $row->current_node_key);
                $currentNode = $currentKey > 0 ? $resolver->findNodeByKey($nodes, $currentKey) : null;

                return [
                    'id' => $row->id,
                    'campaign_id' => $row->campaign_id,
                    'campaign_lead_id' => $row->campaign_lead_id,
                    'current_node_key' => $row->current_node_key,
                    'next_node_key' => $row->next_node_key,
                    'current_node_label' => $currentNode ? $resolver->nodeLabel($currentNode) : null,
                    'acceptance_status' => $row->acceptance_status,
                    'run_status' => $row->run_status,
                    'completed_keys' => $row->completed_keys ?? [],
                    'next_run_at' => $row->next_run_at?->toIso8601String(),
                    'lead' => $row->campaignLead ? [
                        'id' => $row->campaignLead->id,
                        'full_name' => $row->campaignLead->full_name,
                        'status' => $row->campaignLead->status,
                    ] : null,
                ];
            });

        return response()->json(['data' => $progress]);
    }

    /** Recent per-node execution log for a campaign. */
    public function activity(Request $request, int $campaignId, CampaignActivityLogger $logger): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        V2Campaign::where('id', $campaignId)->where('user_id', $user->id)->firstOrFail();

        $afterId = $request->query('after_id') ? (int) $request->query('after_id') : null;
        $leadId = $request->query('lead_id') ? (int) $request->query('lead_id') : null;
        $limit = min(100, max(10, (int) ($request->query('limit') ?? 50)));

        return response()->json([
            'data' => $logger->recentForCampaign($campaignId, $limit, $afterId, $leadId),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /** Update a single lead's execution progress (called by extension after each step). */
    public function updateProgress(
        Request $request,
        int $campaignId,
        int $leadId,
        CampaignActivityLogger $logger,
        CampaignSequenceResolver $resolver,
    ): JsonResponse {
        $user = $request->attributes->get('v2User');
        $campaign = V2Campaign::where('id', $campaignId)->where('user_id', $user->id)->firstOrFail();

        $data = $request->validate([
            'current_node_key'  => ['nullable', 'integer'],
            'next_node_key'     => ['nullable', 'integer'],
            'acceptance_status' => ['nullable', 'boolean'],
            'run_status'        => ['nullable', 'integer'],
            'completed_keys'    => ['nullable', 'array'],
            'next_run_at'       => ['nullable', 'date'],
            'event_status'      => ['nullable', 'string', 'max:32'],
            'event_message'     => ['nullable', 'string', 'max:500'],
        ]);

        $campaignLead = V2CampaignLead::where('campaign_id', $campaign->id)->where('id', $leadId)->firstOrFail();

        $progress = V2CampaignLeadProgress::updateOrCreate(
            ['campaign_id' => $campaign->id, 'campaign_lead_id' => $campaignLead->id],
            array_filter([
                'current_node_key'  => $data['current_node_key'] ?? null,
                'next_node_key'     => $data['next_node_key'] ?? null,
                'acceptance_status' => $data['acceptance_status'] ?? null,
                'run_status'        => $data['run_status'] ?? null,
                'completed_keys'    => $data['completed_keys'] ?? null,
                'next_run_at'       => $data['next_run_at'] ?? null,
            ], fn ($v) => $v !== null)
        );

        if (isset($data['run_status'])) {
            $status = match ((int) $data['run_status']) {
                4       => 'done',
                9       => 'error',
                default => 'running',
            };
            $campaignLead->update(['status' => $status]);
        }

        $nodeKey = (int) ($data['current_node_key'] ?? $data['next_node_key'] ?? 0);
        $nodes = is_array($campaign->node_model) ? $campaign->node_model : [];
        $node = $nodeKey > 0 ? $resolver->findNodeByKey($nodes, $nodeKey) : null;

        if (!empty($data['event_message']) || !empty($data['event_status'])) {
            $logger->log(
                $campaign->id,
                $campaignLead->id,
                null,
                $node,
                (string) ($data['event_status'] ?? 'info'),
                (string) ($data['event_message'] ?? 'Progress updated'),
            );
        }

        return response()->json(['data' => $progress, 'updated' => true]);
    }
}
