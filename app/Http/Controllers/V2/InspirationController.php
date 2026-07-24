<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2InspirationPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InspirationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $rows = V2InspirationPost::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'source' => ['nullable', 'string', 'max:50'],
            'post_id' => ['nullable', 'string', 'max:191'],
            'content' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
        ]);

        $row = V2InspirationPost::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'source' => $data['source'] ?? 'linkedin',
            'post_id' => $data['post_id'] ?? null,
            'content' => $data['content'] ?? null,
            'meta' => $data['meta'] ?? [],
        ]);

        return response()->json(['data' => $row], 201);
    }
}
