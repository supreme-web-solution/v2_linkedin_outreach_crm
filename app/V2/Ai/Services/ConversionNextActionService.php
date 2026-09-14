<?php

namespace App\V2\Ai\Services;

use App\Models\User;
use App\Models\V2OutreachLead;

/**
 * Deterministic inbox brain: qualify → one conversion asset → meeting (last card).
 *
 * Prompt text is not enough — this decides which tool/URL the reply must use.
 */
class ConversionNextActionService
{
    public const ACTION_OPT_OUT = 'opt_out';

    public const ACTION_QUALIFY = 'qualify';

    public const ACTION_SHARE_SALES_PAGE = 'share_sales_page';

    public const ACTION_SHARE_WEBINAR = 'share_webinar';

    public const ACTION_BOOK_MEETING = 'book_meeting';

    public const ACTION_CONFIRM_MEETING = 'confirm_meeting';

    public const ACTION_NURTURE = 'nurture';

    public function __construct(
        private readonly InboxClassificationService $classifier,
        private readonly ConversionStageService $stages,
        private readonly WorkspaceContextService $workspace,
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly ProspectMemoryService $memory,
    ) {}

    /**
     * @param  array<string, mixed>|null  $classification
     * @return array{
     *     action: string,
     *     tool: string|null,
     *     asset_type: string|null,
     *     asset_url: string|null,
     *     must_include_url: bool,
     *     forbid_links: bool,
     *     buying_signal: string,
     *     intent: string,
     *     stage: string,
     *     guide: string
     * }
     */
    public function decide(
        User $user,
        int $organizationId,
        string $inboundBody,
        ?V2OutreachLead $lead = null,
        ?array $classification = null,
    ): array {
        $settings = $this->settingsService->for($user, $organizationId);
        $assets = $this->workspace->conversionAssets($settings);
        $meeting = $this->workspace->resolveMeetingLink($user, $settings);
        $stage = $lead ? $this->stages->current($lead) : ConversionStageService::STAGE_OPENING;
        $offered = [];
        if ($lead) {
            $dossier = $this->memory->dossier($lead);
            $offered = is_array($dossier['offered_assets'] ?? null) ? $dossier['offered_assets'] : [];
        }

        $classification ??= $this->classifier->classify($inboundBody);

        return $this->decideFromState($stage, $classification, [
            'sales_page_url' => trim((string) ($assets['sales_page_url'] ?? '')),
            'webinar_url' => trim((string) ($assets['webinar_url'] ?? '')),
            'meeting_link' => $meeting,
        ], $offered);
    }

    /**
     * Pure decision used by tests and decide().
     *
     * @param  array<string, mixed>  $classification
     * @param  array{sales_page_url?: string, webinar_url?: string, meeting_link?: string|null}  $assets
     * @param  list<string>  $offeredAssets
     * @return array{
     *     action: string,
     *     tool: string|null,
     *     asset_type: string|null,
     *     asset_url: string|null,
     *     must_include_url: bool,
     *     forbid_links: bool,
     *     buying_signal: string,
     *     intent: string,
     *     stage: string,
     *     guide: string
     * }
     */
    public function decideFromState(
        string $stage,
        array $classification,
        array $assets,
        array $offeredAssets = [],
    ): array {
        $intent = (string) ($classification['intent'] ?? 'neutral');
        $buying = (string) ($classification['buying_signal'] ?? 'none');
        $sales = trim((string) ($assets['sales_page_url'] ?? ''));
        $webinar = trim((string) ($assets['webinar_url'] ?? ''));
        $meeting = $assets['meeting_link'] ?? null;
        $hasMeeting = is_string($meeting) && $meeting !== '';
        $offeredSales = in_array('sales_page', $offeredAssets, true);
        $offeredWebinar = in_array('webinar', $offeredAssets, true);

        if ($intent === 'opt_out') {
            return $this->result(
                self::ACTION_OPT_OUT,
                $stage,
                $intent,
                $buying,
                guide: 'They asked to stop. Do not reply with a pitch or any link. Pause outreach.',
            );
        }

        if ($stage === ConversionStageService::STAGE_WON) {
            return $this->result(
                self::ACTION_NURTURE,
                $stage,
                $intent,
                $buying,
                guide: 'They are already a customer. Be helpful; no selling.',
            );
        }

        if ($stage === ConversionStageService::STAGE_OFFERED_MEETING) {
            return $this->result(
                self::ACTION_CONFIRM_MEETING,
                $stage,
                $intent,
                $buying,
                tool: 'draft_reply',
                guide: 'Meeting link was already shared. Confirm they received it, answer timing questions, do not re-pitch or dump more links.',
            );
        }

        if ($intent === 'meeting_request') {
            if ($hasMeeting) {
                return $this->bookMeeting($stage, $intent, $buying, $meeting);
            }

            return $this->result(
                self::ACTION_QUALIFY,
                $stage,
                $intent,
                $buying,
                tool: 'draft_reply',
                guide: 'They want to talk, but no booking link is configured. Propose two concrete time windows. No sales page or webinar unless they ask.',
            );
        }

        if ($stage === ConversionStageService::STAGE_OFFERED_ASSET) {
            $preference = $classification['asset_preference'] ?? null;
            if ($intent === 'wants_watch' || $preference === 'watch') {
                $other = $this->shareConfiguredAsset(
                    $stage,
                    $intent,
                    $buying,
                    $sales,
                    $webinar,
                    $offeredSales,
                    $offeredWebinar,
                    preferWatch: true,
                    unusedOnly: true,
                );
                if ($other !== null) {
                    return $other;
                }
            } elseif ($intent === 'wants_info' && $preference === 'read') {
                $other = $this->shareConfiguredAsset(
                    $stage,
                    $intent,
                    $buying,
                    $sales,
                    $webinar,
                    $offeredSales,
                    $offeredWebinar,
                    preferWatch: false,
                    unusedOnly: true,
                );
                if ($other !== null) {
                    return $other;
                }
            }

            if ($intent === 'objection') {
                return $this->result(
                    self::ACTION_NURTURE,
                    $stage,
                    $intent,
                    $buying,
                    tool: 'draft_reply',
                    guide: 'Hard no after the page/webinar. Acknowledge and leave the door open. No more links.',
                );
            }

            if ($hasMeeting && $intent !== 'unclear') {
                return $this->bookMeeting(
                    $stage,
                    $intent,
                    $buying,
                    $meeting,
                    'They already got the sales page or webinar and are cooling off (unsure, stalling, “I’ll think about it”, lukewarm thanks). Last card — suggest a short call to talk it through and include the booking link in the same message. Do not re-send the page/webinar.',
                );
            }

            return $this->result(
                self::ACTION_QUALIFY,
                $stage,
                $intent,
                $buying,
                tool: 'draft_reply',
                guide: 'They already received a page or webinar. No meeting link is configured — ask if a quick call would help and propose two time windows.',
            );
        }

        if (in_array($intent, ['wants_watch', 'wants_info', 'will_review'], true)
            || ($intent === 'interested' && $buying === 'hard')
        ) {
            $asset = $this->shareConfiguredAsset(
                $stage,
                $intent,
                $buying,
                $sales,
                $webinar,
                $offeredSales,
                $offeredWebinar,
                preferWatch: $intent === 'wants_watch',
                unusedOnly: false,
            );
            if ($asset !== null) {
                return $asset;
            }
            if ($hasMeeting) {
                return $this->bookMeeting($stage, $intent, $buying, $meeting);
            }
        }

        if (in_array($intent, ['timing', 'objection'], true)) {
            return $this->result(
                self::ACTION_NURTURE,
                $stage,
                $intent,
                $buying,
                tool: 'draft_reply',
                guide: 'Low-pressure. Acknowledge timing or the objection. Do not pitch or send links. Leave the door open.',
            );
        }

        return $this->qualify($stage, $intent, $buying);
    }

    /**
     * @param  array<string, mixed>  $classification
     * @param  array{sales_page_url?: string, webinar_url?: string, meeting_link?: string|null}  $assets
     * @param  list<string>  $offeredAssets
     */
    public function replyGuideForState(
        string $stage,
        array $classification,
        array $assets,
        array $offeredAssets = [],
    ): string {
        return $this->decideFromState($stage, $classification, $assets, $offeredAssets)['guide'];
    }

    /**
     * @return array{
     *     action: string,
     *     tool: string|null,
     *     asset_type: string|null,
     *     asset_url: string|null,
     *     must_include_url: bool,
     *     forbid_links: bool,
     *     buying_signal: string,
     *     intent: string,
     *     stage: string,
     *     guide: string
     * }
     */
    private function qualify(string $stage, string $intent, string $buying): array
    {
        $guide = match ($intent) {
            'qualifying_answer' => 'They answered how they get clients. Ask ONE follow-up about whether a more predictable pipeline matters — or how outbound is performing. No product name dump, no links, no calendar.',
            'interested' => 'Soft interest only — they have not asked for details yet. Ask ONE qualifying question about their situation. No pitch, no links.',
            default => 'Qualify with ONE situational question about how they get clients or what is not working. Earn permission before any pitch or link. No sales page, webinar, or meeting link.',
        };

        return $this->result(
            self::ACTION_QUALIFY,
            $stage,
            $intent,
            $buying,
            tool: 'draft_reply',
            forbidLinks: true,
            guide: $guide,
        );
    }

    /**
     * @return array{
     *     action: string,
     *     tool: string|null,
     *     asset_type: string|null,
     *     asset_url: string|null,
     *     must_include_url: bool,
     *     forbid_links: bool,
     *     buying_signal: string,
     *     intent: string,
     *     stage: string,
     *     guide: string
     * }
     */
    /**
     * Use whichever conversion URL the owner actually saved.
     * Sales page is preferred for read/info; webinar for watch — the other fills the gap.
     *
     * @return array<string, mixed>|null
     */
    private function shareConfiguredAsset(
        string $stage,
        string $intent,
        string $buying,
        string $sales,
        string $webinar,
        bool $offeredSales,
        bool $offeredWebinar,
        bool $preferWatch,
        bool $unusedOnly,
    ): ?array {
        $candidates = $preferWatch
            ? [
                ['type' => 'webinar', 'url' => $webinar, 'offered' => $offeredWebinar],
                ['type' => 'sales_page', 'url' => $sales, 'offered' => $offeredSales],
            ]
            : [
                ['type' => 'sales_page', 'url' => $sales, 'offered' => $offeredSales],
                ['type' => 'webinar', 'url' => $webinar, 'offered' => $offeredWebinar],
            ];

        foreach ($candidates as $candidate) {
            if ($candidate['url'] === '') {
                continue;
            }
            if ($unusedOnly && $candidate['offered']) {
                continue;
            }

            return $candidate['type'] === 'webinar'
                ? $this->shareWebinar($stage, $intent, $buying, $candidate['url'])
                : $this->shareSalesPage($stage, $intent, $buying, $candidate['url']);
        }

        return null;
    }

    private function shareSalesPage(string $stage, string $intent, string $buying, string $url): array
    {
        return $this->result(
            self::ACTION_SHARE_SALES_PAGE,
            $stage,
            $intent,
            $buying,
            tool: 'draft_reply',
            assetType: 'sales_page',
            assetUrl: $url,
            mustIncludeUrl: true,
            guide: 'They earned a look. Share this conversion URL (sales page — or the only asset on file). Include this exact URL: '.$url
                .' — one link only, no meeting link in the same message. Keep it short and specific to their situation.',
        );
    }

    /**
     * @return array{
     *     action: string,
     *     tool: string|null,
     *     asset_type: string|null,
     *     asset_url: string|null,
     *     must_include_url: bool,
     *     forbid_links: bool,
     *     buying_signal: string,
     *     intent: string,
     *     stage: string,
     *     guide: string
     * }
     */
    private function shareWebinar(string $stage, string $intent, string $buying, string $url): array
    {
        return $this->result(
            self::ACTION_SHARE_WEBINAR,
            $stage,
            $intent,
            $buying,
            tool: 'draft_reply',
            assetType: 'webinar',
            assetUrl: $url,
            mustIncludeUrl: true,
            guide: 'They want to watch or see how it works. Share this conversion URL (webinar — or the only asset on file). Include this exact URL: '.$url
                .' — one link only, no meeting link in the same message.',
        );
    }

    /**
     * @return array{
     *     action: string,
     *     tool: string|null,
     *     asset_type: string|null,
     *     asset_url: string|null,
     *     must_include_url: bool,
     *     forbid_links: bool,
     *     buying_signal: string,
     *     intent: string,
     *     stage: string,
     *     guide: string
     * }
     */
    private function bookMeeting(string $stage, string $intent, string $buying, string $meeting, ?string $guide = null): array
    {
        $url = $meeting === 'app_booking' ? null : $meeting;
        $must = is_string($url) && $url !== '';

        return $this->result(
            self::ACTION_BOOK_MEETING,
            $stage,
            $intent,
            $buying,
            tool: 'book_meeting',
            assetType: 'meeting',
            assetUrl: $meeting,
            mustIncludeUrl: $must,
            guide: $guide ?? 'Last card — they asked to talk, or they are cooling off after the page/webinar. Use book_meeting. Suggest a short call and include the booking URL in the same message. No extra pitch.',
        );
    }

    /**
     * @return array{
     *     action: string,
     *     tool: string|null,
     *     asset_type: string|null,
     *     asset_url: string|null,
     *     must_include_url: bool,
     *     forbid_links: bool,
     *     buying_signal: string,
     *     intent: string,
     *     stage: string,
     *     guide: string
     * }
     */
    private function result(
        string $action,
        string $stage,
        string $intent,
        string $buying,
        ?string $tool = null,
        ?string $assetType = null,
        ?string $assetUrl = null,
        bool $mustIncludeUrl = false,
        bool $forbidLinks = false,
        string $guide = '',
    ): array {
        return [
            'action' => $action,
            'tool' => $tool,
            'asset_type' => $assetType,
            'asset_url' => $assetUrl,
            'must_include_url' => $mustIncludeUrl,
            'forbid_links' => $forbidLinks,
            'buying_signal' => $buying,
            'intent' => $intent,
            'stage' => $stage,
            'guide' => $guide,
        ];
    }
}
