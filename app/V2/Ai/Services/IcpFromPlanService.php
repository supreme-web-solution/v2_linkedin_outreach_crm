<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use Illuminate\Support\Carbon;

class IcpFromPlanService
{
    /**
     * @return array{message:string, icp:array<string,mixed>}
     */
    public function applyFromApproval(AiActionApproval $approval, User $user): array
    {
        $payload = $approval->payload ?? [];
        $icp = is_array($payload['icp'] ?? null) ? $payload['icp'] : [];

        if ($icp === []) {
            throw new \RuntimeException('ICP plan is empty.');
        }

        $settings = app(AiEmployeeSettingsService::class)->for($user, (int) $approval->organization_id);
        $meta = is_array($settings->meta) ? $settings->meta : [];
        $meta['stored_icp'] = [
            'icp' => $icp,
            'goal' => $payload['goal'] ?? null,
            'saved_at' => Carbon::now()->toIso8601String(),
            'approval_id' => $approval->id,
        ];
        $settings->update(['meta' => $meta]);

        return [
            'message' => 'ICP saved to your AI Employee workspace. Say "find prospects" or "draft campaign" to use it.',
            'icp' => $icp,
        ];
    }
}
