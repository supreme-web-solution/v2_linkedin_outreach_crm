<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2CallCampaign;
use App\Models\V2CallCampaignMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @deprecated Use Call Manager (`/api/v2/calls`) and web `/calls` instead.
 */
class CallCampaignController extends Controller
{
    /**
     * @param array<string, mixed> $payload
     */
    private function deprecated(array $payload, int $status = 200): JsonResponse
    {
        return response()->json(
            array_merge([
                'deprecated' => true,
                'replacement' => '/api/v2/calls',
                'message' => 'Call campaigns are deprecated. Use Call Manager (V2Call + /calls) for booking flows.',
            ], $payload),
            $status,
            [
                'Deprecation' => 'true',
                'Link' => '</api/v2/calls>; rel="successor-version"',
            ]
        );
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $rows = V2CallCampaign::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->latest('id')
            ->get();

        return $this->deprecated(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'status' => ['nullable', 'string', 'max:50'],
            'meta' => ['nullable', 'array'],
        ]);

        $campaign = V2CallCampaign::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'status' => $data['status'] ?? 'active',
            'meta' => $data['meta'] ?? [],
        ]);

        return $this->deprecated(['data' => $campaign], 201);
    }

    public function addMessage(Request $request, int $campaignId): JsonResponse
    {
        $campaign = $this->resolveOwnedCampaign($request, $campaignId);
        if (!$campaign) {
            return response()->json(['message' => 'Campaign not found.'], 404);
        }

        $data = $request->validate([
            'recipient_id' => ['required', 'string', 'max:191'],
            'message' => ['required', 'string'],
            'scheduled_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        $row = V2CallCampaignMessage::query()->create([
            'campaign_id' => $campaign->id,
            'recipient_id' => $data['recipient_id'],
            'message' => $data['message'],
            'status' => 'pending',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'meta' => $data['meta'] ?? [],
        ]);

        return $this->deprecated(['data' => $row], 201);
    }

    public function readyToSend(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $limit = min((int) $request->query('limit', 20), 100);

        $rows = V2CallCampaignMessage::query()
            ->whereIn('status', ['pending', 'queued'])
            ->where(function ($query) {
                $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            })
            ->whereHas('campaign', function ($query) use ($user, $organizationId) {
                $query->where('user_id', $user->id)
                    ->where('organization_id', $organizationId);
            })
            ->limit($limit)
            ->get();

        return $this->deprecated(['data' => $rows]);
    }

    public function updateMessageStatus(Request $request, int $id): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'status' => ['required', 'string', 'max:50'],
        ]);

        $message = V2CallCampaignMessage::query()
            ->where('id', $id)
            ->whereHas('campaign', function ($query) use ($user, $organizationId) {
                $query->where('user_id', $user->id)
                    ->where('organization_id', $organizationId);
            })
            ->first();

        if (!$message) {
            return response()->json(['message' => 'Call campaign message not found.'], 404);
        }

        $message->forceFill([
            'status' => $data['status'],
            'sent_at' => $data['status'] === 'sent' ? now() : $message->sent_at,
        ])->save();

        return $this->deprecated(['data' => $message]);
    }

    private function resolveOwnedCampaign(Request $request, int $campaignId): ?V2CallCampaign
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        return V2CallCampaign::query()
            ->where('id', $campaignId)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();
    }
}
