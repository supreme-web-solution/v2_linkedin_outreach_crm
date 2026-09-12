<?php

namespace App\V2\Ai\Services;

use App\Models\V2OutreachCampaign;
use App\V2\Ai\Support\IcpSearchFilterParser;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Str;

/**
 * Turns Soci plan sequence prose / structured steps into an executable outreach node_model.
 * Falls back to channel presets when the plan does not describe a usable sequence.
 * Always maps actions onto OutreachChannelRegistry-supported keys (e.g. connect → send_invite).
 *
 * LinkedIn: after send_invite, nest follow-up DMs under invite_accepted (never a second invite
 * or blind waits pretending to mean “accepted”).
 */
class PlanSequenceNodeBuilder
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{template_type:string, node_model:list<array<string,mixed>>, custom:bool}
     */
    public function resolve(array $payload): array
    {
        if ($this->isOneShotIntent($payload)) {
            return [
                'template_type' => 'custom',
                'node_model' => $this->ensureEndNode($this->rekeyNodes($this->buildOneShotNodes($payload))),
                'custom' => true,
            ];
        }

        $fallbackType = app(CampaignDraftFromPlanService::class)->resolveTemplateType($payload);
        $templates = V2OutreachCampaign::templates();
        $fallbackNodes = $templates[$fallbackType]['node_model']
            ?? $templates['linkedin_email']['node_model'];

        if ($this->isValidNodeModel($payload['node_model'] ?? null)) {
            $nodes = $this->finalizeNodes(
                $this->normalizeNodes(array_values($payload['node_model'])),
                $payload,
            );

            return [
                'template_type' => 'custom',
                'node_model' => $nodes,
                'custom' => true,
            ];
        }

        $structured = $payload['sequence_steps'] ?? null;
        if (is_array($structured) && $structured !== []) {
            $built = $this->fromStructuredSteps($structured, $payload);
            if ($built !== []) {
                return [
                    'template_type' => 'custom',
                    'node_model' => $this->finalizeNodes($this->normalizeNodes($built), $payload),
                    'custom' => true,
                ];
            }
        }

        $prose = $payload['sequence'] ?? null;
        if (is_array($prose) && count($prose) >= 2) {
            $built = $this->fromProseSequence($prose, $payload);
            if (count($built) >= 2) {
                return [
                    'template_type' => 'custom',
                    'node_model' => $this->finalizeNodes($this->normalizeNodes($built), $payload),
                    'custom' => true,
                ];
            }
        }

        $fallbackNodes = $this->maybeAdaptFallbackForFirstDegree($fallbackNodes, $payload);

        return [
            'template_type' => $fallbackType,
            'node_model' => $fallbackNodes,
            'custom' => false,
        ];
    }

    /**
     * One greeting / one email / one DM — never inject Wait N days or follow-ups.
     *
     * @param  array<string, mixed>  $payload
     */
    public function isOneShotIntent(array $payload): bool
    {
        if (! empty($payload['one_shot']) || ! empty($payload['one_time']) || ($payload['sequence_mode'] ?? '') === 'one_shot') {
            return true;
        }

        $blob = Str::lower(trim(implode(' ', array_filter([
            (string) ($payload['goal'] ?? ''),
            (string) ($payload['audience'] ?? ''),
            (string) ($payload['message'] ?? ''),
            is_array($payload['sequence'] ?? null) ? implode(' ', $payload['sequence']) : '',
        ]))));

        return (bool) preg_match(
            '/\b(one[-\s]?time|one[-\s]?shot|single[-\s]?(email|message|dm|greeting|send)|just\s+(a\s+)?(greeting|hello|message|email)|greeting(\s+(message|only))?|hello[, ]+good\s+evening|message\s+(him|her|them)|send\s+(him|her|them)\s+a\s+(message|greeting|hello)|no\s+follow[-\s]?ups?|without\s+follow[-\s]?up)\b/i',
            $blob,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function buildOneShotNodes(array $payload): array
    {
        $channel = $this->primaryChannel($payload);
        $message = trim((string) (
            $payload['message']
            ?? $payload['draft_text']
            ?? $payload['body']
            ?? ''
        ));
        if ($message === '' && is_array($payload['sequence'] ?? null)) {
            foreach ($payload['sequence'] as $line) {
                $line = trim((string) $line);
                if ($line !== '' && ! preg_match('/wait|pause|after accept|invite|follow/i', $line) && strlen($line) > 12) {
                    $message = $line;
                    break;
                }
            }
        }
        if ($message === '') {
            $message = $channel === 'email'
                ? 'Hi {{firstName}}, I wanted to reach out briefly.'
                : 'Hello {{firstName}}, good evening.';
        }

        $senderName = \App\V2\Ai\Support\SenderIdentity::extractFromPlan($payload);
        if ($senderName !== '') {
            $message = preg_replace('/\[(?:Your\s+)?Name\]/iu', $senderName, $message) ?? $message;
            $message = str_ireplace(
                ['{{senderName}}', '{{sender_name}}', '{{yourName}}', '{{your_name}}'],
                $senderName,
                $message
            );
        }

        if ($channel === 'email') {
            $subject = trim((string) ($payload['subject'] ?? 'Quick note'));
            if ($subject === '') {
                $subject = 'Quick note';
            }

            return [[
                'type' => 'action',
                'channel' => 'email',
                'action' => 'send_email',
                'label' => 'Send Email',
                'config' => [
                    'subject' => $subject,
                    'body' => $message,
                ],
            ]];
        }

        return [[
            'type' => 'action',
            'channel' => $channel,
            'action' => 'send_message',
            'label' => $channel === 'linkedin' ? 'Send Message' : Str::headline($channel).' Message',
            'config' => ['message' => $message],
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function finalizeNodes(array $nodes, array $payload): array
    {
        if ($this->isOneShotIntent($payload)) {
            return $this->ensureEndNode($this->rekeyNodes($this->buildOneShotNodes($payload)));
        }

        if (! $this->isLinkedInPrimary($payload)) {
            $nodes = $this->stripLinkedInOnlySteps($nodes);
        } else {
            $nodes = $this->applyInviteAcceptedIntelligence($nodes, $payload);
            $nodes = $this->applyFirstDegreeAudienceIntelligence($nodes, $payload);
        }

        $nodes = $this->enforceSingleChannelOnly($nodes, $payload);

        return $this->ensureEndNode($this->rekeyNodes($nodes));
    }

    /**
     * @param  list<mixed>  $steps
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function fromStructuredSteps(array $steps, array $payload): array
    {
        $nodes = [];
        $key = 1;
        $defaultChannel = $this->primaryChannel($payload);

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $type = Str::lower(trim((string) ($step['type'] ?? 'action')));
            if ($type === 'delay' || $type === 'wait') {
                $days = max(1, (int) ($step['wait_days'] ?? $step['value'] ?? $step['days'] ?? 1));
                $nodes[] = [
                    'key' => $key++,
                    'type' => 'delay',
                    'value' => $days,
                    'time' => 'days',
                    'label' => (string) ($step['label'] ?? "Wait {$days} day".($days === 1 ? '' : 's')),
                ];
                continue;
            }

            if ($type === 'end') {
                continue;
            }

            if ($type === 'condition') {
                $channel = Str::lower(trim((string) ($step['channel'] ?? 'linkedin')));
                $condition = $this->normalizeConditionKey(
                    $channel,
                    (string) ($step['condition'] ?? $step['action'] ?? 'invite_accepted'),
                );
                $branches = is_array($step['branches'] ?? null) ? $step['branches'] : [];
                $acceptedSrc = $branches['accepted'] ?? $step['accepted'] ?? [];
                $notAcceptedSrc = $branches['not_accepted'] ?? $step['not_accepted'] ?? [];

                $nodes[] = [
                    'key' => $key++,
                    'type' => 'condition',
                    'channel' => $channel,
                    'condition' => $condition,
                    'label' => (string) ($step['label'] ?? 'Invite Accepted?'),
                    'branches' => [
                        'accepted' => $this->fromStructuredSteps(
                            is_array($acceptedSrc) ? $acceptedSrc : [],
                            $payload,
                        ),
                        'not_accepted' => $this->fromStructuredSteps(
                            is_array($notAcceptedSrc) ? $notAcceptedSrc : [],
                            $payload,
                        ),
                    ],
                ];
                continue;
            }

            $channel = Str::lower(trim((string) ($step['channel'] ?? $defaultChannel)));
            $rawAction = (string) ($step['action'] ?? '');
            // Empty linkedin action must not become send_invite when the step is clearly a message.
            if ($rawAction === '' && isset($step['message']) && trim((string) $step['message']) !== '') {
                $rawAction = $channel === 'email' ? 'send_email' : 'send_message';
            }
            $action = OutreachChannelRegistry::normalizeAction($channel, $rawAction);

            $config = [];
            if ($action === 'send_email') {
                $config['subject'] = (string) ($step['subject'] ?? 'Quick intro');
                $config['body'] = (string) ($step['body'] ?? $step['message'] ?? 'Hi {{firstName}},');
            } elseif (in_array($action, ['send_message', 'send_invite'], true)) {
                $config['message'] = (string) ($step['message'] ?? '');
            }

            $nodes[] = [
                'key' => $key++,
                'type' => 'action',
                'channel' => $channel,
                'action' => $action,
                'label' => (string) ($step['label'] ?? Str::headline(str_replace('_', ' ', $action))),
                'config' => $config,
            ];
        }

        return $nodes;
    }

    /**
     * @param  list<mixed>  $lines
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function fromProseSequence(array $lines, array $payload): array
    {
        $nodes = [];
        $key = 1;
        $defaultChannel = $this->primaryChannel($payload);
        $channels = Str::lower((string) ($payload['preferred_channels'] ?? $payload['channels'] ?? $defaultChannel));
        $sawInvite = false;

        foreach ($lines as $line) {
            if (! is_string($line) && ! is_numeric($line)) {
                continue;
            }
            $text = trim((string) $line);
            if ($text === '' || preg_match('/reply handling|stop on reply|ai reply|pause on reply|handle in inbox/i', $text)) {
                continue;
            }

            // Acceptance gate — never another invite. Intelligence layer nests following DMs.
            if ($this->isAcceptanceGateProse($text)) {
                continue;
            }

            if (preg_match('/wait\s+(\d+)\s*(day|days|hour|hours)/i', $text, $m)) {
                $n = max(1, (int) $m[1]);
                $unit = str_contains(Str::lower($m[2]), 'hour') ? 'hours' : 'days';
                $nodes[] = [
                    'key' => $key++,
                    'type' => 'delay',
                    'value' => $n,
                    'time' => $unit,
                    'label' => "Wait {$n} ".$unit,
                ];
                continue;
            }

            $channel = $defaultChannel;
            $action = 'send_message';
            $label = $text;
            $config = ['message' => ''];

            if ($this->isInviteProse($text)) {
                if ($sawInvite) {
                    continue;
                }
                $sawInvite = true;
                $channel = 'linkedin';
                $action = 'send_invite';
                $label = 'Send Invite';
                $config = ['message' => ''];
            } elseif (preg_match('/visit\s+profile/i', $text)) {
                $channel = 'linkedin';
                $action = 'visit_profile';
                $label = 'Visit Profile';
                $config = [];
            } elseif (preg_match('/email/i', $text)) {
                $channel = 'email';
                $action = 'send_email';
                $label = 'Send Email';
                $config = [
                    'subject' => 'Quick intro',
                    'body' => 'Hi {{firstName}}, I wanted to follow up briefly.',
                ];
            } elseif (preg_match('/whatsapp|\bwa\b/i', $text)) {
                $channel = 'whatsapp';
                $action = 'send_message';
                $label = 'WhatsApp Message';
                $config = ['message' => 'Hi {{firstName}}, quick note for you.'];
            } elseif (preg_match('/telegram/i', $text)) {
                $channel = 'telegram';
                $action = 'send_message';
                $label = 'Telegram Message';
                $config = ['message' => 'Hi {{firstName}},'];
            } elseif (preg_match('/instagram/i', $text)) {
                $channel = 'instagram';
                $action = 'send_message';
                $label = 'Instagram DM';
                $config = $this->personalizedFirstTouchConfig();
            } elseif (preg_match('/follow.?up|message|dm|touch|diagnostic|value|close|question/i', $text)) {
                if ($this->isSingleChannel($payload, 'email')) {
                    $channel = 'email';
                    $action = 'send_email';
                    $label = 'Follow-up Email';
                    $config = [
                        'subject' => 'Following up',
                        'body' => 'Hi {{firstName}}, just checking in.',
                    ];
                } elseif ($this->isSingleChannel($payload, 'whatsapp')) {
                    $channel = 'whatsapp';
                    $action = 'send_message';
                    $label = 'WhatsApp Follow-up';
                    $config = ['message' => 'Hi {{firstName}}, just bumping this.'];
                } elseif ($this->isSingleChannel($payload, 'instagram')) {
                    $channel = 'instagram';
                    $action = 'send_message';
                    $label = 'Instagram Follow-up';
                    $config = ['message' => 'Just floating this back up — still curious how this is going on your side.'];
                } elseif ($this->isSingleChannel($payload, 'telegram')) {
                    $channel = 'telegram';
                    $action = 'send_message';
                    $label = 'Telegram Follow-up';
                    $config = ['message' => 'Hi {{firstName}}, just bumping this.'];
                } elseif ($this->isSingleChannel($payload, 'twitter')) {
                    $channel = 'twitter';
                    $action = 'send_message';
                    $label = 'Twitter Follow-up';
                    $config = ['message' => 'Hi {{firstName}}, just bumping this.'];
                } else {
                    $isFirstTouch = ! preg_match('/follow.?up|value follow|professional close/i', $text);
                    $channel = $defaultChannel;
                    $action = $channel === 'email' ? 'send_email' : 'send_message';

                    if ($channel === 'email') {
                        $label = $isFirstTouch ? 'First email (after research)' : 'Follow-up Email';
                        $config = $isFirstTouch
                            ? [
                                'subject' => 'Quick intro',
                                'body' => '',
                                'personalize_before_send' => true,
                                'placeholder' => 'Written for this person after profile and company research. Not a shared template.',
                            ]
                            : [
                                'subject' => 'Following up',
                                'body' => 'Just floating this back up — still curious how this is going on your side.',
                            ];
                    } else {
                        $label = $isFirstTouch
                            ? (strtolower($channel) === 'linkedin' ? 'First message (after research)' : Str::headline($channel).' first message (after research)')
                            : (strtolower($channel) === 'linkedin' ? 'Follow-up' : Str::headline($channel).' Follow-up');
                        $config = $isFirstTouch
                            ? $this->personalizedFirstTouchConfig()
                            : ['message' => 'Just floating this back up — still curious how this is going on your side.'];
                    }
                }
            } else {
                // Skip vague strategy lines that aren't executable steps.
                continue;
            }

            $nodes[] = [
                'key' => $key++,
                'type' => 'action',
                'channel' => $channel,
                'action' => $action,
                'label' => $label,
                'config' => $config,
            ];
        }

        return $nodes;
    }

    private function isAcceptanceGateProse(string $text): bool
    {
        return (bool) preg_match(
            '/after\s+(the\s+)?(invite\s+)?accept|invite\s+accept|wait\s+(for\s+)?accept|once\s+(they\s+)?connect|connection\s+accept|has\s+accept|when\s+(they\s+)?accept/i',
            $text,
        );
    }

    private function isInviteProse(string $text): bool
    {
        if ($this->isAcceptanceGateProse($text)) {
            return false;
        }

        // "Empty invite", "Send Invite", "Connection / first touch" — not "after acceptance".
        return (bool) preg_match('/\b(invite|connect|connection request|first touch)\b/i', $text);
    }

    /**
     * If the plan has LinkedIn invite + later LinkedIn DMs but no invite_accepted node,
     * wrap DMs (and alt-channel backups) under the real condition branches.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function applyInviteAcceptedIntelligence(array $nodes, array $payload): array
    {
        if (! $this->isLinkedInPrimary($payload)) {
            return $nodes;
        }

        $nodes = $this->dedupeTopLevelInvites($nodes);

        if ($this->containsCondition($nodes, 'invite_accepted')) {
            return $nodes;
        }

        $inviteIndex = null;
        foreach ($nodes as $i => $node) {
            if (($node['type'] ?? '') === 'action' && ($node['action'] ?? '') === 'send_invite') {
                $inviteIndex = $i;
                break;
            }
        }

        if ($inviteIndex === null) {
            return $nodes;
        }

        $before = array_slice($nodes, 0, $inviteIndex + 1);
        $after = array_values(array_filter(
            array_slice($nodes, $inviteIndex + 1),
            fn (array $n) => ($n['type'] ?? '') !== 'end',
        ));

        $preConditionDelay = null;
        $accepted = [];
        $notAccepted = [];
        $branching = false;

        foreach ($after as $node) {
            $type = (string) ($node['type'] ?? '');

            if ($type === 'action' && ($node['action'] ?? '') === 'send_invite') {
                continue;
            }

            if (! $branching && $type === 'delay' && $accepted === [] && $notAccepted === []) {
                if ($preConditionDelay === null) {
                    $preConditionDelay = $node;
                }
                continue;
            }

            $branching = true;
            $channel = Str::lower((string) ($node['channel'] ?? 'linkedin'));

            if ($type === 'delay') {
                $accepted[] = $node;
                continue;
            }

            if ($type === 'action' && $channel !== 'linkedin') {
                $notAccepted[] = $node;
                continue;
            }

            $accepted[] = $node;
        }

        if ($accepted === [] && $notAccepted === []) {
            return $nodes;
        }

        // LinkedIn-only plans with no alt channel still get an empty not_accepted branch
        // so the canvas shows the Yes path clearly (timeout ends the lead).
        $out = $before;
        if ($preConditionDelay !== null) {
            $out[] = $preConditionDelay;
        }
        $out[] = [
            'type' => 'condition',
            'channel' => 'linkedin',
            'condition' => 'invite_accepted',
            'label' => 'Invite Accepted?',
            'branches' => [
                'accepted' => $accepted,
                'not_accepted' => $notAccepted,
            ],
        ];

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function dedupeTopLevelInvites(array $nodes): array
    {
        $seenInvite = false;
        $out = [];
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'action' && ($node['action'] ?? '') === 'send_invite') {
                if ($seenInvite) {
                    continue;
                }
                $seenInvite = true;
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * 1st-degree audiences are already connected — strip invites / invite_accepted and keep DMs.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function applyFirstDegreeAudienceIntelligence(array $nodes, array $payload): array
    {
        if (! $this->isFirstDegreeAudience($payload)) {
            return $nodes;
        }

        $out = [];
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? '');

            if ($type === 'action' && ($node['action'] ?? '') === 'send_invite') {
                continue;
            }

            if ($type === 'condition' && ($node['condition'] ?? '') === 'invite_accepted') {
                foreach ($node['branches']['accepted'] ?? [] as $child) {
                    if (is_array($child)) {
                        $out[] = $child;
                    }
                }
                foreach ($node['branches']['not_accepted'] ?? [] as $child) {
                    if (is_array($child)) {
                        $out[] = $child;
                    }
                }
                continue;
            }

            $out[] = $node;
        }

        $hasMessage = collect($out)->contains(
            fn ($n) => ($n['type'] ?? '') === 'action' && ($n['action'] ?? '') === 'send_message',
        );
        if (! $hasMessage) {
            array_unshift($out, [
                'type' => 'action',
                'channel' => 'linkedin',
                'action' => 'send_message',
                'label' => 'Send Message',
                'config' => ['message' => 'Hi {{firstName}}, quick question for you.'],
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isFirstDegreeAudience(array $payload): bool
    {
        if (! empty($payload['first_degree_only'])) {
            return true;
        }

        $depths = $payload['network_depths']
            ?? data_get($payload, 'search_filters.network_depths')
            ?? IcpSearchFilterParser::normalizeNetworkDepths(
                $payload['network_degree'] ?? null,
                (string) ($payload['audience'] ?? $payload['icp_notes'] ?? $payload['goal'] ?? ''),
            );

        return IcpSearchFilterParser::isFirstDegreeOnly(is_array($depths) ? $depths : null);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function maybeAdaptFallbackForFirstDegree(array $nodes, array $payload): array
    {
        if (! $this->isFirstDegreeAudience($payload)) {
            return $nodes;
        }

        return $this->ensureEndNode(
            $this->rekeyNodes(
                $this->applyFirstDegreeAudienceIntelligence(
                    $this->normalizeNodes($nodes),
                    $payload,
                ),
            ),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function containsCondition(array $nodes, string $condition): bool
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'condition' && ($node['condition'] ?? '') === $condition) {
                return true;
            }
            foreach (['accepted', 'not_accepted'] as $branch) {
                $kids = $node['branches'][$branch] ?? null;
                if (is_array($kids) && $this->containsCondition($kids, $condition)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeConditionKey(string $channel, string $raw): string
    {
        $key = Str::lower(trim($raw));
        $aliases = [
            'accepted' => 'invite_accepted',
            'has_accept' => 'invite_accepted',
            'has_accepted' => 'invite_accepted',
            'invite_accept' => 'invite_accepted',
            'connection_accepted' => 'invite_accepted',
            'replied' => $channel === 'email' ? 'email_replied' : ($channel === 'linkedin' ? 'has_replied' : 'message_replied'),
            'has_reply' => $channel === 'linkedin' ? 'has_replied' : 'message_replied',
            'reply' => $channel === 'email' ? 'email_replied' : ($channel === 'linkedin' ? 'has_replied' : 'message_replied'),
            'no_response' => 'no_reply',
            'silent' => 'no_reply',
        ];
        $key = $aliases[$key] ?? $key;

        $allowed = array_column(OutreachChannelRegistry::conditionsByChannel()[$channel] ?? [], 'key');
        if ($allowed !== [] && ! in_array($key, $allowed, true)) {
            return $channel === 'linkedin' ? 'invite_accepted' : ($allowed[0] ?? $key);
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function primaryChannel(array $payload): string
    {
        $explicit = Str::lower(trim((string) ($payload['primary_channel'] ?? $payload['send_channel'] ?? '')));
        if (! empty($payload['single_channel_only'])
            && in_array($explicit, ['linkedin', 'email', 'whatsapp', 'telegram', 'instagram', 'twitter'], true)) {
            return $explicit;
        }

        if (in_array($explicit, ['linkedin', 'email', 'whatsapp', 'telegram', 'instagram', 'twitter'], true)) {
            return $explicit;
        }

        $channels = Str::lower((string) ($payload['preferred_channels'] ?? $payload['channels'] ?? 'linkedin'));
        $oneShot = ! empty($payload['one_shot']) || ! empty($payload['one_time'])
            || (($payload['sequence_mode'] ?? '') === 'one_shot');

        // Soci single-channel plans must never default follow-ups to LinkedIn.
        if (! empty($payload['single_channel_only'])) {
            $priority = ['instagram', 'whatsapp', 'telegram', 'twitter', 'email', 'linkedin'];
        } else {
            $priority = $oneShot
                ? ['whatsapp', 'instagram', 'telegram', 'twitter', 'email', 'linkedin']
                : ['linkedin', 'email', 'whatsapp', 'telegram', 'instagram', 'twitter'];
        }

        $mentioned = [];
        foreach ($priority as $ch) {
            if (str_contains($channels, $ch) || ($ch === 'twitter' && (str_contains($channels, ' x ') || str_ends_with($channels, ' x')))) {
                $mentioned[] = $ch;
            }
        }

        if ($mentioned === []) {
            return 'linkedin';
        }

        if ($oneShot && count($mentioned) === 1) {
            return $mentioned[0];
        }

        return $mentioned[0];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isSingleChannel(array $payload, string $channel): bool
    {
        if (! empty($payload['single_channel_only'])) {
            return $this->primaryChannel($payload) === $channel;
        }

        $channels = Str::lower((string) ($payload['preferred_channels'] ?? $payload['channels'] ?? ''));

        return str_contains($channels, $channel) && ! str_contains($channels, 'linkedin');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isLinkedInPrimary(array $payload): bool
    {
        return $this->primaryChannel($payload) === 'linkedin';
    }

    /**
     * Remove LinkedIn invites/conditions from non-LinkedIn Soci plans.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function stripLinkedInOnlySteps(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = (string) ($node['type'] ?? '');

            if ($type === 'action') {
                $channel = Str::lower((string) ($node['channel'] ?? ''));
                $action = (string) ($node['action'] ?? '');
                if ($channel === 'linkedin' && in_array($action, ['send_invite', 'visit_profile', 'like_post'], true)) {
                    continue;
                }
            }

            if ($type === 'condition' && ($node['condition'] ?? '') === 'invite_accepted') {
                $accepted = is_array($node['branches']['accepted'] ?? null) ? $node['branches']['accepted'] : [];
                $out = array_merge($out, $this->stripLinkedInOnlySteps($accepted));

                continue;
            }

            if ($type === 'condition') {
                foreach (['accepted', 'not_accepted'] as $branch) {
                    $kids = $node['branches'][$branch] ?? null;
                    if (is_array($kids)) {
                        $node['branches'][$branch] = $this->stripLinkedInOnlySteps($kids);
                    }
                }
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * Soci plans: one channel only — every send step uses primary_channel.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function enforceSingleChannelOnly(array $nodes, array $payload): array
    {
        if (empty($payload['single_channel_only'])) {
            return $nodes;
        }

        $primary = $this->primaryChannel($payload);
        if (! in_array($primary, ['linkedin', 'email', 'whatsapp', 'telegram', 'instagram', 'twitter'], true)) {
            return $nodes;
        }

        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? '') === 'condition') {
                $nodes[$i]['channel'] = $primary;
                foreach (['accepted', 'not_accepted'] as $branch) {
                    $kids = $node['branches'][$branch] ?? null;
                    if (is_array($kids)) {
                        $nodes[$i]['branches'][$branch] = $this->enforceSingleChannelOnly($kids, $payload);
                    }
                }
                continue;
            }

            if (($node['type'] ?? '') !== 'action') {
                continue;
            }

            $action = OutreachChannelRegistry::normalizeAction(
                $primary,
                (string) ($node['action'] ?? ''),
            );
            $nodes[$i]['channel'] = $primary;
            $nodes[$i]['action'] = $action;

            $label = trim((string) ($node['label'] ?? ''));
            if ($label === '' || str_contains(Str::lower($label), 'linkedin') && $primary !== 'linkedin') {
                $nodes[$i]['label'] = Str::headline($primary.' '.str_replace('_', ' ', $action));
            }
        }

        return $nodes;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function normalizeNodes(array $nodes): array
    {
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['type'] ?? '') === 'condition') {
                $channel = Str::lower(trim((string) ($node['channel'] ?? 'linkedin')));
                $nodes[$i]['channel'] = $channel;
                $nodes[$i]['condition'] = $this->normalizeConditionKey(
                    $channel,
                    (string) ($node['condition'] ?? 'invite_accepted'),
                );
                foreach (['accepted', 'not_accepted'] as $branch) {
                    $kids = $node['branches'][$branch] ?? [];
                    if (is_array($kids)) {
                        $nodes[$i]['branches'][$branch] = $this->normalizeNodes(array_values($kids));
                    }
                }
                continue;
            }

            if (($node['type'] ?? '') !== 'action') {
                continue;
            }

            $channel = Str::lower(trim((string) ($node['channel'] ?? 'linkedin')));
            $action = OutreachChannelRegistry::normalizeAction($channel, (string) ($node['action'] ?? ''));
            $nodes[$i]['channel'] = $channel;
            $nodes[$i]['action'] = $action;

            $label = trim((string) ($node['label'] ?? ''));
            if ($label === '' || preg_match('/connect/i', $label)) {
                $nodes[$i]['label'] = match ($action) {
                    'send_invite' => 'Send Invite',
                    'send_message' => 'Send Message',
                    'send_email' => 'Send Email',
                    'visit_profile' => 'Visit Profile',
                    default => Str::headline(str_replace('_', ' ', $action)),
                };
            }
        }

        return $nodes;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function rekeyNodes(array $nodes, int &$next = 1): array
    {
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') !== 'end') {
                $nodes[$i]['key'] = $next++;
            }
            foreach (['accepted', 'not_accepted'] as $branch) {
                $kids = $node['branches'][$branch] ?? null;
                if (is_array($kids) && $kids !== []) {
                    $nodes[$i]['branches'][$branch] = $this->rekeyNodes(array_values($kids), $next);
                }
            }
        }

        return $nodes;
    }

    private function isValidNodeModel(mixed $nodes): bool
    {
        if (! is_array($nodes) || $nodes === [] || ! array_is_list($nodes)) {
            return false;
        }

        return $this->countActions($nodes) > 0;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function countActions(array $nodes): int
    {
        $actions = 0;
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'action') {
                $actions++;
            }
            foreach (['accepted', 'not_accepted'] as $branch) {
                $kids = $node['branches'][$branch] ?? null;
                if (is_array($kids)) {
                    $actions += $this->countActions($kids);
                }
            }
        }

        return $actions;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function ensureEndNode(array $nodes): array
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'end') {
                return $nodes;
            }
        }

        $nodes[] = ['key' => 99, 'type' => 'end', 'label' => 'End'];

        return $nodes;
    }

    /**
     * @return array{message:string, personalize_before_send:bool, placeholder:string}
     */
    private function personalizedFirstTouchConfig(): array
    {
        return [
            'message' => '',
            'personalize_before_send' => true,
            'placeholder' => 'Written for this person after profile and company research. Not a shared template.',
        ];
    }
}
