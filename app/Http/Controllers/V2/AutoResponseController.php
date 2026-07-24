<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2AutoResponse;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AutoResponseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $rows = V2AutoResponse::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->latest('id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'message_type' => ['nullable', 'string', 'max:50'],
            'message_keywords' => ['nullable', 'string', 'max:255'],
            'message_body' => ['required', 'string'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', Rule::in(array_keys(OutreachChannelRegistry::channels()))],
            'attachments' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $row = V2AutoResponse::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'message_type' => $data['message_type'] ?? 'contains',
            'message_keywords' => $data['message_keywords'] ?? null,
            'message_body' => $data['message_body'],
            'platforms' => $this->normalizePlatforms($data['platforms'] ?? null),
            'attachments' => $data['attachments'] ?? [],
            'enabled' => $data['enabled'] ?? true,
        ]);

        return response()->json(['data' => $row], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $row = $this->resolveOwnedRecord($request, $id);
        if (!$row) {
            return response()->json(['message' => 'Auto response not found.'], 404);
        }

        return response()->json(['data' => $row]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = $this->resolveOwnedRecord($request, $id);
        if (!$row) {
            return response()->json(['message' => 'Auto response not found.'], 404);
        }

        $data = $request->validate([
            'message_type' => ['nullable', 'string', 'max:50'],
            'message_keywords' => ['nullable', 'string', 'max:255'],
            'message_body' => ['nullable', 'string'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', Rule::in(array_keys(OutreachChannelRegistry::channels()))],
            'attachments' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $row->forceFill(array_filter([
            'message_type' => $data['message_type'] ?? null,
            'message_keywords' => $data['message_keywords'] ?? null,
            'message_body' => $data['message_body'] ?? null,
            'platforms' => array_key_exists('platforms', $data) ? $this->normalizePlatforms($data['platforms']) : null,
            'attachments' => $data['attachments'] ?? null,
            'enabled' => $data['enabled'] ?? null,
        ], static fn ($value) => $value !== null))->save();

        return response()->json(['data' => $row]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = $this->resolveOwnedRecord($request, $id);
        if (!$row) {
            return response()->json(['message' => 'Auto response not found.'], 404);
        }

        $row->delete();

        return response()->json(['message' => 'deleted']);
    }

    private function resolveOwnedRecord(Request $request, int $id): ?V2AutoResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        return V2AutoResponse::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();
    }

    /**
     * @param  array<int, string>|null  $platforms
     * @return array<int, string>
     */
    private function normalizePlatforms(?array $platforms): array
    {
        if (! is_array($platforms) || $platforms === []) {
            return [];
        }

        $allowed = array_keys(OutreachChannelRegistry::channels());

        return array_values(array_unique(array_filter(array_map(
            fn ($platform) => strtolower(trim((string) $platform)),
            $platforms
        ), fn ($platform) => in_array($platform, $allowed, true))));
    }
}
