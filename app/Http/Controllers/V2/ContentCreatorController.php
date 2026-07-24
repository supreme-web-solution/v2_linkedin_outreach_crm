<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2ContentPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentCreatorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $rows = V2ContentPost::query()
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
            'provider' => ['nullable', 'string', 'max:50'],
            'content' => ['required', 'string'],
            'status' => ['nullable', 'string', 'max:50'],
            'scheduled_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        $row = V2ContentPost::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'provider' => $data['provider'] ?? 'linkedin',
            'content' => $data['content'],
            'status' => $data['status'] ?? 'draft',
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'meta' => $data['meta'] ?? [],
        ]);

        return response()->json(['data' => $row], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'status' => ['required', 'string', 'max:50'],
        ]);

        $row = V2ContentPost::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        $row->forceFill([
            'status' => $data['status'],
            'published_at' => $data['status'] === 'published' ? now() : $row->published_at,
        ])->save();

        return response()->json(['data' => $row]);
    }
}
