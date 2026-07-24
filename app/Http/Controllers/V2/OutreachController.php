<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Jobs\V2\ProcessOutboundOutreachJob;
use App\Models\V2IntegrationAccount;
use App\Models\V2UserActivity;
use App\V2\DTO\OutreachInviteRequestData;
use App\V2\DTO\OutreachMessageRequestData;
use App\V2\DTO\OutreachStartChatRequestData;
use App\V2\Integrations\ProviderManager;
use App\V2\Services\LinkedInConnectionService;
use App\V2\Services\OutreachPersistenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutreachController extends Controller
{
    public function __construct(
        private readonly OutreachPersistenceService $persistenceService,
        private readonly ProviderManager $providerManager,
        private readonly LinkedInConnectionService $linkedin,
    ) {}

    /**
     * Resolve the Unipile account_id for the current user, or return a 422 response.
     */
    private function requireUnipileAccountId(mixed $user): string|\Illuminate\Http\JsonResponse
    {
        $id = V2IntegrationAccount::activeUnipileAccountId($user->id);
        if (!$id) {
            return response()->json([
                'message'    => 'No connected LinkedIn account. Connect your LinkedIn account first via the extension Settings.',
                'error_code' => 'no_linkedin_account',
            ], 422);
        }
        return $id;
    }

    /**
     * List pending received connection invitations from LinkedIn.
     */
    public function listInvitations(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $accountId = $this->requireUnipileAccountId($user);
        if ($accountId instanceof \Illuminate\Http\JsonResponse) return $accountId;

        try {
            $providerKey = $this->providerManager->defaultProvider();
            $result = $this->providerManager->invitation($providerKey)->listReceivedInvitations([
                'account_id' => $accountId,
            ]);
            return response()->json(['data' => $result]);
        } catch (\Throwable $e) {
            $this->linkedin->handleUnipileFailure($user, $e);

            return response()->json(['data' => [], 'error' => $e->getMessage()]);
        }
    }

    /**
     * List sent (pending) connection invitations — for withdraw flow.
     */
    public function listSentInvitations(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $accountId = $this->requireUnipileAccountId($user);
        if ($accountId instanceof \Illuminate\Http\JsonResponse) {
            return $accountId;
        }

        try {
            $providerKey = $this->providerManager->defaultProvider();
            $result = $this->providerManager->invitation($providerKey)->listSentInvitations(array_filter([
                'account_id' => $accountId,
                'limit' => (int) $request->query('limit', 100),
                'cursor' => $request->query('cursor'),
            ], fn ($value) => $value !== null && $value !== ''));

            return response()->json(['data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['data' => [], 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * List 1st-degree connections (relations) for bulk messaging / profile actions.
     */
    public function listRelations(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $accountId = $this->requireUnipileAccountId($user);
        if ($accountId instanceof \Illuminate\Http\JsonResponse) {
            return $accountId;
        }

        try {
            $providerKey = $this->providerManager->defaultProvider();
            $result = $this->providerManager->profile($providerKey)->listRelations([
                'account_id' => $accountId,
                'limit' => (int) $request->query('limit', 50),
                'cursor' => $request->query('cursor'),
            ]);

            return response()->json(['data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['data' => [], 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Reject a received connection invitation.
     */
    public function rejectInvitation(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $accountId = $this->requireUnipileAccountId($user);
        if ($accountId instanceof \Illuminate\Http\JsonResponse) {
            return $accountId;
        }

        $data = $request->validate([
            'invitation_id' => ['required', 'string'],
            'shared_secret' => ['required', 'string'],
        ]);
        try {
            $providerKey = $this->providerManager->defaultProvider();
            $result = $this->providerManager->invitation($providerKey)->handleReceivedInvitation(
                $data['invitation_id'],
                'reject',
                [
                    'account_id' => $accountId,
                    'shared_secret' => $data['shared_secret'],
                ]
            );

            return response()->json(['data' => $result, 'rejected' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Accept a received connection invitation.
     */
    public function acceptInvitation(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $accountId = $this->requireUnipileAccountId($user);
        if ($accountId instanceof \Illuminate\Http\JsonResponse) return $accountId;

        $data = $request->validate([
            'invitation_id' => ['required', 'string'],
            'shared_secret' => ['required', 'string'],
        ]);
        try {
            $providerKey = $this->providerManager->defaultProvider();
            $result = $this->providerManager->invitation($providerKey)->handleReceivedInvitation(
                $data['invitation_id'],
                'accept',
                [
                    'account_id' => $accountId,
                    'shared_secret' => $data['shared_secret'],
                ]
            );
            return response()->json(['data' => $result, 'accepted' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Withdraw / cancel a sent connection invitation.
     */
    public function withdrawInvitation(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $accountId = $this->requireUnipileAccountId($user);
        if ($accountId instanceof \Illuminate\Http\JsonResponse) return $accountId;

        $data = $request->validate(['invitation_id' => ['required', 'string']]);
        try {
            $providerKey = $this->providerManager->defaultProvider();
            $result = $this->providerManager->invitation($providerKey)->cancelInvitation(
                $data['invitation_id'],
                ['account_id' => $accountId]
            );
            return response()->json(['data' => $result, 'withdrawn' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Perform a LinkedIn profile action: view_profile, follow, endorse.
     */
    public function profileAction(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $accountId = $this->requireUnipileAccountId($user);
        if ($accountId instanceof \Illuminate\Http\JsonResponse) return $accountId;

        $data = $request->validate([
            'profile_id' => ['required', 'string'],
            'action'     => ['required', 'string', 'in:view_profile,follow,endorse,unfollow'],
        ]);
        if ($data['action'] === 'follow') {
            $data['action'] = 'view_profile';
        }
        if ($data['action'] === 'unfollow') {
            return response()->json(['error' => 'LinkedIn unfollow is not supported via Unipile.'], 422);
        }
        try {
            $providerKey = $this->providerManager->defaultProvider();
            /** @var \App\V2\Integrations\Unipile\UnipileProvider $provider */
            $provider = $this->providerManager->profile($providerKey);
            $result = $provider->performLinkedinProfileAction(
                $data['action'],
                [
                    'account_id' => $accountId,
                    'provider_id' => $data['profile_id'],
                    'profile_id' => $data['profile_id'],
                ]
            );

            if ($data['action'] === 'view_profile') {
                $organizationId = (int) $request->attributes->get('v2OrganizationId');
                if ($organizationId > 0) {
                    V2UserActivity::query()->create([
                        'user_id' => $user->id,
                        'organization_id' => $organizationId,
                        'module' => 'outreach',
                        'identifier' => 'view_profile',
                        'stat' => 1,
                        'meta' => ['profile_id' => $data['profile_id']],
                    ]);
                }
            }

            return response()->json(['data' => $result, 'action' => $data['action']]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Resolve a LinkedIn identifier (vanity slug, fsd id, etc.) to a Unipile provider_id.
     */
    public function resolveAttendee(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $accountId = $this->requireUnipileAccountId($user);
        if ($accountId instanceof \Illuminate\Http\JsonResponse) {
            return $accountId;
        }

        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:191'],
        ]);

        try {
            $providerKey = $this->providerManager->defaultProvider();
            /** @var \App\V2\Integrations\Unipile\UnipileProvider $provider */
            $provider = $this->providerManager->profile($providerKey);
            $resolved = $provider->resolveProviderId($data['identifier'], ['account_id' => $accountId]);

            if (empty($resolved['provider_id'])) {
                return response()->json(['message' => 'Could not resolve LinkedIn profile id.'], 422);
            }

            return response()->json(['data' => $resolved]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sendInvitation(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $accountId = $this->requireUnipileAccountId($user);
        if ($accountId instanceof \Illuminate\Http\JsonResponse) return $accountId;

        $data = OutreachInviteRequestData::fromRequest($request);
        $data['_unipile_account_id'] = $accountId;

        $lead = $this->persistenceService->findOrCreateLead($user->id, (string) $data['recipient_id']);
        $conversation = $this->persistenceService->findOrCreateConversation(
            $user->id,
            $organizationId,
            $lead?->id,
            null,
            ['recipient_id' => $data['recipient_id']]
        );

        $message = $this->persistenceService->createOutboundMessage(
            $conversation->id,
            $data['message'] ?? null,
            'invite',
            ['recipient_id' => $data['recipient_id']]
        );

        ProcessOutboundOutreachJob::dispatch(
            'invite',
            $user->id,
            $organizationId,
            $conversation->id,
            $message->id,
            $data
        );

        return response()->json([
            'data' => [
                'queued' => true,
                'action' => 'invite',
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ],
        ], 202);
    }

    public function startChat(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $accountId = $this->requireUnipileAccountId($user);
        if ($accountId instanceof \Illuminate\Http\JsonResponse) return $accountId;

        $data = OutreachStartChatRequestData::fromRequest($request);
        $data['_unipile_account_id'] = $accountId;

        $lead = $this->persistenceService->findOrCreateLead($user->id, (string) ($data['attendee_ids'][0] ?? ''));
        $conversation = $this->persistenceService->findOrCreateConversation(
            $user->id,
            $organizationId,
            $lead?->id,
            null,
            ['attendee_ids' => $data['attendee_ids']]
        );

        $message = $this->persistenceService->dispatchOutboundToConversation(
            $user->id,
            $organizationId,
            $conversation,
            (string) ($data['text'] ?? ''),
            (string) ($data['attendee_ids'][0] ?? ''),
            ['attendee_ids' => $data['attendee_ids']]
        );

        return response()->json([
            'data' => [
                'queued' => true,
                'action' => $conversation->provider_chat_id ? 'message' : 'start_chat',
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ],
        ], 202);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $user = $request->attributes->get('v2User');
        $organizationId = (int) $request->attributes->get('v2OrganizationId');

        $accountId = $this->requireUnipileAccountId($user);
        if ($accountId instanceof \Illuminate\Http\JsonResponse) return $accountId;

        $data = OutreachMessageRequestData::fromRequest($request);
        $data['_unipile_account_id'] = $accountId;

        $conversation = $this->persistenceService->findOrCreateConversation(
            $user->id,
            $organizationId,
            null,
            (string) $data['chat_id']
        );

        $message = $this->persistenceService->createOutboundMessage(
            $conversation->id,
            (string) $data['text'],
            'message',
            ['chat_id' => $data['chat_id']]
        );

        ProcessOutboundOutreachJob::dispatch(
            'message',
            $user->id,
            $organizationId,
            $conversation->id,
            $message->id,
            $data
        );

        return response()->json([
            'data' => [
                'queued' => true,
                'action' => 'message',
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ],
        ], 202);
    }
}
