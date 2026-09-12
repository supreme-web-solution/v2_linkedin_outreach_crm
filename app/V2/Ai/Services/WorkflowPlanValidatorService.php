<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use Carbon\Carbon;

class WorkflowPlanValidatorService
{
    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,errors:list<string>}
     */
    public function validate(User $user, int $organizationId, string $tool, array $payload): array
    {
        $errors = [];
        $policy = app(ToolPolicyRegistry::class)->policyFor($tool);
        $knownClass = (string) ($policy['action_class'] ?? '');

        if ($knownClass === '') {
            $errors[] = 'Unknown tool policy.';
        }

        $targetCount = (int) ($payload['target_count'] ?? $payload['count'] ?? 0);
        if ($targetCount > 100) {
            $errors[] = 'Target count exceeds max policy limit (100).';
        }

        $channelsRaw = $payload['channels'] ?? $payload['preferred_channels'] ?? null;
        if (is_string($channelsRaw) && trim($channelsRaw) !== '') {
            $allowed = ['linkedin', 'email', 'whatsapp', 'instagram', 'telegram', 'twitter', '+', ',', ' '];
            $normalized = strtolower($channelsRaw);
            $tokens = preg_split('/[\+,]/', $normalized) ?: [];
            foreach ($tokens as $token) {
                $channel = trim($token);
                if ($channel === '') {
                    continue;
                }
                if (! in_array($channel, ['linkedin', 'email', 'whatsapp', 'instagram', 'telegram', 'twitter'], true)) {
                    $errors[] = "Unsupported channel in plan: {$channel}.";
                }
            }
            foreach (str_split($normalized) as $char) {
                if (ctype_alpha($char)) {
                    continue;
                }
                if (! in_array($char, $allowed, true)) {
                    $errors[] = 'Invalid channel format in plan.';
                    break;
                }
            }
        }

        $scheduledAt = $payload['scheduled_at'] ?? $payload['schedule_at'] ?? null;
        if (is_string($scheduledAt) && trim($scheduledAt) !== '') {
            try {
                $when = Carbon::parse($scheduledAt);
                if ($when->lt(now()->subMinute())) {
                    $errors[] = 'Scheduled time must be in the future.';
                }
            } catch (\Throwable) {
                $errors[] = 'Scheduled time is invalid.';
            }
        }

        $isDelete = $tool === 'delete_campaign' || $tool === 'delete_resource' || (bool) ($payload['destructive'] ?? false);
        if ($isDelete && ! $this->hasDeleteScope($payload)) {
            $errors[] = 'Destructive plans require explicit delete scope/confirmation.';
        }

        if ((int) $user->id <= 0 || $organizationId <= 0) {
            $errors[] = 'Invalid tenant scope.';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hasDeleteScope(array $payload): bool
    {
        if (! empty($payload['confirm_delete'])
            || ! empty($payload['delete_created_today'])
            || ! empty($payload['delete_all_outreach'])
            || ! empty($payload['delete_all_campaigns'])
            || ! empty($payload['delete_all_lead_lists'])) {
            return true;
        }

        if (! empty($payload['items']) && is_array($payload['items'])) {
            return true;
        }

        if (! empty($payload['campaign_id']) || ! empty($payload['campaign_ids'])) {
            return true;
        }

        $type = (string) ($payload['type'] ?? '');
        if (in_array($type, ['bulk_delete', 'resource_delete', 'campaign_delete'], true)) {
            if ($type === 'bulk_delete') {
                return ! empty($payload['items']);
            }

            return ! empty($payload['resource_id']) || ! empty($payload['campaign_id']);
        }

        return ! empty($payload['resource_id']) && ! empty($payload['kind']);
    }
}
