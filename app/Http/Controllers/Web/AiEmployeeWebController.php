<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiChannelIdentity;
use App\V2\Ai\Integrations\ZernioClient;
use App\V2\Ai\Services\ActionApprovalService;
use App\V2\Ai\Services\AgentOrchestrator;
use App\V2\Ai\Services\AiActionHistoryService;
use App\V2\Ai\Services\AiChannelPolicyService;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Services\ChannelIdentityService;
use App\V2\Ai\Services\WhatsAppCommandLinkPresenter;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\ExecuteSalesPlanCommandCenterService;
use App\V2\Ai\Services\InboxCommandCenterService;
use App\V2\Services\EntitlementService;
use App\V2\Outreach\OutreachChannelGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiEmployeeWebController extends Controller
{
    public function index(
        AiEmployeeSettingsService $settingsService,
        CommandCenterService $commandCenter,
        InboxCommandCenterService $inboxCommandCenter,
        ZernioClient $zernio,
        OutreachChannelGuard $channelGuard,
        AiChannelPolicyService $channelPolicy,
        AiActionHistoryService $actionHistory,
    ): Response {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $settings = $settingsService->for($user, $orgId);
        $conversation = $commandCenter->conversation($user, $orgId);
        $historyWindow = $commandCenter->historyWindow($conversation);
        $pending = $commandCenter->pendingApprovals($user, $orgId);

        $identity = AiChannelIdentity::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->where('channel', 'whatsapp')
            ->where('status', 'active')
            ->first();

        return Inertia::render('crm/AiEmployee/Index', [
            'settings' => [
                'enabled' => (bool) $settings->enabled,
                'kill_switch' => (bool) $settings->kill_switch,
                'autonomy_level' => (int) $settings->autonomy_level,
                'employee_name' => $settings->employee_name,
            ],
            'whatsapp' => [
                'linked' => (bool) $identity,
                'phone' => $identity?->external_id,
                'bot_number' => config('socifusion_ai.zernio.from_number'),
                'configured' => $zernio->configured(),
            ],
            'conversation_id' => $conversation->id,
            'messages' => $commandCenter->serializeMessages($historyWindow['messages']),
            'has_older_messages' => $historyWindow['has_older'],
            'pending_approvals' => $commandCenter->serializeApprovals($pending),
            'attention_queue' => $inboxCommandCenter->attention($user, $orgId, 8),
            'action_history' => $actionHistory->recent($user, $orgId, 5),
            'integrations' => $this->integrationReadiness($channelGuard, $channelPolicy, $user->id),
        ]);
    }

    public function widgetBootstrap(
        AiEmployeeSettingsService $settingsService,
        CommandCenterService $commandCenter,
    ): JsonResponse {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $settings = $settingsService->for($user, $orgId);
        $conversation = $commandCenter->conversation($user, $orgId);
        $historyWindow = $commandCenter->historyWindow($conversation);
        $pending = $commandCenter->pendingApprovals($user, $orgId);

        return response()->json([
            'conversation_id' => $conversation->id,
            'messages' => $commandCenter->serializeMessages($historyWindow['messages']),
            'has_older_messages' => $historyWindow['has_older'],
            'pending_approvals' => $commandCenter->serializeApprovals($pending),
            'pending_approvals_count' => count($pending),
            'settings' => [
                'enabled' => (bool) $settings->enabled,
                'kill_switch' => (bool) $settings->kill_switch,
                'employee_name' => $settings->employee_name,
            ],
        ]);
    }

    public function attention(InboxCommandCenterService $inboxCommandCenter): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        return response()->json(
            $inboxCommandCenter->attention($user, $orgId, (int) request()->query('limit', 10))
        );
    }

    public function nurture(InboxCommandCenterService $inboxCommandCenter): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        return response()->json(
            $inboxCommandCenter->nurtureQueue($user, (int) request()->query('limit', 8))
        );
    }

    public function actionHistory(AiActionHistoryService $actionHistory): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        return response()->json([
            'items' => $actionHistory->recent($user, $orgId, (int) request()->query('limit', 5)),
        ]);
    }

    public function activityPage(
        AiActionHistoryService $actionHistory,
        AiEmployeeSettingsService $settingsService,
    ): Response {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $settings = $settingsService->for($user, $orgId);

        return Inertia::render('crm/AiEmployee/Activity', [
            'actions' => $actionHistory->paginate(
                $user,
                $orgId,
                (int) request()->query('per_page', 20),
            ),
            'employee_name' => $settings->employee_name,
        ]);
    }

    public function undoAction(int $id, AiActionHistoryService $actionHistory): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $result = $actionHistory->undo($user, $orgId, $id);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    public function draftInboxReply(
        Request $request,
        CommandCenterService $commandCenter,
        InboxCommandCenterService $inboxCommandCenter,
    ): JsonResponse {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $data = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $aiConversation = $commandCenter->conversation($user, $orgId);

        try {
            $result = $inboxCommandCenter->stageDraftReply(
                $user,
                $orgId,
                $aiConversation,
                (int) $data['conversation_id'],
                $data['notes'] ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['blocked'] ?? false) {
            return response()->json(['message' => $result['message'] ?? 'Blocked'], 403);
        }

        return response()->json([
            'approval_id' => $result['approval_id'],
            'card' => $result['card'],
            'plan' => $result['plan'],
            'pending_approvals' => $commandCenter->serializeApprovals(
                $commandCenter->pendingApprovals($user, $orgId)
            ),
        ]);
    }

    public function stageNextBestAction(
        Request $request,
        CommandCenterService $commandCenter,
        InboxCommandCenterService $inboxCommandCenter,
    ): JsonResponse {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $data = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'action' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $aiConversation = $commandCenter->conversation($user, $orgId);

        try {
            $result = $inboxCommandCenter->stageNextBestAction(
                $user,
                $orgId,
                $aiConversation,
                (int) $data['conversation_id'],
                $data['action'] ?? null,
                $data['reason'] ?? null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['blocked'] ?? false) {
            return response()->json(['message' => $result['message'] ?? 'Blocked'], 403);
        }

        return response()->json([
            'approval_id' => $result['approval_id'],
            'card' => $result['card'],
            'plan' => $result['plan'],
            'pending_approvals' => $commandCenter->serializeApprovals(
                $commandCenter->pendingApprovals($user, $orgId)
            ),
        ]);
    }

    public function chat(Request $request, AgentOrchestrator $orchestrator): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $result = $orchestrator->handleWeb(
            user: $user,
            organizationId: $orgId,
            message: $data['message'],
            conversationId: $data['conversation_id'] ?? null,
        );

        $status = ($result['status'] ?? 'done') === 'queued' ? 202 : 200;

        return response()->json($result, $status);
    }

    public function clearChat(CommandCenterService $commandCenter): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $result = $commandCenter->startFreshConversation($user, $orgId);
        $pending = $commandCenter->pendingApprovals($user, $orgId);

        return response()->json([
            'conversation_id' => $result['conversation']->id,
            'archived_conversation_id' => $result['archived_conversation_id'],
            'messages' => [$result['welcome']],
            'has_older_messages' => false,
            'pending_approvals' => $commandCenter->serializeApprovals($pending),
            'pending_approvals_count' => $pending->count(),
        ]);
    }

    public function messages(Request $request, CommandCenterService $commandCenter): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $data = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $conversation = $commandCenter->conversation($user, $orgId);
        abort_unless((int) $conversation->id === (int) $data['conversation_id'], 404);

        if (array_key_exists('after_id', $data) && $data['after_id'] !== null) {
            $rows = $commandCenter->messagesAfter(
                $conversation,
                (int) $data['after_id'],
                (int) $request->query('limit', 50),
            );

            $pending = $commandCenter->pendingApprovals($user, $orgId);

            return response()->json([
                'messages' => $commandCenter->serializeMessages($rows),
                'pending_approvals' => $commandCenter->serializeApprovals($pending),
                'pending_approvals_count' => $pending->count(),
            ]);
        }

        $beforeId = isset($data['before_id']) ? (int) $data['before_id'] : null;
        $window = $commandCenter->historyWindow($conversation, $beforeId);

        return response()->json([
            'messages' => $commandCenter->serializeMessages($window['messages']),
            'has_older' => $window['has_older'],
        ]);
    }

    /**
     * @return list<array{key:string, label:string, connected:bool, enabled:bool, tier:string}>
     */
    private function integrationReadiness(
        OutreachChannelGuard $guard,
        AiChannelPolicyService $policy,
        int $userId,
    ): array {
        return $policy->readiness($userId, $guard);
    }

    public function whatsAppStatus(): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $identity = AiChannelIdentity::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $orgId)
            ->where('channel', 'whatsapp')
            ->where('status', 'active')
            ->first();

        return response()->json([
            'linked' => (bool) $identity,
            'phone' => $identity?->external_id,
            'verified_at' => $identity?->verified_at?->toIso8601String(),
        ]);
    }

    public function createWhatsAppLink(
        ChannelIdentityService $identityService,
        WhatsAppCommandLinkPresenter $presenter,
        ZernioClient $zernio,
    ): JsonResponse {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $code = $identityService->createLinkCode($user, $orgId, 'whatsapp');

        return response()->json(array_merge($presenter->payload($code), [
            'zernio_configured' => $zernio->configured(),
        ]));
    }

    public function disconnectWhatsApp(ChannelIdentityService $identityService): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $disconnected = $identityService->disconnect($user, $orgId, 'whatsapp');

        if (! $disconnected) {
            return response()->json(['message' => 'WhatsApp is not connected.'], 422);
        }

        return response()->json([
            'linked' => false,
            'phone' => null,
        ]);
    }

    public function decideApproval(
        Request $request,
        ActionApprovalService $approvals,
        CommandCenterService $commandCenter,
    ): JsonResponse {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $data = $request->validate([
            'approval_id' => ['required', 'integer'],
            'decision' => ['required', 'in:approve,reject'],
            'draft_text' => ['nullable', 'string', 'max:8000'],
        ]);

        $approval = $approvals->findPendingForUser($user, $orgId, (int) $data['approval_id']);
        abort_unless($approval, 404);

        if (
            $data['decision'] === 'approve'
            && ! empty($data['draft_text'])
            && (
                in_array($approval->tool, ['draft_reply', 'draft_personalized_message'], true)
                || in_array($approval->payload['type'] ?? '', ['draft_reply', 'personalized_message'], true)
            )
        ) {
            app(InboxCommandCenterService::class)->updateDraftText($approval, (string) $data['draft_text']);
            $approval = $approval->fresh();
        }

        if ($data['decision'] === 'reject') {
            $approvals->reject($approval, $user);
            $message = "Rejected plan #{$approval->id}.";
        } else {
            $integrationBlock = $commandCenter->launchIntegrationBlockMessage($approval, $user);
            if ($integrationBlock !== null) {
                $conversation = $commandCenter->conversation($user, $orgId);
                \App\Models\AiMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $integrationBlock,
                    'meta' => ['channel' => 'web', 'control' => 'blocked_launch'],
                ]);

                return response()->json([
                    'approval_id' => $approval->id,
                    'status' => $approval->status,
                    'payload' => $approval->payload,
                    'reply' => $integrationBlock,
                    'blocked' => true,
                    'pending_approvals' => $commandCenter->serializeApprovals(
                        $commandCenter->pendingApprovals($user, $orgId)
                    ),
                ]);
            }

            $approvals->approve($approval, $user);
            $message = $commandCenter->launchAcknowledged($approval->fresh(), $user);
        }

        $conversation = $commandCenter->conversation($user, $orgId);
        \App\Models\AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $message,
            'meta' => ['channel' => 'web', 'control' => $data['decision']],
        ]);

        return response()->json([
            'approval_id' => $approval->id,
            'status' => $approval->fresh()->status,
            'payload' => $approval->payload,
            'reply' => $message,
            'pending_approvals' => $commandCenter->serializeApprovals(
                $commandCenter->pendingApprovals($user, $orgId)
            ),
        ]);
    }

    public function updateSettings(
        Request $request,
        AiEmployeeSettingsService $settingsService,
        EntitlementService $entitlements,
    ): JsonResponse {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'employee_name' => ['sometimes', 'string', 'max:40'],
            'sender_display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'autonomy_level' => ['sometimes', 'integer', 'in:1,2,3,4'],
        ]);

        try {
            $updated = $settingsService->updateForUser(
                $user,
                $orgId,
                $data,
                allowAutonomous: $entitlements->isPlatformAdmin($user),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $meta = is_array($updated->meta) ? $updated->meta : [];

        return response()->json([
            'settings' => [
                'enabled' => (bool) $updated->enabled,
                'kill_switch' => (bool) $updated->kill_switch,
                'autonomy_level' => (int) $updated->autonomy_level,
                'autonomy_label' => app(\App\V2\Ai\Services\AutonomyContextService::class)
                    ->label((int) $updated->autonomy_level),
                'employee_name' => $updated->employee_name,
                'sender_display_name' => $meta['sender_display_name'] ?? null,
            ],
        ]);
    }

    public function stageExecutePlan(
        ExecuteSalesPlanCommandCenterService $executor,
        CommandCenterService $commandCenter,
    ): JsonResponse {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $conversation = $commandCenter->conversation($user, $orgId);

        try {
            $result = $executor->stage($user, $orgId, $conversation, 'web');
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'redirect' => url('/ai-employee'),
            ], 422);
        }

        if ($result['blocked'] ?? false) {
            return response()->json([
                'message' => $result['message'] ?? 'Blocked',
                'redirect' => url('/ai-employee'),
            ], 403);
        }

        $card = trim((string) ($result['card'] ?? ''));
        if ($card !== '') {
            \App\Models\AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $card,
                'meta' => [
                    'channel' => 'web',
                    'tool' => 'let_ai_execute',
                    'empty' => (bool) ($result['empty'] ?? false),
                    'approval_id' => $result['approval_id'] ?? null,
                ],
            ]);
        }

        return response()->json([
            'empty' => (bool) ($result['empty'] ?? false),
            'approval_id' => $result['approval_id'] ?? null,
            'card' => $card,
            'plan' => $result['plan'] ?? [],
            'message' => $result['message'] ?? null,
            'pending_approvals' => $commandCenter->serializeApprovals(
                $commandCenter->pendingApprovals($user, $orgId)
            ),
            'redirect' => url('/ai-employee'),
        ]);
    }
}
