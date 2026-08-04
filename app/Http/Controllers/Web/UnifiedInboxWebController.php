<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\Models\V2OutreachCampaign;
use App\Models\V2OutreachLead;
use App\Models\V2OutreachLeadProgress;
use App\V2\Outreach\InboxAttachmentSupport;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\OpenAIContentService;
use App\V2\Services\OutreachChannelInboxSettingsService;
use App\V2\Services\EmailAddressQuality;
use App\V2\Services\EmailBodyFormatter;
use App\V2\Services\InboxUnreadService;
use App\V2\Services\UnifiedInboxService;
use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

class UnifiedInboxWebController extends Controller
{
    private const CONVERSATIONS_PER_PAGE = 20;

    public function __construct(
        private readonly UnifiedInboxService $inbox,
        private readonly InboxUnreadService $unread,
        private readonly OutreachChannelInboxSettingsService $channelSettings,
        private readonly EmailBodyFormatter $emailBodyFormatter,
        private readonly EmailAddressQuality $emailQuality,
    ) {
    }

    public function index(): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $platforms = OutreachChannelRegistry::inboxPlatforms();

        $unreadByPlatform = $this->unread->unreadCountsByPlatform($user->id, $platforms);

        $platformCards = [];
        foreach ($platforms as $platform) {
            $config = OutreachChannelRegistry::channels()[$platform] ?? [];
            $count = V2Conversation::query()
                ->where('user_id', $user->id)
                ->forInboxPlatform($platform)
                ->count();

            $recentInbound = V2Message::query()
                ->whereHas('conversation', fn ($q) => $q
                    ->where('user_id', $user->id)
                    ->forInboxPlatform($platform))
                ->where('direction', 'inbound')
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            $platformCards[] = [
                'key' => $platform,
                'label' => (string) ($config['label'] ?? ucfirst($platform)),
                'color' => (string) ($config['color'] ?? '#64748b'),
                'connected' => (bool) V2IntegrationAccount::activeUnipileAccountIdForProvider($user->id, $platform),
                'conversations_count' => $count,
                'recent_inbound_count' => $recentInbound,
                'unread_count' => $unreadByPlatform[$platform] ?? 0,
                'href' => route('inbox.platform', $platform),
            ];
        }

        return Inertia::render('crm/inbox/Index', [
            'platforms' => $platformCards,
        ]);
    }

    public function platform(Request $request, string $platform): Response|RedirectResponse
    {
        $this->channelSettings->assertInboxChannel($platform);

        /** @var User $user */
        $user = auth()->user();
        $search = trim((string) $request->query('search', ''));
        $campaignFilter = (int) $request->query('campaign', 0);
        $selectedId = (int) $request->query('id', 0);

        if ($platform === 'email') {
            $this->inbox->syncEmailInboxForUser($user->id);
        }

        if ($selectedId <= 0 && $search === '' && $campaignFilter <= 0) {
            $firstUnread = $this->unread->firstUnreadConversationId($user->id, $platform);
            if ($firstUnread !== null) {
                return redirect()->route('inbox.show', [$platform, $firstUnread]);
            }
        }

        $conversations = $this->paginateConversations($user, $platform, $request);

        $selected = null;
        $messages = [];
        $outreachContext = null;

        if ($selectedId > 0) {
            $thread = V2Conversation::query()
                ->where('user_id', $user->id)
                ->forInboxPlatform($platform)
                ->where('id', $selectedId)
                ->first();

            if ($thread) {
                $this->inbox->syncMessagesFromProvider($thread);
                $this->inbox->dedupeConversationMessages($thread);
                $thread->refresh();
                $this->unread->markAsRead($thread);
                $thread->refresh();
                $selected = $this->serializeThread($thread);
                $messages = $this->serializeMessages($thread);
                $outreachContext = $this->buildOutreachContext($thread, $user);
            }
        }

        $config = OutreachChannelRegistry::channels()[$platform] ?? [];

        return Inertia::render('crm/inbox/Platform', [
            'platform' => $platform,
            'platformLabel' => (string) ($config['label'] ?? ucfirst($platform)),
            'platformColor' => (string) ($config['color'] ?? '#64748b'),
            'connected' => (bool) V2IntegrationAccount::activeUnipileAccountIdForProvider($user->id, $platform),
            'conversations' => $conversations,
            'selected' => $selected,
            'messages' => $messages,
            'outreachContext' => $outreachContext,
            'unread_count' => $this->unread->unreadCountForUser($user->id, $platform),
            'aiConfigured' => app(OpenAIContentService::class)->isConfigured(),
            'supportsAttachments' => InboxAttachmentSupport::supportsAttachments($platform),
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'campaign' => $campaignFilter > 0 ? $campaignFilter : null,
                'id' => $selectedId > 0 ? $selectedId : null,
            ],
        ]);
    }

    public function show(Request $request, string $platform, int $id): Response
    {
        $this->channelSettings->assertInboxChannel($platform);

        /** @var User $user */
        $user = auth()->user();
        $search = trim((string) $request->query('search', ''));

        $thread = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forInboxPlatform($platform)
            ->where('id', $id)
            ->firstOrFail();

        $this->inbox->syncMessagesFromProvider($thread);
        $this->inbox->dedupeConversationMessages($thread);
        $thread->refresh();
        $this->unread->markAsRead($thread);
        $thread->refresh();

        $config = OutreachChannelRegistry::channels()[$platform] ?? [];
        $outreachContext = $this->buildOutreachContext($thread, $user);

        return Inertia::render('crm/inbox/Platform', [
            'platform' => $platform,
            'platformLabel' => (string) ($config['label'] ?? ucfirst($platform)),
            'platformColor' => (string) ($config['color'] ?? '#64748b'),
            'connected' => (bool) V2IntegrationAccount::activeUnipileAccountIdForProvider($user->id, $platform),
            'conversations' => $this->paginateConversations($user, $platform, $request),
            'selected' => $this->serializeThread($thread),
            'messages' => $this->serializeMessages($thread),
            'outreachContext' => $outreachContext,
            'unread_count' => $this->unread->unreadCountForUser($user->id, $platform),
            'aiConfigured' => app(OpenAIContentService::class)->isConfigured(),
            'supportsAttachments' => InboxAttachmentSupport::supportsAttachments($platform),
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'campaign' => null,
                'id' => $id,
            ],
        ]);
    }

    public function poll(Request $request, string $platform, int $id): JsonResponse
    {
        $this->channelSettings->assertInboxChannel($platform);

        /** @var User $user */
        $user = auth()->user();

        $thread = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forInboxPlatform($platform)
            ->where('id', $id)
            ->firstOrFail();

        $this->inbox->syncMessagesFromProvider($thread);
        $this->inbox->dedupeConversationMessages($thread);
        $thread->refresh();
        $this->unread->markAsRead($thread);
        $thread->refresh();

        $outreachContext = $this->buildOutreachContext($thread, $user);

        return response()->json([
            'messages' => $this->serializeMessages($thread),
            'conversations' => $this->serializeConversationsPaginator(
                $this->paginateConversations($user, $platform, $request)
            ),
            'selected' => $this->serializeThread($thread),
            'outreachContext' => $outreachContext,
            'unread_count' => $this->unread->unreadCountForUser($user->id, $platform),
        ]);
    }

    public function send(Request $request, string $platform, int $id): RedirectResponse
    {
        $this->channelSettings->assertInboxChannel($platform);

        /** @var User $user */
        $user = auth()->user();

        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forInboxPlatform($platform)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:8000'],
            'attachment' => ['nullable', 'file', 'max:15360'],
        ]);

        if (trim((string) ($data['body'] ?? '')) === '' && ! $request->hasFile('attachment')) {
            return back()->withErrors(['body' => 'Enter a message or attach a file.']);
        }

        try {
            $this->inbox->sendMessage(
                $user,
                $conversation,
                trim((string) ($data['body'] ?? '')),
                $request->file('attachment'),
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Message sent.');
    }

    public function destroy(string $platform, int $id): RedirectResponse
    {
        $this->channelSettings->assertInboxChannel($platform);

        /** @var User $user */
        $user = auth()->user();

        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forInboxPlatform($platform)
            ->where('id', $id)
            ->firstOrFail();

        $name = $this->prospectName($conversation) ?? 'Conversation';
        $conversation->delete();

        return redirect()
            ->route('inbox.platform', $platform)
            ->with('success', "{$name} removed from your inbox.");
    }

    public function destroyMessage(string $platform, int $id, int $messageId): RedirectResponse
    {
        $this->channelSettings->assertInboxChannel($platform);

        /** @var User $user */
        $user = auth()->user();

        $conversation = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forInboxPlatform($platform)
            ->where('id', $id)
            ->firstOrFail();

        $message = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', $messageId)
            ->firstOrFail();

        $message->delete();

        $latest = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('received_at')
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->first();

        $conversation->forceFill([
            'last_message_at' => ($latest?->received_at ?? $latest?->sent_at ?? $latest?->created_at),
        ])->save();

        return back()->with('success', 'Message removed.');
    }

    public function attachment(
        string $platform,
        int $id,
        string $messageId,
        string $attachmentId,
    ): StreamedResponse|RedirectResponse {
        $this->channelSettings->assertInboxChannel($platform);

        /** @var User $user */
        $user = auth()->user();

        $thread = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forInboxPlatform($platform)
            ->where('id', $id)
            ->firstOrFail();

        $message = V2Message::query()
            ->where('conversation_id', $thread->id)
            ->where('provider_message_id', $messageId)
            ->firstOrFail();

        $attachments = is_array($message->attachments) ? $message->attachments : [];
        $allowed = collect($attachments)->first(fn ($item) => is_array($item) && (string) ($item['id'] ?? '') === $attachmentId);
        if (! $allowed) {
            abort(404);
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountIdForProvider($user->id, $platform);
        if (! $accountId) {
            abort(404);
        }

        $response = app(UnipileProvider::class)->downloadMessageAttachment($messageId, $attachmentId, [
            'account_id' => $accountId,
        ]);

        if (! $response->successful()) {
            abort($response->status() ?: 502);
        }

        $filename = (string) ($allowed['filename'] ?? 'attachment');
        $mime = (string) ($allowed['mimetype'] ?? $response->header('Content-Type') ?? 'application/octet-stream');

        return response()->streamDownload(function () use ($response) {
            echo $response->body();
        }, $filename, [
            'Content-Type' => $mime,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildOutreachContext(V2Conversation $conversation, User $user): ?array
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $campaignId = (int) (Arr::get($meta, 'outreach_campaign_id') ?? 0);
        $leadId = (int) (Arr::get($meta, 'outreach_lead_id') ?? 0);
        $provider = (string) $conversation->provider;

        if ($campaignId <= 0) {
            return null;
        }

        $campaign = V2OutreachCampaign::query()
            ->where('id', $campaignId)
            ->where('user_id', $user->id)
            ->first();

        if (! $campaign) {
            return null;
        }

        $lead = $leadId > 0 ? V2OutreachLead::query()->find($leadId) : null;
        $progress = $lead
            ? V2OutreachLeadProgress::query()
                ->where('outreach_campaign_id', $campaign->id)
                ->where('outreach_lead_id', $lead->id)
                ->first()
            : null;

        $campaignOutboundCount = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->where('meta->source', 'outreach_campaign')
            ->count();

        $channelConfig = $this->channelSettings->forCampaignChannel($campaign, $provider);

        return [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'href' => route('outreach.show', $campaign->id),
            ],
            'lead' => $lead ? [
                'id' => $lead->id,
                'full_name' => $lead->full_name,
                'status' => $lead->status,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'email_quality' => $provider === 'email'
                    ? $this->emailQuality->assess($lead->email)
                    : null,
            ] : null,
            'progress' => $progress ? [
                'paused_reason' => Arr::get($progress->meta ?? [], 'paused_reason'),
                'paused_channel' => Arr::get($progress->meta ?? [], 'paused_channel'),
                'channel_replied' => (bool) Arr::get($progress->channel_state ?? [], "{$provider}.replied", false),
            ] : null,
            'campaign_outbound_count' => $campaignOutboundCount,
            'channel_settings' => $channelConfig,
            'settings_update_url' => route('outreach.channel-inbox', [$campaign->id, $provider]),
        ];
    }

    private function paginateConversations(User $user, string $platform, Request $request): LengthAwarePaginator
    {
        $search = trim((string) $request->query('search', ''));
        $campaignFilter = (int) $request->query('campaign', 0);

        $query = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forInboxPlatform($platform)
            ->withCount('messages');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('provider_chat_id', 'like', '%'.$search.'%')
                    ->orWhere('meta', 'like', '%'.$search.'%');
            });
        }

        if ($campaignFilter > 0) {
            $query->where('meta->outreach_campaign_id', $campaignFilter);
        }

        $paginator = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(self::CONVERSATIONS_PER_PAGE)
            ->withQueryString();

        $paginator->setCollection(
            $this->serializeListItems($paginator->getCollection())
        );

        return $paginator;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, V2Conversation>  $conversations
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function serializeListItems($conversations)
    {
        if ($conversations->isEmpty()) {
            return $conversations;
        }

        $ids = $conversations->pluck('id')->all();
        $idList = implode(',', array_map('intval', $ids));

        $previewRows = $idList === ''
            ? []
            : DB::select("
                SELECT conversation_id, body FROM (
                    SELECT conversation_id, body,
                        ROW_NUMBER() OVER (
                            PARTITION BY conversation_id
                            ORDER BY received_at DESC, sent_at DESC, created_at DESC, id DESC
                        ) AS rn
                    FROM v2_messages
                    WHERE conversation_id IN ({$idList})
                      AND body IS NOT NULL
                      AND body != ''
                ) ranked
                WHERE rn = 1
            ");

        $previews = collect($previewRows)->mapWithKeys(
            fn ($row) => [(int) $row->conversation_id => (string) $row->body]
        );

        $campaignIds = $conversations
            ->map(fn (V2Conversation $c) => (int) (Arr::get($c->meta ?? [], 'outreach_campaign_id') ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $campaignNames = $campaignIds === []
            ? collect()
            : V2OutreachCampaign::query()->whereIn('id', $campaignIds)->pluck('name', 'id');

        $unreadMap = $this->unread->unreadMap($conversations);

        return $conversations->map(function (V2Conversation $conversation) use ($previews, $campaignNames, $unreadMap) {
            $preview = $previews[$conversation->id] ?? null;
            $meta = is_array($conversation->meta) ? $conversation->meta : [];
            $prospectEmail = trim((string) (
                Arr::get($meta, 'prospect_email')
                ?? ($conversation->provider === 'email' ? $conversation->provider_chat_id : '')
                ?? ''
            ));
            $formattedPreview = $preview
                ? ($conversation->provider === 'email'
                    ? $this->emailBodyFormatter->preview((string) $preview)
                    : mb_substr((string) $preview, 0, 120))
                : null;
            $campaignId = (int) (Arr::get($meta, 'outreach_campaign_id') ?? 0);

            return [
                'id' => $conversation->id,
                'provider' => $conversation->provider,
                'channel_label' => Arr::get($meta, 'channel_label') ?: OutreachChannelRegistry::channelLabel((string) $conversation->provider),
                'prospect_name' => $this->prospectName($conversation),
                'prospect_headline' => Arr::get($meta, 'prospect_headline'),
                'prospect_email' => $prospectEmail !== '' ? $prospectEmail : null,
                'email_quality' => $conversation->provider === 'email'
                    ? $this->emailQuality->assess($prospectEmail)
                    : null,
                'status' => $conversation->status,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'messages_count' => (int) ($conversation->messages_count ?? 0),
                'last_message_preview' => $formattedPreview,
                'outreach_campaign_id' => Arr::get($meta, 'outreach_campaign_id'),
                'outreach_campaign_name' => $campaignId > 0 ? ($campaignNames[$campaignId] ?? null) : null,
                'outreach_lead_id' => Arr::get($meta, 'outreach_lead_id'),
                'is_unread' => (bool) ($unreadMap[$conversation->id] ?? false),
            ];
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeConversationsPaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_values($paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'next_page_url' => $paginator->nextPageUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeThread(V2Conversation $conversation): array
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $provider = (string) $conversation->provider;

        $prospectEmail = trim((string) (
            Arr::get($meta, 'prospect_email')
            ?? ($provider === 'email' ? $conversation->provider_chat_id : '')
            ?? ''
        ));

        return [
            'id' => $conversation->id,
            'provider' => $provider,
            'channel_label' => Arr::get($meta, 'channel_label') ?: OutreachChannelRegistry::channelLabel($provider),
            'provider_chat_id' => $conversation->provider_chat_id,
            'prospect_name' => $this->prospectName($conversation),
            'prospect_email' => $prospectEmail !== '' ? $prospectEmail : null,
            'prospect_headline' => Arr::get($meta, 'prospect_headline'),
            'status' => $conversation->status,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'has_channel' => (bool) V2IntegrationAccount::activeUnipileAccountIdForProvider((int) $conversation->user_id, $provider),
            'outreach_campaign_id' => Arr::get($meta, 'outreach_campaign_id'),
            'outreach_lead_id' => Arr::get($meta, 'outreach_lead_id'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeMessages(V2Conversation $conversation): array
    {
        $normalizer = app(\App\V2\Services\UnipileMessageNormalizer::class);

        return V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByRaw('COALESCE(received_at, sent_at, created_at) ASC')
            ->orderBy('id')
            ->get()
            ->filter(fn (V2Message $message) => ! $normalizer->isReactionAnnouncementText((string) ($message->body ?? '')))
            ->map(function (V2Message $message) use ($conversation, $normalizer) {
                $rawBody = (string) ($message->body ?? '');
                $formatted = $conversation->provider === 'email'
                    ? $this->emailBodyFormatter->format($rawBody)
                    : null;

                return [
                'id' => $message->id,
                'provider_message_id' => $message->provider_message_id,
                'direction' => $message->direction,
                'body' => $rawBody,
                'formatted' => $formatted,
                'at' => ($message->received_at ?? $message->sent_at ?? $message->created_at)?->toIso8601String(),
                'source' => Arr::get($message->meta ?? [], 'source'),
                'attachments' => collect(is_array($message->attachments) ? $message->attachments : [])
                    ->filter(fn ($item) => is_array($item) && ! empty($item['id']))
                    ->map(fn (array $item) => [
                        'id' => (string) $item['id'],
                        'filename' => (string) ($item['filename'] ?? 'file'),
                        'mimetype' => (string) ($item['mimetype'] ?? 'application/octet-stream'),
                        'type' => (string) ($item['type'] ?? 'file'),
                        'unavailable' => (bool) ($item['unavailable'] ?? false),
                        'url' => $message->provider_message_id
                            ? route('inbox.attachment', [
                                'platform' => $conversation->provider,
                                'id' => $conversation->id,
                                'messageId' => $message->provider_message_id,
                                'attachmentId' => $item['id'],
                            ])
                            : null,
                    ])
                    ->values()
                    ->all(),
                'reactions' => collect(is_array(Arr::get($message->meta, 'reactions')) ? Arr::get($message->meta, 'reactions') : [])
                    ->filter(fn ($item) => is_array($item) && ! empty($item['value']))
                    ->map(fn (array $item) => [
                        'value' => (string) $item['value'],
                        'is_sender' => (bool) ($item['is_sender'] ?? false),
                    ])
                    ->values()
                    ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function prospectName(V2Conversation $conversation): ?string
    {
        $fromMeta = trim((string) Arr::get($conversation->meta ?? [], 'prospect_name', ''));

        return $fromMeta !== '' ? $fromMeta : null;
    }
}
