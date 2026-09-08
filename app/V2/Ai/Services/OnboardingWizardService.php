<?php

namespace App\V2\Ai\Services;

use App\Models\AiChannelIdentity;
use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\V2\Ai\Integrations\ZernioClient;
use App\V2\Outreach\OutreachChannelGuard;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\CallCalendarService;
use App\V2\Services\ChannelConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OnboardingWizardService
{
    /** @var array<string, array<string, mixed>> */
    private const GOALS = [
        'book_meetings' => [
            'label' => 'Book more meetings',
            'prompt' => 'Book 20 meetings with US SaaS founders this month',
            'required_channels' => ['linkedin', 'email'],
            'optional_channels' => ['google_calendar', 'outlook_calendar'],
            'featured' => true,
        ],
        'build_linkedin_audience' => [
            'label' => 'Build LinkedIn audience',
            'prompt' => 'Build a LinkedIn audience of ~100 ideal prospects via search, then prepare outreach',
            'required_channels' => ['linkedin'],
            'optional_channels' => ['email'],
            'featured' => true,
        ],
        'reactivate_old_leads' => [
            'label' => 'Reactivate old leads',
            'prompt' => "Who's due for nurture follow-up? Help me reactivate cold and past leads",
            'required_channels' => ['linkedin', 'email'],
            'optional_channels' => ['whatsapp'],
            'featured' => true,
        ],
        'follow_up' => [
            'label' => 'Follow up with replies',
            'prompt' => 'Who needs my attention? Draft replies for hot leads',
            'required_channels' => ['linkedin', 'email'],
            'optional_channels' => ['whatsapp', 'instagram', 'telegram'],
            'featured' => true,
        ],
        'multichannel_outreach' => [
            'label' => 'Multichannel outreach',
            'prompt' => 'Launch multichannel outreach',
            'required_channels' => ['linkedin', 'email'],
            'optional_channels' => ['whatsapp', 'instagram', 'telegram'],
            'featured' => true,
        ],
        // Available via free-text / chat — not shown as quick picks (keeps the wizard simple).
        'find_prospects' => [
            'label' => 'Find ideal prospects',
            'prompt' => 'Find my ideal customers and build a prospect list',
            'required_channels' => ['linkedin'],
            'optional_channels' => ['email'],
            'featured' => false,
        ],
        'competitor_audience' => [
            'label' => "Reach competitors' audience",
            'prompt' => 'Find people engaging with my competitors and start outreach',
            'required_channels' => ['linkedin'],
            'optional_channels' => ['email'],
            'featured' => false,
        ],
        'whatsapp_outreach' => [
            'label' => 'WhatsApp outreach campaign',
            'prompt' => 'Run a WhatsApp outreach campaign',
            'required_channels' => ['whatsapp'],
            'optional_channels' => ['linkedin', 'email'],
            'featured' => false,
        ],
        'instagram_outreach' => [
            'label' => 'Instagram outreach',
            'prompt' => 'Run Instagram DM outreach',
            'required_channels' => ['instagram'],
            'optional_channels' => ['linkedin', 'email'],
            'featured' => false,
        ],
        'telegram_outreach' => [
            'label' => 'Telegram outreach',
            'prompt' => 'Run Telegram outreach',
            'required_channels' => ['telegram'],
            'optional_channels' => ['linkedin', 'email'],
            'featured' => false,
        ],
    ];

    public function __construct(
        private readonly AiEmployeeSettingsService $settingsService,
        private readonly ChannelConnectionService $channels,
        private readonly OutreachChannelGuard $guard,
        private readonly CallCalendarService $calendar,
        private readonly ZernioClient $zernio,
        private readonly AiChannelPolicyService $channelPolicy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(User $user, int $organizationId): array
    {
        $settings = $this->settingsService->for($user, $organizationId);
        $meta = $this->onboardingMeta($settings);
        $goalKey = (string) ($meta['goal'] ?? '');
        $goal = $this->resolvedGoal($goalKey, $meta);
        $connections = $goal ? $this->connectionSteps($user, $organizationId, $goal, $meta) : [];
        $outreachReady = $goal ? $this->outreachChannelsReady($user, $goal, $meta) : false;
        $waCommand = $this->whatsAppCommandStatus($user, $organizationId);
        $skipWaCommand = (bool) ($meta['skip_whatsapp_command'] ?? false);

        $phase = 'ask';
        if ($goalKey !== '') {
            $phase = ! $outreachReady ? 'connect_outreach' : ((! $waCommand['linked'] && ! $skipWaCommand) ? 'link_whatsapp' : 'ready');
        }

        return [
            'show' => $this->shouldShow($settings, $user, $organizationId),
            'completed' => $this->isComplete($settings),
            'step' => (string) ($meta['step'] ?? 'welcome'),
            'phase' => $phase,
            'goal' => $goalKey !== '' ? $goalKey : null,
            'goal_label' => $goal['label'] ?? null,
            'custom_goal' => $meta['custom_goal'] ?? null,
            'starter_prompt' => $meta['custom_goal'] ?? ($goal['prompt'] ?? null),
            'employee_name' => $settings->employee_name,
            'goal_options' => collect(self::GOALS)
                ->filter(fn ($g) => (bool) ($g['featured'] ?? false))
                ->sortBy(fn ($g, $key) => match ($key) {
                    'book_meetings' => 0,
                    'build_linkedin_audience' => 1,
                    'reactivate_old_leads' => 2,
                    'follow_up' => 3,
                    'multichannel_outreach' => 4,
                    default => 10,
                })
                ->map(fn ($g, $key) => [
                    'key' => $key,
                    'label' => $g['label'],
                ])->values()->all(),
            'connections' => $connections,
            'whatsapp_command' => $waCommand,
            'skip_whatsapp_command' => $skipWaCommand,
            'outreach_ready' => $outreachReady,
            'show_whatsapp_command' => $goalKey !== '' && $outreachReady && ! $skipWaCommand,
            'ready' => $goal ? $this->isReady($user, $organizationId, $goalKey, $goal, $meta) : false,
            'can_open_command_center' => $outreachReady,
            'workspace_configured' => (bool) ($meta['workspace_configured_at'] ?? false),
            'autonomy_level' => (int) $settings->autonomy_level,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function selectGoal(User $user, int $organizationId, string $goalKey, ?string $customGoal = null): array
    {
        if (! isset(self::GOALS[$goalKey])) {
            throw new \InvalidArgumentException('Unknown goal.');
        }

        return $this->applyGoal($user, $organizationId, $goalKey, $customGoal);
    }

    /**
     * @return array{role:string, content:string, actions?:list<array<string,string>>}
     */
    public function chatTurn(User $user, int $organizationId, string $message): array
    {
        $settings = $this->settingsService->for($user, $organizationId);
        $meta = $this->onboardingMeta($settings);
        $normalized = Str::lower(trim($message));
        $name = $settings->employee_name;

        if ($this->wantsToSkipWhatsappCommand($normalized)) {
            $this->saveMeta($settings, ['skip_whatsapp_command' => true]);

            return [
                'role' => 'assistant',
                'content' => "No problem — you can control me from the web Command Center. Tap **Open Command Center** when your outreach connections are ready.",
            ];
        }

        if ($normalized !== '' && ! in_array($normalized, ['hi', 'hello', 'start'], true)) {
            $inferred = $this->inferGoalFromMessage($message);
            if ($inferred !== null) {
                $this->applyGoal($user, $organizationId, $inferred['key'], $message, $inferred['overrides'] ?? []);

                return [
                    'role' => 'assistant',
                    'content' => $inferred['reply'],
                ];
            }

            $mentioned = $this->channelPolicy->mentionedInText($normalized);
            $wantsCampaign = str_contains($normalized, 'campaign')
                || str_contains($normalized, 'outreach')
                || (str_contains($normalized, 'run') && $this->channelPolicy->mentionedInText($normalized) !== []);

            if ($wantsCampaign && count($mentioned) === 0) {
                return [
                    'role' => 'assistant',
                    'content' => "Which channel should we run on — **WhatsApp**, **LinkedIn**, **Instagram**, or **multichannel**? Say it in one line and I'll adjust the checklist.",
                ];
            }
        }

        $goalKey = (string) ($meta['goal'] ?? '');
        if ($goalKey === '' || in_array($normalized, ['hi', 'hello', 'start'], true) || $normalized === '') {
            $this->saveMeta($settings, ['step' => 'goal']);

            return [
                'role' => 'assistant',
                'content' => "Hey — I'm **{$name}**.\n\nWhat do you want SociFusion to do? Pick one below, or type your own (e.g. *WhatsApp campaign*, *competitors' audience*).",
            ];
        }

        $state = $this->status($user, $organizationId);

        if ($state['ready']) {
            return [
                'role' => 'assistant',
                'content' => "You're set. Open Command Center and I'll continue from your goal.",
            ];
        }

        if ($state['show_whatsapp_command'] && ! ($state['whatsapp_command']['linked'] ?? false)) {
            return [
                'role' => 'assistant',
                'content' => "Last step — link **WhatsApp** so you can talk to me from your phone (same Alex, same plans).\n\nTap **Connect** on *WhatsApp — control Alex* below, or say *skip whatsapp* if you only want the web app.",
            ];
        }

        $missing = collect($state['connections'] ?? [])
            ->filter(fn ($c) => ($c['required'] ?? false) && ! ($c['connected'] ?? false) && ($c['kind'] ?? '') !== 'whatsapp_command')
            ->pluck('label')
            ->implode(', ');

        if ($missing !== '') {
            return [
                'role' => 'assistant',
                'content' => "For **{$state['goal_label']}**, connect: **{$missing}**. I'll update automatically when you're done.",
            ];
        }

        return [
            'role' => 'assistant',
            'content' => 'Connect the items in the checklist below, then link WhatsApp to control me from your phone — or skip that step.',
        ];
    }

    public function skipWhatsappCommand(User $user, int $organizationId): array
    {
        $settings = $this->settingsService->for($user, $organizationId);
        $this->saveMeta($settings, ['skip_whatsapp_command' => true]);

        return $this->status($user, $organizationId);
    }

    public function complete(User $user, int $organizationId): void
    {
        $settings = $this->settingsService->for($user, $organizationId);
        $meta = $this->onboardingMeta($settings);
        $meta['completed_at'] = now()->toIso8601String();
        $meta['step'] = 'done';
        $this->saveMeta($settings, $meta);
    }

    public function dismiss(User $user, int $organizationId): void
    {
        $settings = $this->settingsService->for($user, $organizationId);
        $this->saveMeta($settings, [
            'dismissed_at' => now()->toIso8601String(),
            'step' => 'dismissed',
        ]);
    }

    /**
     * @return array{redirect_url:string}
     */
    public function connectUrl(User $user, int $organizationId, string $channelKey, ?Request $request = null): array
    {
        if (! OutreachChannelRegistry::isEnabled($channelKey)) {
            throw new \InvalidArgumentException("Channel {$channelKey} is not enabled.");
        }

        $link = $this->channels->createHostedAuthLink(
            $user,
            $channelKey,
            $request,
            '/dashboard?onboarding=1&connected=1&channel='.$channelKey,
            '/dashboard?onboarding=1&error=1&channel='.$channelKey,
        );

        return [
            'redirect_url' => (string) ($link['url'] ?? $link['link'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function applyGoal(
        User $user,
        int $organizationId,
        string $goalKey,
        ?string $customGoal = null,
        array $overrides = [],
    ): array {
        $patch = [
            'goal' => $goalKey,
            'custom_goal' => $customGoal ? trim($customGoal) : null,
            'step' => 'connect',
            'started_at' => now()->toIso8601String(),
            'workspace_configured_at' => now()->toIso8601String(),
        ];

        if ($overrides !== []) {
            $patch['channel_overrides'] = $overrides;
        }

        $this->settingsService->configureWorkspaceForGoal($user, $organizationId, $goalKey);
        $settings = $this->settingsService->for($user, $organizationId);
        $this->saveMeta($settings, $patch);

        return $this->status($user, $organizationId);
    }

    /**
     * @return array{key:string, reply:string, overrides?:array<string,mixed>}|null
     */
    private function inferGoalFromMessage(string $message): ?array
    {
        $hay = Str::lower(trim($message));
        if ($hay === '') {
            return null;
        }

        foreach (self::GOALS as $key => $goal) {
            if ($hay === $key || str_contains($hay, Str::lower($goal['label']))) {
                return [
                    'key' => $key,
                    'reply' => $this->goalAckReply($goal['label'], $this->channelSummary($goal)),
                ];
            }
        }

        $mentioned = $this->channelPolicy->mentionedInText($hay);
        $has = fn (string $ch): bool => in_array($ch, $mentioned, true);

        $wantsMeeting = str_contains($hay, 'meeting') || str_contains($hay, 'book a call') || str_contains($hay, 'demo');
        $wantsCompetitor = str_contains($hay, 'competitor');
        $wantsReactivate = str_contains($hay, 'reactivat') || str_contains($hay, 'cold lead') || str_contains($hay, 'old lead') || str_contains($hay, 'nurture');
        $wantsFollowUp = str_contains($hay, 'follow up') || str_contains($hay, 'inbox') || str_contains($hay, 'repl');
        $wantsAudience = str_contains($hay, 'linkedin audience') || str_contains($hay, 'build audience') || str_contains($hay, 'grow audience');
        $wantsFind = str_contains($hay, 'find') || str_contains($hay, 'prospect') || str_contains($hay, 'lead list');
        $wantsCampaign = str_contains($hay, 'campaign') || str_contains($hay, 'outreach');
        $wantsRun = str_contains($hay, 'run') && ($wantsCampaign || count($mentioned) > 0);

        if ($has('whatsapp') && ($wantsCampaign || $wantsRun || $wantsFind || ! $wantsMeeting)) {
            return [
                'key' => 'whatsapp_outreach',
                'reply' => $this->goalAckReply('WhatsApp outreach campaign', '**WhatsApp** for sending to prospects'.($has('linkedin') || $has('email') ? ' (LinkedIn/Email optional backup)' : '')),
            ];
        }

        if ($has('instagram')) {
            return [
                'key' => 'instagram_outreach',
                'reply' => $this->goalAckReply('Instagram outreach', '**Instagram** for DMs'.($has('linkedin') ? ', LinkedIn optional' : '')),
            ];
        }

        if ($has('telegram')) {
            return [
                'key' => 'telegram_outreach',
                'reply' => $this->goalAckReply('Telegram outreach', '**Telegram** for messaging'.($has('linkedin') ? ', LinkedIn optional backup' : '')),
            ];
        }

        if ($wantsCompetitor) {
            return [
                'key' => 'competitor_audience',
                'reply' => $this->goalAckReply("competitors' audience", '**LinkedIn** to harvest and reach engagers'),
            ];
        }

        if ($wantsReactivate) {
            return [
                'key' => 'reactivate_old_leads',
                'reply' => $this->goalAckReply('reactivating old leads', '**LinkedIn + Email** — nurture queue + follow-ups'),
            ];
        }

        if ($wantsFollowUp) {
            return [
                'key' => 'follow_up',
                'reply' => $this->goalAckReply('inbox follow-up', '**LinkedIn + Email** (and WhatsApp if you reply there)'),
            ];
        }

        if ($wantsMeeting) {
            return [
                'key' => 'book_meetings',
                'reply' => $this->goalAckReply('booking meetings', '**LinkedIn + Email** for outreach, calendar for booking links'),
            ];
        }

        if ($wantsAudience || ($wantsFind && $has('linkedin'))) {
            return [
                'key' => 'build_linkedin_audience',
                'reply' => $this->goalAckReply('building a LinkedIn audience', '**LinkedIn** to search and grow a list'),
            ];
        }

        if ($wantsFind) {
            return [
                'key' => 'build_linkedin_audience',
                'reply' => $this->goalAckReply('finding prospects', '**LinkedIn** to start — Email optional'),
            ];
        }

        if ($wantsCampaign || $wantsRun) {
            return [
                'key' => 'multichannel_outreach',
                'reply' => $this->goalAckReply('outreach campaign', '**LinkedIn + Email** by default — say Instagram, Telegram, or WhatsApp to lead with that channel'),
            ];
        }

        if (count($mentioned) >= 2) {
            return [
                'key' => 'multichannel_outreach',
                'reply' => $this->goalAckReply('multichannel outreach', $this->channelSummary(self::GOALS['multichannel_outreach'])),
            ];
        }

        return null;
    }

    private function goalAckReply(string $label, string $needs): string
    {
        return "Got it — **{$label}**.\n\nYou'll need {$needs}. Connect below — I'll watch for completion.\n\nAfter that, link WhatsApp to control me from your phone (optional).";
    }

    /**
     * @param  array<string, mixed>  $goal
     */
    private function channelSummary(array $goal): string
    {
        $required = collect($goal['required_channels'] ?? [])
            ->filter(fn ($k) => OutreachChannelRegistry::isEnabled($k))
            ->map(fn ($k) => '**'.OutreachChannelRegistry::channelLabel($k).'**')
            ->implode(' + ');

        return $required !== '' ? $required : 'your outreach channels';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function resolvedGoal(string $goalKey, array $meta): array
    {
        $base = self::GOALS[$goalKey] ?? [];
        $overrides = is_array($meta['channel_overrides'] ?? null) ? $meta['channel_overrides'] : [];

        return array_merge($base, array_filter([
            'required_channels' => $overrides['required_channels'] ?? null,
            'optional_channels' => $overrides['optional_channels'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /**
     * @param  array<string, mixed>  $goal
     * @param  array<string, mixed>  $meta
     * @return list<array<string, mixed>>
     */
    private function connectionSteps(User $user, int $organizationId, array $goal, array $meta): array
    {
        $steps = [];
        $outreachReady = $this->outreachChannelsReady($user, $goal, $meta);

        foreach ($goal['required_channels'] ?? [] as $channelKey) {
            if (! OutreachChannelRegistry::isEnabled($channelKey)) {
                continue;
            }

            $steps[] = $this->connectionRow($user, $channelKey, true);
        }

        foreach ($goal['optional_channels'] ?? [] as $channelKey) {
            if (! OutreachChannelRegistry::isEnabled($channelKey)) {
                continue;
            }

            $steps[] = $this->connectionRow($user, $channelKey, false);
        }

        $goalKey = (string) ($meta['goal'] ?? '');
        if ($goalKey === 'book_meetings' && ! ($meta['skip_calendar'] ?? false) && ! $this->calendar->isAvailable($user->id)) {
            foreach (['google_calendar', 'outlook_calendar'] as $cal) {
                if (! OutreachChannelRegistry::isEnabled($cal)) {
                    continue;
                }
                $steps[] = [
                    'key' => $cal,
                    'label' => OutreachChannelRegistry::channelLabel($cal),
                    'connected' => $this->isChannelConnected($user, $cal),
                    'required' => false,
                    'kind' => 'calendar',
                    'phase' => 'outreach',
                ];
            }
        }

        if (! ($meta['skip_whatsapp_command'] ?? false)) {
            $wa = $this->whatsAppCommandStatus($user, $organizationId);
            $steps[] = [
                'key' => 'whatsapp_command',
                'label' => 'WhatsApp — control Alex from your phone',
                'connected' => $wa['linked'],
                'required' => false,
                'kind' => 'whatsapp_command',
                'phase' => 'climax',
                'highlight' => ! $wa['linked'] && $outreachReady,
            ];
        }

        return $steps;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionRow(User $user, string $channelKey, bool $required): array
    {
        return [
            'key' => $channelKey,
            'label' => OutreachChannelRegistry::channelLabel($channelKey),
            'connected' => $this->isChannelConnected($user, $channelKey),
            'required' => $required,
            'kind' => in_array($channelKey, ['google_calendar', 'outlook_calendar'], true) ? 'calendar' : 'integration',
            'phase' => 'outreach',
        ];
    }

    /**
     * @param  array<string, mixed>  $goal
     * @param  array<string, mixed>  $meta
     */
    private function outreachChannelsReady(User $user, array $goal, array $meta): bool
    {
        foreach ($goal['required_channels'] ?? [] as $channelKey) {
            if (! OutreachChannelRegistry::isEnabled($channelKey)) {
                continue;
            }
            if (! $this->isChannelConnected($user, $channelKey)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $goal
     * @param  array<string, mixed>  $meta
     */
    private function isReady(User $user, int $organizationId, string $goalKey, array $goal, array $meta): bool
    {
        if (! $this->outreachChannelsReady($user, $goal, $meta)) {
            return false;
        }

        if ($goalKey === 'book_meetings' && ! ($meta['skip_calendar'] ?? false)) {
            if (! $this->calendar->isAvailable($user->id)) {
                return false;
            }
        }

        $skipWa = (bool) ($meta['skip_whatsapp_command'] ?? false);
        if (! $skipWa && ! $this->whatsAppCommandStatus($user, $organizationId)['linked']) {
            return false;
        }

        return true;
    }

    private function wantsToSkipWhatsappCommand(string $normalized): bool
    {
        return str_contains($normalized, 'skip whatsapp')
            || str_contains($normalized, 'skip wa')
            || str_contains($normalized, "don't want whatsapp")
            || str_contains($normalized, 'no whatsapp')
            || str_contains($normalized, 'without whatsapp')
            || str_contains($normalized, 'web only');
    }

    private function isChannelConnected(User $user, string $channelKey): bool
    {
        if (in_array($channelKey, ['google_calendar', 'outlook_calendar'], true)) {
            return $this->calendar->isAvailable($user->id)
                || $this->guard->isChannelConnected($user->id, $channelKey);
        }

        return $this->guard->isChannelConnected($user->id, $channelKey);
    }

    /**
     * @return array{linked:bool, phone:?string, configured:bool}
     */
    private function whatsAppCommandStatus(User $user, int $organizationId): array
    {
        $identity = AiChannelIdentity::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organizationId)
            ->where('channel', 'whatsapp')
            ->where('status', 'active')
            ->first();

        return [
            'linked' => (bool) $identity,
            'phone' => $identity?->external_id,
            'configured' => $this->zernio->configured(),
        ];
    }

    private function shouldShow(AiEmployeeSetting $settings, User $user, int $organizationId): bool
    {
        if (! $organizationId || ! $user->current_organization_id) {
            return false;
        }

        return ! $this->isComplete($settings);
    }

    private function isComplete(AiEmployeeSetting $settings): bool
    {
        return ! empty($this->onboardingMeta($settings)['completed_at']);
    }

    /**
     * @return array<string, mixed>
     */
    private function onboardingMeta(AiEmployeeSetting $settings): array
    {
        $root = is_array($settings->meta) ? $settings->meta : [];

        return is_array($root['onboarding'] ?? null) ? $root['onboarding'] : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function saveMeta(AiEmployeeSetting $settings, array $patch): void
    {
        $root = is_array($settings->meta) ? $settings->meta : [];
        $root['onboarding'] = array_merge($this->onboardingMeta($settings), $patch);
        $settings->update(['meta' => $root]);
    }
}
