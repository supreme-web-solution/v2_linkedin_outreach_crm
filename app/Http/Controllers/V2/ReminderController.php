<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\V2Reminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $rows = V2Reminder::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('send_at')->orWhere('send_at', '<=', now());
            })
            ->limit(50)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'status' => ['required', 'string', 'max:50'],
        ]);

        $row = V2Reminder::query()
            ->where('id', $data['id'])
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$row) {
            return response()->json(['message' => 'Reminder not found.'], 404);
        }

        $row->forceFill([
            'status' => $data['status'],
            'sent_at' => $data['status'] === 'sent' ? now() : $row->sent_at,
        ])->save();

        return response()->json(['data' => $row]);
    }
}
