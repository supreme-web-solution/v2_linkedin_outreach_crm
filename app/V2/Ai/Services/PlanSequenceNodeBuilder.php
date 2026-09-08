<?php

namespace App\V2\Ai\Services;

use App\Models\V2OutreachCampaign;
use App\V2\Outreach\OutreachChannelRegistry;
use Illuminate\Support\Str;

/**
 * Turns Alex plan sequence prose / structured steps into an executable outreach node_model.
 * Falls back to channel presets when the plan does not describe a usable sequence.
 * Always maps actions onto OutreachChannelRegistry-supported keys (e.g. connect → send_invite).
 */
class PlanSequenceNodeBuilder
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{template_type:string, node_model:list<array<string,mixed>>, custom:bool}
     */
    public function resolve(array $payload): array
    {
        $fallbackType = app(CampaignDraftFromPlanService::class)->resolveTemplateType($payload);
        $templates = V2OutreachCampaign::templates();
        $fallbackNodes = $templates[$fallbackType]['node_model']
            ?? $templates['linkedin_email']['node_model'];

        if ($this->isValidNodeModel($payload['node_model'] ?? null)) {
            return [
                'template_type' => 'custom',
                'node_model' => $this->ensureEndNode($this->normalizeNodes(array_values($payload['node_model']))),
                'custom' => true,
            ];
        }

        $structured = $payload['sequence_steps'] ?? null;
        if (is_array($structured) && $structured !== []) {
            $built = $this->fromStructuredSteps($structured, $payload);
            if ($built !== []) {
                return [
                    'template_type' => 'custom',
                    'node_model' => $this->ensureEndNode($this->normalizeNodes($built)),
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
                    'node_model' => $this->ensureEndNode($this->normalizeNodes($built)),
                    'custom' => true,
                ];
            }
        }

        return [
            'template_type' => $fallbackType,
            'node_model' => $fallbackNodes,
            'custom' => false,
        ];
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

            $channel = Str::lower(trim((string) ($step['channel'] ?? $defaultChannel)));
            $action = OutreachChannelRegistry::normalizeAction(
                $channel,
                (string) ($step['action'] ?? ''),
            );

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

        foreach ($lines as $line) {
            if (! is_string($line) && ! is_numeric($line)) {
                continue;
            }
            $text = trim((string) $line);
            if ($text === '' || preg_match('/reply handling|stop on reply|ai reply/i', $text)) {
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

            if (preg_match('/invite|connect|connection|first touch/i', $text)) {
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
                $config = ['message' => 'Hi {{firstName}},'];
            } elseif (preg_match('/follow.?up|message|dm|touch/i', $text)) {
                if (str_contains($channels, 'email') && ! str_contains($channels, 'linkedin')) {
                    $channel = 'email';
                    $action = 'send_email';
                    $label = 'Follow-up Email';
                    $config = [
                        'subject' => 'Following up',
                        'body' => 'Hi {{firstName}}, just checking in.',
                    ];
                } elseif (str_contains($channels, 'whatsapp') && ! str_contains($channels, 'linkedin')) {
                    $channel = 'whatsapp';
                    $action = 'send_message';
                    $label = 'WhatsApp Follow-up';
                    $config = ['message' => 'Hi {{firstName}}, just bumping this.'];
                } else {
                    $channel = 'linkedin';
                    $action = 'send_message';
                    $label = 'Send Message';
                    $config = ['message' => 'Thanks for connecting, {{firstName}}!'];
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function primaryChannel(array $payload): string
    {
        $channels = Str::lower((string) ($payload['preferred_channels'] ?? $payload['channels'] ?? 'linkedin'));
        foreach (['linkedin', 'email', 'whatsapp', 'telegram', 'instagram'] as $ch) {
            if (str_contains($channels, $ch)) {
                return $ch;
            }
        }

        return 'linkedin';
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function normalizeNodes(array $nodes): array
    {
        foreach ($nodes as $i => $node) {
            if (! is_array($node) || ($node['type'] ?? '') !== 'action') {
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

    private function isValidNodeModel(mixed $nodes): bool
    {
        if (! is_array($nodes) || $nodes === [] || ! array_is_list($nodes)) {
            return false;
        }

        $actions = 0;
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                return false;
            }
            $type = (string) ($node['type'] ?? '');
            if ($type === 'action') {
                $actions++;
            }
        }

        return $actions > 0;
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
}
