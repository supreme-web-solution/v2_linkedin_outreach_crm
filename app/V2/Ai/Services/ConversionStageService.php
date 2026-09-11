<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2OutreachLead;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Tracks where a prospect is on the qualify → asset → meeting ladder.
 */
class ConversionStageService
{
    public const STAGE_OPENING = 'opening';

    public const STAGE_QUALIFYING = 'qualifying';

    public const STAGE_OFFERED_ASSET = 'offered_asset';

    public const STAGE_OFFERED_MEETING = 'offered_meeting';

    public const STAGE_WON = 'won';

    public function __construct(
        private readonly ProspectMemoryService $memory,
        private readonly WorkspaceContextService $workspace,
    ) {}

    public function current(V2OutreachLead $lead): string
    {
        $dossier = $this->memory->dossier($lead);
        $stage = trim((string) ($dossier['conversion_stage'] ?? ''));

        if ($stage !== '' && $this->isValidStage($stage)) {
            return $stage;
        }

        $qualStage = trim((string) Arr::get($lead->meta ?? [], 'qualification.stage', ''));
        if ($qualStage === 'customer') {
            return self::STAGE_WON;
        }

        if (in_array($qualStage, ['meeting_booked', 'sql', 'qualified'], true)) {
            return self::STAGE_OFFERED_MEETING;
        }

        return self::STAGE_OPENING;
    }

    public function advanceOnInbound(V2OutreachLead $lead): string
    {
        $current = $this->current($lead);

        if ($current === self::STAGE_WON) {
            return $current;
        }

        if ($current === self::STAGE_OPENING) {
            $this->memory->setConversionStage($lead, self::STAGE_QUALIFYING);

            return self::STAGE_QUALIFYING;
        }

        return $current;
    }

    public function recordOutbound(V2OutreachLead $lead, string $body, User $user, int $organizationId): string
    {
        $body = Str::lower($body);
        $settings = app(AiEmployeeSettingsService::class)->for($user, $organizationId);
        $assets = $this->workspace->conversionAssets($settings);
        $sales = trim((string) ($assets['sales_page_url'] ?? ''));
        $webinar = trim((string) ($assets['webinar_url'] ?? ''));
        $meeting = $this->workspace->resolveMeetingLink($user, $settings);

        $current = $this->current($lead);
        $next = $current;

        if ($meeting !== null && $this->bodyContainsMeetingLink($body, $meeting)) {
            $next = self::STAGE_OFFERED_MEETING;
        } elseif (
            ($sales !== '' && str_contains($body, Str::lower($sales)))
            || ($webinar !== '' && str_contains($body, Str::lower($webinar)))
        ) {
            $next = self::STAGE_OFFERED_ASSET;
        }

        if ($next !== $current) {
            $this->memory->setConversionStage($lead, $next);
        }

        return $next;
    }

    public function replyGuide(V2OutreachLead $lead, User $user, int $organizationId): string
    {
        $stage = $this->current($lead);

        return match ($stage) {
            self::STAGE_OPENING => 'Stage: opening — if this is their first reply, ask ONE qualifying question about their situation. No pitch, no links.',
            self::STAGE_QUALIFYING => 'Stage: qualifying — dig into pain, current approach, and goals. Only move to an asset when they show genuine interest.',
            self::STAGE_OFFERED_ASSET => 'Stage: offered asset — they saw a sales page or webinar link. Check if it resonated; do not re-send the same link unless they ask.',
            self::STAGE_OFFERED_MEETING => 'Stage: offered meeting — meeting link was shared. Confirm timing or answer objections; do not pitch again.',
            self::STAGE_WON => 'Stage: won — treat as customer/nurture appropriately; no hard selling.',
            default => '',
        };
    }

    private function bodyContainsMeetingLink(string $body, string $meeting): bool
    {
        if ($meeting === 'app_booking') {
            return str_contains($body, 'book')
                || str_contains($body, 'calendar')
                || str_contains($body, '/calls')
                || str_contains($body, 'meeting');
        }

        return str_contains($body, Str::lower($meeting));
    }

    private function isValidStage(string $stage): bool
    {
        return in_array($stage, [
            self::STAGE_OPENING,
            self::STAGE_QUALIFYING,
            self::STAGE_OFFERED_ASSET,
            self::STAGE_OFFERED_MEETING,
            self::STAGE_WON,
        ], true);
    }
}
