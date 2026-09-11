<?php

namespace App\Ai\Tools;

use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\ActionApprovalService;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\ImportLeadsCsvFromPlanService;
use App\V2\Ai\Support\PlanLeadList;
use App\V2\Outreach\OutreachImportListService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Save phone / email / @handles / LinkedIn URLs from chat into an outreach list,
 * then Soci can draft_campaign_plan with the returned list_hash.
 */
class SaveContactsTool extends GatedTool
{
    public function toolName(): string
    {
        return 'save_contacts';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Prepare;
    }

    public function description(): Stringable|string
    {
        return 'Save one or many contacts from chat (phone, email, Instagram/Telegram/Twitter handle, LinkedIn URL) into a lead list. '
            .'Always save first, then draft_campaign_plan / one_shot send. Autopilot+ saves immediately; Copilot/Assisted require Launch.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_name' => $schema->string()->nullable()->description('List name. Defaults from contact count / channel.'),
            'contacts' => $schema->array()->required()->description(
                'Array of contacts. Each may include: full_name, phone, email, linkedin_url, instagram, telegram, twitter, '
                .'or identifier (+ optional platform) when the user pasted a raw phone/@/email/URL.',
            ),
            'message' => $schema->string()->nullable()->description('Optional message body if you will immediately draft a one_shot after save.'),
            'preferred_channels' => $schema->string()->nullable()->description(
                'Hint for next campaign: WhatsApp, Instagram, Telegram, Email, LinkedIn, Twitter.',
            ),
        ];
    }

    protected function run(Request $request): array
    {
        $rawContacts = $request['contacts'] ?? null;
        if (! is_array($rawContacts) || $rawContacts === []) {
            throw new \InvalidArgumentException('contacts must be a non-empty array.');
        }

        $importer = app(OutreachImportListService::class);
        $normalized = [];
        foreach ($rawContacts as $row) {
            if (is_string($row) && trim($row) !== '') {
                $row = ['identifier' => trim($row)];
            }
            if (! is_array($row)) {
                continue;
            }
            $map = $importer->normalizeContactMap($row);
            if ($map !== null) {
                $normalized[] = $map;
            }
        }

        if ($normalized === []) {
            throw new \InvalidArgumentException(
                'No valid contacts. Need phone, email, linkedin_url, instagram, telegram, and/or twitter.'
            );
        }

        $channelHint = trim((string) ($request['preferred_channels'] ?? ''));
        if ($channelHint === '') {
            $channelHint = $this->inferChannelLabel($normalized);
        }

        $listName = trim((string) ($request['list_name'] ?? ''));
        if ($listName === '') {
            $listName = count($normalized) === 1
                ? ($normalized[0]['full_name'].' (1)')
                : 'Chat contacts ('.count($normalized).')';
        }

        $plan = [
            'type' => 'csv_import',
            'tool' => 'save_contacts',
            'goal' => 'Save contacts: '.$listName,
            'list_name' => $listName,
            'contacts' => $normalized,
            'preview_rows' => count($normalized),
            'preferred_channels' => $channelHint,
            'message' => trim((string) ($request['message'] ?? '')),
            'steps' => [
                'Save '.count($normalized).' contact(s) to a lead list',
                'Use returned list_hash with draft_campaign_plan (one_shot for single greeting)',
                'Channels hint: '.($channelHint !== '' ? $channelHint : 'infer from identifiers'),
            ],
            'status' => 'awaiting_review',
        ];

        $formatter = app(CommandCenterService::class);
        $autonomy = $this->context->autonomy();

        // Copilot: recommend only. Assisted: Launch required. Autopilot+: save immediately.
        if ($autonomy->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval_id' => null,
                'saved' => false,
                'plan' => $plan,
                'card' => $formatter->formatPlanCard($plan, null, $this->context->channel),
                'cta' => 'Copilot mode: recommend saving these contacts; user must raise autonomy or confirm via UI.',
                'suggested_channels' => $channelHint,
            ];
        }

        if ($autonomy->value >= AiAutonomyLevel::Autopilot->value) {
            $result = app(ImportLeadsCsvFromPlanService::class)->importContactsNow(
                $this->context->user,
                $listName,
                $normalized,
            );

            $list = $result['list'];
            $merged = PlanLeadList::merge($plan, $list['list_hash'], 'csv', $listName);

            return [
                'approval_id' => null,
                'saved' => true,
                'plan' => $merged,
                'list_hash' => $list['list_hash'],
                'list_src' => 'csv',
                'list_name' => $listName,
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'suggested_channels' => $result['suggested_channel']
                    ? ucfirst((string) $result['suggested_channel'])
                    : $channelHint,
                'next_steps' => [
                    'Contacts saved. Call draft_campaign_plan with this list_hash + matching channels'
                    .(count($normalized) === 1 ? ' + one_shot=true + message' : '').'.',
                    'list_hash='.$list['list_hash'].' list_src=csv',
                ],
                'card' => "Saved {$result['imported']} contact(s) into \"{$listName}\" (list_hash={$list['list_hash']}).",
            ];
        }

        $approval = app(ActionApprovalService::class)->createPending(
            $this->context->user,
            $this->context->organizationId,
            $this->toolName(),
            $this->permission(),
            $plan,
            $this->context->conversation,
        );

        return [
            'approval_id' => $approval->id,
            'saved' => false,
            'plan' => $plan,
            'card' => $formatter->formatPlanCard($plan, $approval->id, $this->context->channel),
            'cta' => 'Assisted mode: user Launch/Import to save, then draft campaign with the list_hash.',
            'suggested_channels' => $channelHint,
        ];
    }

    /**
     * @param  list<array<string, string>>  $contacts
     */
    private function inferChannelLabel(array $contacts): string
    {
        $scores = [];
        foreach ($contacts as $c) {
            if (trim($c['phone'] ?? '') !== '') {
                $scores['WhatsApp'] = ($scores['WhatsApp'] ?? 0) + 1;
            }
            if (trim($c['email'] ?? '') !== '') {
                $scores['Email'] = ($scores['Email'] ?? 0) + 1;
            }
            if (trim($c['instagram'] ?? '') !== '') {
                $scores['Instagram'] = ($scores['Instagram'] ?? 0) + 1;
            }
            if (trim($c['telegram'] ?? '') !== '') {
                $scores['Telegram'] = ($scores['Telegram'] ?? 0) + 1;
            }
            if (trim($c['twitter'] ?? '') !== '') {
                $scores['Twitter'] = ($scores['Twitter'] ?? 0) + 1;
            }
            if (trim($c['linkedin_url'] ?? '') !== '') {
                $scores['LinkedIn'] = ($scores['LinkedIn'] ?? 0) + 1;
            }
        }
        arsort($scores);

        return $scores !== [] ? (string) array_key_first($scores) : 'LinkedIn + Email';
    }
}
