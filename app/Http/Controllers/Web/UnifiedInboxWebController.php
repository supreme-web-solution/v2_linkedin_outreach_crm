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
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Services\OpenAIContentService;
use App\V2\Services\OutreachChannelInboxSettingsService;
use App\V2\Services\UnifiedInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class UnifiedInboxWebController extends Controller
{
    /** @var array<int, string> */
    private const PLATFORMS = ['linkedin', 'whatsapp', 'instagram', 'telegram', 'twitter', 'email'];

    public function __construct(
        private readonly UnifiedInboxService $inbox,
        private readonly OutreachChannelInboxSettingsService $channelSettings,
    ) {
    }

    public function index(): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $platforms = [];
        foreach (self::PLATFORMS as $platform) {
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

            $platforms[] = [
                'key' => $platform,
                'label' => (string) ($config['label'] ?? ucfirst($platform)),
                'color' => (string) ($config['color'] ?? '#64748b'),
                'connected' => (bool) V2IntegrationAccount::activeUnipileAccountIdForProvider($user->id, $platform),
                'conversations_count' => $count,
                'recent_inbound_count' => $recentInbound,
                'href' => route('inbox.platform', $platform),
            ];
        }

        return Inertia::render('crm/inbox/Index', [
            'platforms' => $platforms,
        ]);
    }

    public function platform(Request $request, string $platform): Response
    {
        $this->channelSettings->assertInboxChannel($platform);

        /** @var User $user */
        $user = auth()->user();
        $search = trim((string) $request->query('search', ''));
        $campaignFilter = (int) $request->query('campaign', 0);
        $selectedId = (int) $request->query('id', 0);

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

        $conversations = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (V2Conversation $conversation) => $this->serializeListItem($conversation));

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
            'aiConfigured' => app(OpenAIContentService::class)->isConfigured(),
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'campaign' => $campaignFilter > 0 ? $campaignFilter : null,
                'id' => $selectedId > 0 ? $selectedId : null,
            ],
        ]);
    }

    public function show(string $platform, int $id): Response
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
        $thread->refresh();

        $config = OutreachChannelRegistry::channels()[$platform] ?? [];

        $conversations = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forInboxPlatform($platform)
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (V2Conversation $conversation) => $this->serializeListItem($conversation));

        return Inertia::render('crm/inbox/Platform', [
            'platform' => $platform,
            'platformLabel' => (string) ($config['label'] ?? ucfirst($platform)),
            'platformColor' => (string) ($config['color'] ?? '#64748b'),
            'connected' => (bool) V2IntegrationAccount::activeUnipileAccountIdForProvider($user->id, $platform),
            'conversations' => $conversations,
            'selected' => $this->serializeThread($thread),
            'messages' => $this->serializeMessages($thread),
            'outreachContext' => $this->buildOutreachContext($thread, $user),
            'aiConfigured' => app(OpenAIContentService::class)->isConfigured(),
            'filters' => ['search' => null, 'campaign' => null, 'id' => $id],
        ]);
    }

    public function poll(string $platform, int $id): JsonResponse
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
        $thread->refresh();

        $conversations = V2Conversation::query()
            ->where('user_id', $user->id)
            ->forInboxPlatform($platform)
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (V2Conversation $conversation) => $this->serializeListItem($conversation));

        return response()->json([
            'messages' => $this->serializeMessages($thread),
            'conversations' => $conversations,
            'selected' => $this->serializeThread($thread),
            'outreachContext' => $this->buildOutreachContext($thread, $user),
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
            'body' => ['required', 'string', 'max:8000'],
        ]);

        try {
            $this->inbox->sendMessage($user, $conversation, $data['body']);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Message queued via Unipile.');
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

    /**
     * @return array<string, mixed>
     */
    private function serializeListItem(V2Conversation $conversation): array
    {
        $preview = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderByDesc('received_at')
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->value('body');

        $meta = is_array($conversation->meta) ? $conversation->meta : [];

        return [
            'id' => $conversation->id,
            'provider' => $conversation->provider,
            'channel_label' => Arr::get($meta, 'channel_label') ?: OutreachChannelRegistry::channelLabel((string) $conversation->provider),
            'prospect_name' => $this->prospectName($conversation),
            'prospect_headline' => Arr::get($meta, 'prospect_headline'),
            'status' => $conversation->status,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'messages_count' => (int) ($conversation->messages_count ?? 0),
            'last_message_preview' => $preview ? mb_substr((string) $preview, 0, 120) : null,
            'outreach_campaign_id' => Arr::get($meta, 'outreach_campaign_id'),
            'outreach_campaign_name' => $this->campaignName((int) (Arr::get($meta, 'outreach_campaign_id') ?? 0)),
            'outreach_lead_id' => Arr::get($meta, 'outreach_lead_id'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeThread(V2Conversation $conversation): array
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $provider = (string) $conversation->provider;

        return [
            'id' => $conversation->id,
            'provider' => $provider,
            'channel_label' => Arr::get($meta, 'channel_label') ?: OutreachChannelRegistry::channelLabel($provider),
            'provider_chat_id' => $conversation->provider_chat_id,
            'prospect_name' => $this->prospectName($conversation),
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
        return V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (V2Message $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'body' => (string) ($message->body ?? ''),
                'at' => ($message->received_at ?? $message->sent_at ?? $message->created_at)?->toIso8601String(),
                'source' => Arr::get($message->meta ?? [], 'source'),
            ])
            ->all();
    }

    private function prospectName(V2Conversation $conversation): ?string
    {
        $fromMeta = trim((string) Arr::get($conversation->meta ?? [], 'prospect_name', ''));

        return $fromMeta !== '' ? $fromMeta : null;
    }

    private function campaignName(int $campaignId): ?string
    {
        if ($campaignId <= 0) {
            return null;
        }

        return V2OutreachCampaign::query()->where('id', $campaignId)->value('name');
    }
}
