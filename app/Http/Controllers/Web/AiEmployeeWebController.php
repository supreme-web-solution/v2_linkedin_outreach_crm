<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AiChannelIdentity;
use App\V2\Ai\Integrations\ZernioClient;
use App\V2\Ai\Services\ActionApprovalService;
use App\V2\Ai\Services\AgentOrchestrator;
use App\V2\Ai\Services\AiChannelPolicyService;
use App\V2\Ai\Services\AiEmployeeSettingsService;
use App\V2\Ai\Services\ChannelIdentityService;
use App\V2\Ai\Services\WhatsAppCommandLinkPresenter;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\ExecuteSalesPlanCommandCenterService;
use App\V2\Ai\Services\InboxCommandCenterService;
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
                'enabled' => $settings->enabled,
                'kill_switch' => $settings->kill_switch,
                'autonomy_level' => $settings->autonomy_level,
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
            'pending_approvals_count' => count($pending),
            'settings' => [
                'enabled' => $settings->enabled,
                'kill_switch' => $settings->kill_switch,
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

        $result = $orchestrator->handle(
            user: $user,
            organizationId: $orgId,
            message: $data['message'],
            channel: 'web',
            conversationId: $data['conversation_id'] ?? null,
        );

        return response()->json($result);
    }

    public function messages(Request $request, CommandCenterService $commandCenter): JsonResponse
    {
        $user = auth()->user();
        $orgId = (int) ($user->current_organization_id ?? 0);
        abort_unless($orgId > 0, 403);

        $data = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'before_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $conversation = $commandCenter->conversation($user, $orgId);
        abort_unless((int) $conversation->id === (int) $data['conversation_id'], 404);

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
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['blocked'] ?? false) {
            return response()->json(['message' => $result['message'] ?? 'Blocked'], 403);
        }

        if ($result['empty'] ?? false) {
            return response()->json([
                'empty' => true,
                'card' => $result['card'] ?? '',
            ]);
        }

        if (! empty($result['approval_id'])) {
            \App\Models\AiMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $result['card'] ?? '',
                'meta' => ['channel' => 'web', 'tool' => 'let_ai_execute'],
            ]);
        }

        return response()->json([
            'approval_id' => $result['approval_id'] ?? null,
            'card' => $result['card'] ?? '',
            'plan' => $result['plan'] ?? [],
            'pending_approvals' => $commandCenter->serializeApprovals(
                $commandCenter->pendingApprovals($user, $orgId)
            ),
            'redirect' => url('/ai-employee'),
        ]);
    }
}
