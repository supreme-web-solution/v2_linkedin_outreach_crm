<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\AiConversation;
use App\Models\User;
use App\Models\V2IntegrationAccount;
use App\V2\Ai\Enums\AiAutonomyLevel;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Services\CallOrchestrationService;
use App\V2\Services\LeadListService;
use Illuminate\Support\Str;

class CallManagerCommandCenterService
{
    public function __construct(
        private readonly LeadListService $leadLists,
        private readonly CallOrchestrationService $calls,
        private readonly ProspectAudienceResolverService $audienceResolver,
        private readonly LinkedInAudienceBuilderService $linkedInAudience,
        private readonly ActionApprovalService $approvals,
    ) {}

    /**
     * @return array{
     *     approval_id?:int|null,
     *     plan?:array<string,mixed>,
     *     card?:string,
     *     message?:string
     * }
     */
    public function stageLaunch(
        User $user,
        int $organizationId,
        AiConversation $conversation,
        ?string $audienceQuery,
        ?string $listHash,
        ?string $listSrc,
        ?string $batchName,
        ?string $openingMessage,
        string $surface,
        AiAutonomyLevel $autonomy,
    ): array {
        $audienceQuery = trim((string) $audienceQuery);
        $listHash = trim((string) $listHash);
        $listSrc = trim((string) $listSrc);

        $audience = null;
        if ($listHash !== '' && in_array($listSrc, ['aud', 'sn'], true)) {
            $audience = [
                'list_hash' => $listHash,
                'list_src' => $listSrc,
                'list_name' => 'Selected list',
                'total_leads' => 0,
            ];
            $lists = $this->leadLists->listsForUser($user->id);
            $match = $lists->first(fn (array $row) => (string) $row['list_id'] === $listHash && (string) $row['src'] === $listSrc);
            if ($match) {
                $audience['list_name'] = (string) $match['list_name'];
                $audience['total_leads'] = (int) ($match['total_leads'] ?? 0);
            }
        } else {
            $probe = [
                'goal' => $audienceQuery !== '' ? $audienceQuery : 'Call Manager outreach',
                'icp_notes' => $audienceQuery,
                'audience' => $audienceQuery,
            ];
            $audience = $this->audienceResolver->resolve($user, $probe, strict: true)
                ?? $this->linkedInAudience->tryBuildFromPlan($user, $organizationId, $probe);
        }

        if ($audience === null) {
            throw new \RuntimeException('No audience list found. Connect LinkedIn or import leads first.');
        }

        $leads = $this->leadLists->resolveLeads(
            $user->id,
            (string) $audience['list_hash'],
            (string) $audience['list_src'],
            [],
            true,
        );

        if ($leads->isEmpty()) {
            throw new \RuntimeException('The matched list has no leads with LinkedIn profiles.');
        }

        $flowName = trim((string) $batchName);
        if ($flowName === '') {
            $flowName = $audienceQuery !== ''
                ? Str::limit($audienceQuery, 80, '')
                : (string) $audience['list_name'];
        }

        $result = $this->calls->createCallsFromLeads($user, $organizationId, $leads, [
            'list_id' => (string) $audience['list_hash'],
            'src' => (string) $audience['list_src'],
            'batch_name' => $flowName,
            'pending_message' => trim((string) $openingMessage) ?: null,
            'run' => false,
        ]);

        if ($result['created'] === 0) {
            throw new \RuntimeException(
                'No new Call Manager prospects were added.'
                .($result['skipped'] > 0 ? " {$result['skipped']} skipped (already in pipeline or missing profile)." : ''),
            );
        }

        $hasLinkedIn = V2IntegrationAccount::activeUnipileAccountId($user->id) !== null;

        $plan = [
            'type' => 'call_manager_launch',
            'goal' => 'Start Call Manager outreach: '.$flowName,
            'audience' => (string) $audience['list_name'],
            'list_hash' => (string) $audience['list_hash'],
            'list_src' => (string) $audience['list_src'],
            'batch_id' => (string) $result['batch_id'],
            'prospect_count' => $result['created'],
            'skipped_count' => $result['skipped'],
            'calls_url' => url('/calls'),
            'linkedin_connected' => $hasLinkedIn,
            'opening_message' => trim((string) $openingMessage) ?: null,
            'steps' => [
                'Review audience and opening message',
                'Launch to queue LinkedIn chats via Call Manager',
            ],
        ];

        if (! $hasLinkedIn) {
            $plan['integration_note'] = 'Connect LinkedIn in Integrations before Launch can start chats.';
        }

        if ($autonomy->value <= AiAutonomyLevel::Copilot->value) {
            return [
                'approval_id' => null,
                'plan' => $plan,
                'card' => app(CommandCenterService::class)->formatPlanCard($plan, null, $surface),
                'message' => 'Copilot mode: plan only — switch to Assisted to launch chats.',
            ];
        }

        $approval = $this->approvals->createPending(
            $user,
            $organizationId,
            'prepare_call_manager_launch',
            AiToolPermission::Prepare,
            $plan,
            $conversation,
        );

        return [
            'approval_id' => $approval->id,
            'plan' => $plan,
            'card' => app(CommandCenterService::class)->formatPlanCard($plan, $approval->id, $surface),
        ];
    }

    /**
     * @return array{message:string, calls_url:string, queued:int, skipped:int}
     */
    public function launchFromApproval(AiActionApproval $approval, User $user): array
    {
        $batchId = trim((string) ($approval->payload['batch_id'] ?? ''));
        abort_unless($batchId !== '', 422, 'Call Manager batch missing from approval.');

        if (! V2IntegrationAccount::activeUnipileAccountId($user->id)) {
            throw new \RuntimeException('Connect LinkedIn in Integrations before starting Call Manager chats.');
        }

        $result = $this->calls->launchUnlinkedCallsInFlow(
            $user,
            (int) $approval->organization_id,
            $batchId,
        );

        if ($result['queued'] === 0) {
            throw new \RuntimeException('No chats were queued — prospects may already have active conversations.');
        }

        $message = "{$result['queued']} LinkedIn chat(s) queued via Call Manager.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} skipped (missing profile).";
        }

        return [
            'message' => $message,
            'calls_url' => url('/calls'),
            'queued' => $result['queued'],
            'skipped' => $result['skipped'],
        ];
    }
}
