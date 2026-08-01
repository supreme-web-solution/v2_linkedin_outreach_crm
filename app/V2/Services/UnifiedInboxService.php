<?php

namespace App\V2\Services;

use App\Models\User;
use App\Models\V2Conversation;
use App\Models\V2IntegrationAccount;
use App\Models\V2Message;
use App\Models\V2OutreachLead;
use App\V2\Integrations\Unipile\UnipileException;
use App\V2\Integrations\Unipile\UnipileProvider;
use App\V2\Outreach\InboxAttachmentSupport;
use App\V2\Outreach\OutreachChannelRegistry;
use App\V2\Outreach\OutreachSendProof;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class UnifiedInboxService
{
    /**
     * Resolve or create a multi-channel inbox thread from a Unipile webhook.
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolveConversationForWebhook(int $userId, string $chatId, array $payload = []): ?V2Conversation
    {
        if ($chatId === '') {
            return null;
        }

        $provider = $this->resolveProviderFromPayload($payload);
        if ($provider === null) {
            return null;
        }

        $isGroupChat = $this->isGroupChatPayload($payload, $chatId, $provider);

        if ($isGroupChat) {
            $existing = $this->findConversationByProviderChatId($userId, $provider, $chatId);
            if ($existing && $existing->isInboxThread() && $this->isTruthy(Arr::get($existing->meta, 'is_group'))) {
                return $this->enrichConversationMeta($existing, $payload);
            }

            \Illuminate\Support\Facades\Log::info('[Inbox] Ignored group chat webhook for 1:1 outreach inbox', [
                'user_id' => $userId,
                'provider' => $provider,
                'chat_id' => $chatId,
            ]);

            return null;
        }

        $existing = $this->findConversationByProviderChatId($userId, $provider, $chatId);

        if ($existing) {
            if ($existing->isInboxThread()) {
                return $this->enrichConversationMeta($existing, $payload);
            }

            $stale = $this->findStaleOutreachConversation($userId, $provider, $chatId, $this->extractAttendeeIds($payload), $payload);
            if ($stale) {
                return $this->mergeOrphanIntoOutreachConversation(
                    $stale,
                    $existing,
                    $chatId,
                    $payload
                );
            }
        }

        $attendeeIds = $this->extractAttendeeIds($payload);
        $outreachLead = $this->matchOutreachLead($userId, $provider, $attendeeIds, $payload);

        if ($outreachLead) {
            $stale = V2Conversation::query()
                ->where('user_id', $userId)
                ->where('provider', $provider)
                ->forUnifiedInbox()
                ->where('meta->outreach_lead_id', $outreachLead->id)
                ->where(function ($query) use ($chatId) {
                    $query->whereNull('provider_chat_id')
                        ->orWhere('provider_chat_id', '!=', $chatId);
                })
                ->orderByDesc('id')
                ->first();

            if ($stale) {
                $this->repairConversationChatId($stale, $chatId, $payload);
                $this->rememberInstagramAttendeeAliases($outreachLead, $attendeeIds);

                return $this->enrichConversationMeta($stale->fresh() ?? $stale, $payload);
            }
        }

        $stale = $this->findStaleOutreachConversation($userId, $provider, $chatId, $attendeeIds, $payload);
        if ($stale) {
            $this->repairConversationChatId($stale, $chatId, $payload);
            $lead = $this->resolveOutreachLeadFromConversation($stale);
            if ($lead) {
                $this->rememberInstagramAttendeeAliases($lead, $attendeeIds);
            }

            return $this->enrichConversationMeta($stale->fresh() ?? $stale, $payload);
        }

        // Unified Inbox is for outreach replies only — do not import every WhatsApp/IG DM.
        if (! $outreachLead) {
            return null;
        }

        $contactName = $this->extractContactName($payload, $outreachLead);

        return V2Conversation::query()->create([
            'user_id' => $userId,
            'provider' => $provider,
            'provider_chat_id' => $chatId,
            'status' => 'active',
            'last_message_at' => now(),
            'meta' => array_filter([
                'source' => 'unified_inbox',
                'prospect_name' => $contactName,
                'prospect_headline' => $outreachLead?->headline,
                'outreach_lead_id' => $outreachLead?->id,
                'outreach_campaign_id' => $outreachLead?->outreach_campaign_id,
                'attendee_ids' => $attendeeIds !== [] ? $attendeeIds : null,
                'channel_label' => OutreachChannelRegistry::channelLabel($provider),
            ], fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    /**
     * Link an outbound multi-channel campaign message to the unified inbox.
     *
     * @param  array<string, mixed>  $response
     */
    public function recordOutboundChat(
        int $userId,
        int $organizationId,
        string $channel,
        V2OutreachLead $lead,
        string $recipientId,
        array $response,
        string $messageText,
    ): ?V2Conversation {
        $proof = OutreachSendProof::fromResponse($response);
        $chatId = $proof['chat_id'];
        $providerMessageId = $proof['provider_message_id'];

        $existing = V2Conversation::query()
            ->where('user_id', $userId)
            ->where('provider', $channel)
            ->forUnifiedInbox()
            ->where('meta->outreach_lead_id', $lead->id)
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            if ($chatId !== '') {
                $this->repairConversationChatId($existing, $chatId);
            }
            $conversation = $existing->fresh() ?? $existing;
        } elseif ($chatId !== '' && $this->shouldDeferProviderChatId($response, $chatId, $providerMessageId)) {
            $conversation = V2Conversation::query()->create([
                'user_id' => $userId,
                'provider' => $channel,
                'provider_chat_id' => null,
                'status' => 'active',
                'last_message_at' => now(),
                'meta' => [
                    'source' => 'unified_inbox',
                    'organization_id' => $organizationId,
                    'prospect_name' => trim((string) ($lead->full_name ?? '')) ?: null,
                    'prospect_headline' => $lead->headline,
                    'outreach_lead_id' => $lead->id,
                    'outreach_campaign_id' => $lead->outreach_campaign_id,
                    'attendee_ids' => [$recipientId],
                    'channel_label' => OutreachChannelRegistry::channelLabel($channel),
                    'awaiting_chat_id' => true,
                    'pending_provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                ],
            ]);
        } elseif ($chatId !== '') {
            $conversation = V2Conversation::query()->firstOrCreate(
                [
                    'user_id' => $userId,
                    'provider' => $channel,
                    'provider_chat_id' => $chatId,
                ],
                [
                    'status' => 'active',
                    'last_message_at' => now(),
                    'meta' => [
                        'source' => 'unified_inbox',
                        'organization_id' => $organizationId,
                        'prospect_name' => trim((string) ($lead->full_name ?? '')) ?: null,
                        'prospect_headline' => $lead->headline,
                        'outreach_lead_id' => $lead->id,
                        'outreach_campaign_id' => $lead->outreach_campaign_id,
                        'attendee_ids' => [$recipientId],
                        'channel_label' => OutreachChannelRegistry::channelLabel($channel),
                    ],
                ],
            );
        } else {
            $conversation = V2Conversation::query()->create([
                'user_id' => $userId,
                'provider' => $channel,
                'provider_chat_id' => null,
                'status' => 'active',
                'last_message_at' => now(),
                'meta' => [
                    'source' => 'unified_inbox',
                    'organization_id' => $organizationId,
                    'prospect_name' => trim((string) ($lead->full_name ?? '')) ?: null,
                    'prospect_headline' => $lead->headline,
                    'outreach_lead_id' => $lead->id,
                    'outreach_campaign_id' => $lead->outreach_campaign_id,
                    'attendee_ids' => [$recipientId],
                    'channel_label' => OutreachChannelRegistry::channelLabel($channel),
                    'awaiting_chat_id' => true,
                ],
            ]);
        }

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $conversation->forceFill([
            'last_message_at' => now(),
            'status' => 'active',
            'meta' => array_merge($meta, array_filter([
                'source' => 'unified_inbox',
                'organization_id' => $organizationId,
                'outreach_lead_id' => $lead->id,
                'outreach_campaign_id' => $lead->outreach_campaign_id,
                'prospect_name' => trim((string) ($lead->full_name ?? '')) ?: ($meta['prospect_name'] ?? null),
                'attendee_ids' => [$recipientId],
                'awaiting_chat_id' => $chatId === '' ? true : null,
            ], fn ($value) => $value !== null && $value !== '')),
        ])->save();

        $body = trim($messageText);
        if ($body !== '') {
            V2Message::query()->firstOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'direction' => 'outbound',
                    'body' => $body,
                ],
                [
                    'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                    'sent_at' => $providerMessageId !== '' ? now() : null,
                    'meta' => [
                        'source' => 'outreach_campaign',
                        'outreach_lead_id' => $lead->id,
                        'status' => $providerMessageId !== '' ? 'sent' : 'pending_confirmation',
                    ],
                ],
            );
        }

        return $conversation;
    }

    /**
     * Link an outbound outreach email to the unified inbox thread for this lead.
     *
     * @param  array<string, mixed>  $response
     */
    public function recordOutboundEmail(
        int $userId,
        int $organizationId,
        V2OutreachLead $lead,
        string $leadEmail,
        array $response,
        string $subject,
        string $body,
    ): ?V2Conversation {
        $threadKey = $this->emailThreadKey($leadEmail);
        if ($threadKey === '') {
            return null;
        }

        $providerMessageId = $this->emailProviderMessageId($response);

        $conversation = V2Conversation::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'provider' => 'email',
                'provider_chat_id' => $threadKey,
            ],
            [
                'status' => 'active',
                'last_message_at' => now(),
                'meta' => $this->emailConversationMeta($lead, $organizationId, $threadKey, $subject, $providerMessageId),
            ],
        );

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $conversation->forceFill([
            'last_message_at' => now(),
            'status' => 'active',
            'meta' => array_merge($meta, array_filter([
                'source' => 'unified_inbox',
                'organization_id' => $organizationId,
                'outreach_lead_id' => $lead->id,
                'outreach_campaign_id' => $lead->outreach_campaign_id,
                'prospect_name' => trim((string) ($lead->full_name ?? '')) ?: ($meta['prospect_name'] ?? null),
                'prospect_email' => $threadKey,
                'email_subject' => $subject !== '' ? $subject : ($meta['email_subject'] ?? null),
                'last_email_provider_id' => $providerMessageId !== '' ? $providerMessageId : ($meta['last_email_provider_id'] ?? null),
                'channel_label' => OutreachChannelRegistry::channelLabel('email'),
            ], fn ($value) => $value !== null && $value !== '')),
        ])->save();

        $trimmedBody = trim($body);
        if ($trimmedBody !== '') {
            V2Message::query()->firstOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'direction' => 'outbound',
                    'body' => $trimmedBody,
                ],
                [
                    'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                    'sent_at' => now(),
                    'meta' => [
                        'source' => 'outreach_campaign',
                        'outreach_lead_id' => $lead->id,
                        'email_subject' => $subject,
                    ],
                ],
            );
        }

        return $conversation;
    }

    /**
     * Create or update an inbox thread when Unipile reports a new email.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleInboundEmailWebhook(int $userId, array $payload): void
    {
        $emailItem = $this->normalizeEmailWebhookItem($payload);
        if ($emailItem === null) {
            return;
        }

        $this->importEmailForLead($userId, $emailItem, triggerReplyHandlers: true);
    }

    /**
     * Pull recent mailbox messages and attach outreach replies to the inbox.
     */
    public function syncEmailInboxForUser(int $userId): void
    {
        $accountId = V2IntegrationAccount::activeUnipileAccountIdForProvider($userId, 'email');
        if (! $accountId) {
            return;
        }

        try {
            $response = app(UnipileProvider::class)->listEmails(['limit' => 50], ['account_id' => $accountId]);
        } catch (\Throwable) {
            return;
        }

        $items = Arr::get($response, 'items');
        if (! is_array($items) || $items === []) {
            $items = Arr::get($response, 'data.items', []);
        }

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized = $this->normalizeEmailWebhookItem($item);
            if ($normalized === null) {
                continue;
            }

            $this->importEmailForLead($userId, $normalized, triggerReplyHandlers: false);
        }

        $this->backfillEmailThreadsForUser($userId);
    }

    /**
     * Ensure outreach leads with email addresses have an inbox thread shell.
     */
    public function backfillEmailThreadsForUser(int $userId): void
    {
        $leads = V2OutreachLead::query()
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $userId))
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->latest('id')
            ->get();

        foreach ($leads as $lead) {
            $threadKey = $this->emailThreadKey((string) $lead->email);
            if ($threadKey === '' || ! $lead->campaign) {
                continue;
            }

            $exists = V2Conversation::query()
                ->where('user_id', $userId)
                ->where('provider', 'email')
                ->where('provider_chat_id', $threadKey)
                ->exists();

            if ($exists) {
                continue;
            }

            V2Conversation::query()->create([
                'user_id' => $userId,
                'provider' => 'email',
                'provider_chat_id' => $threadKey,
                'status' => 'active',
                'last_message_at' => now(),
                'meta' => $this->emailConversationMeta(
                    $lead,
                    (int) ($lead->campaign->organization_id ?? 0),
                    $threadKey,
                    '',
                    '',
                ),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function normalizeEmailWebhookItem(array $payload): ?array
    {
        $inner = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $role = strtolower((string) (Arr::get($inner, 'role') ?? Arr::get($payload, 'role') ?? ''));
        $event = strtolower(str_replace('.', '_', (string) (Arr::get($payload, 'event') ?? '')));

        if (in_array($role, ['outbox', 'sent'], true) || $event === 'mail_sent') {
            return null;
        }

        $fromEmail = $this->extractEmailAddress(
            Arr::get($inner, 'from_attendee')
            ?? Arr::get($payload, 'from_attendee')
            ?? Arr::get($inner, 'from')
            ?? Arr::get($payload, 'from')
        );
        $toEmails = $this->extractEmailAddresses(
            Arr::get($inner, 'to_attendees')
            ?? Arr::get($payload, 'to_attendees')
            ?? Arr::get($inner, 'to')
            ?? Arr::get($payload, 'to')
        );

        $body = trim(strip_tags((string) (
            Arr::get($inner, 'body_plain')
            ?? Arr::get($payload, 'body_plain')
            ?? Arr::get($inner, 'body')
            ?? Arr::get($payload, 'body')
            ?? ''
        )));

        if ($body === '') {
            return null;
        }

        $providerMessageId = trim((string) (
            Arr::get($inner, 'email_id')
            ?? Arr::get($payload, 'email_id')
            ?? Arr::get($inner, 'provider_id')
            ?? Arr::get($payload, 'provider_id')
            ?? Arr::get($inner, 'id')
            ?? Arr::get($payload, 'id')
            ?? ''
        ));

        $subject = trim((string) (Arr::get($inner, 'subject') ?? Arr::get($payload, 'subject') ?? ''));
        $receivedAt = Arr::get($inner, 'date') ?? Arr::get($payload, 'date');

        return [
            'from_email' => $fromEmail,
            'to_emails' => $toEmails,
            'body' => $body,
            'subject' => $subject,
            'provider_message_id' => $providerMessageId,
            'received_at' => is_string($receivedAt) ? $receivedAt : null,
        ];
    }

    /**
     * @param  array{from_email: string, to_emails: array<int, string>, body: string, subject: string, provider_message_id: string, received_at: string|null}  $emailItem
     */
    private function importEmailForLead(int $userId, array $emailItem, bool $triggerReplyHandlers): void
    {
        $fromEmail = $emailItem['from_email'];
        if ($fromEmail === '') {
            return;
        }

        $lead = $this->findOutreachLeadByEmail($userId, $fromEmail);
        if (! $lead) {
            foreach ($emailItem['to_emails'] as $toEmail) {
                $lead = $this->findOutreachLeadByEmail($userId, $toEmail);
                if ($lead) {
                    break;
                }
            }
        }

        if (! $lead) {
            return;
        }

        $prospectEmail = strtolower(trim((string) ($lead->email ?? '')));
        if ($prospectEmail === '') {
            $prospectEmail = $fromEmail !== '' && $fromEmail !== $this->connectedMailboxEmail($userId)
                ? $fromEmail
                : ($emailItem['to_emails'][0] ?? '');
        }

        if ($prospectEmail === '') {
            return;
        }

        $isInboundFromLead = $fromEmail === $prospectEmail;
        if (! $isInboundFromLead) {
            return;
        }

        $threadKey = $this->emailThreadKey($prospectEmail);
        $campaign = $lead->campaign;
        if (! $campaign) {
            return;
        }

        $conversation = V2Conversation::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'provider' => 'email',
                'provider_chat_id' => $threadKey,
            ],
            [
                'status' => 'active',
                'last_message_at' => now(),
                'meta' => $this->emailConversationMeta(
                    $lead,
                    (int) ($campaign->organization_id ?? 0),
                    $threadKey,
                    $emailItem['subject'],
                    $emailItem['provider_message_id'],
                ),
            ],
        );

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $conversation->forceFill([
            'last_message_at' => $emailItem['received_at'] ? Carbon::parse($emailItem['received_at']) : now(),
            'status' => 'active',
            'meta' => array_merge($meta, array_filter([
                'source' => 'unified_inbox',
                'outreach_lead_id' => $lead->id,
                'outreach_campaign_id' => $lead->outreach_campaign_id,
                'prospect_name' => trim((string) ($lead->full_name ?? '')) ?: ($meta['prospect_name'] ?? null),
                'prospect_email' => $threadKey,
                'email_subject' => $emailItem['subject'] !== '' ? $emailItem['subject'] : ($meta['email_subject'] ?? null),
                'last_email_provider_id' => $emailItem['provider_message_id'] !== ''
                    ? $emailItem['provider_message_id']
                    : ($meta['last_email_provider_id'] ?? null),
                'channel_label' => OutreachChannelRegistry::channelLabel('email'),
            ], fn ($value) => $value !== null && $value !== '')),
        ])->save();

        $existing = $emailItem['provider_message_id'] !== ''
            ? V2Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('provider_message_id', $emailItem['provider_message_id'])
                ->exists()
            : V2Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->where('body', $emailItem['body'])
                ->exists();

        if ($existing) {
            return;
        }

        V2Message::query()->create([
            'conversation_id' => $conversation->id,
            'provider_message_id' => $emailItem['provider_message_id'] !== '' ? $emailItem['provider_message_id'] : null,
            'direction' => 'inbound',
            'body' => $emailItem['body'],
            'received_at' => $emailItem['received_at'] ? Carbon::parse($emailItem['received_at']) : now(),
            'meta' => array_filter([
                'source' => 'unipile_email',
                'email_subject' => $emailItem['subject'] !== '' ? $emailItem['subject'] : null,
            ], fn ($value) => $value !== null && $value !== ''),
        ]);

        if ($triggerReplyHandlers && $conversation->isInboxThread()) {
            app(UnifiedInboxReplyService::class)->handleInbound(
                $conversation->fresh(),
                $emailItem['body'],
                $userId,
            );
        }
    }

    private function emailThreadKey(string $email): string
    {
        $normalized = strtolower(trim($email));

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) ? $normalized : '';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function emailProviderMessageId(array $response): string
    {
        return trim((string) (
            Arr::get($response, 'provider_id')
            ?? Arr::get($response, 'id')
            ?? Arr::get($response, 'email_id')
            ?? ''
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function emailConversationMeta(
        V2OutreachLead $lead,
        int $organizationId,
        string $prospectEmail,
        string $subject,
        string $providerMessageId,
    ): array {
        return array_filter([
            'source' => 'unified_inbox',
            'organization_id' => $organizationId > 0 ? $organizationId : null,
            'prospect_name' => trim((string) ($lead->full_name ?? '')) ?: null,
            'prospect_headline' => $lead->headline,
            'prospect_email' => $prospectEmail,
            'outreach_lead_id' => $lead->id,
            'outreach_campaign_id' => $lead->outreach_campaign_id,
            'email_subject' => $subject !== '' ? $subject : null,
            'last_email_provider_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'channel_label' => OutreachChannelRegistry::channelLabel('email'),
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function findOutreachLeadByEmail(int $userId, string $email): ?V2OutreachLead
    {
        $normalized = $this->emailThreadKey($email);
        if ($normalized === '') {
            return null;
        }

        return V2OutreachLead::query()
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $userId))
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->latest('id')
            ->first();
    }

    private function connectedMailboxEmail(int $userId): string
    {
        $account = V2IntegrationAccount::query()
            ->where('user_id', $userId)
            ->where('provider', 'email')
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $account) {
            return '';
        }

        $meta = is_array($account->meta) ? $account->meta : [];

        return $this->emailThreadKey((string) (
            Arr::get($meta, 'email')
            ?? Arr::get($meta, 'identifier')
            ?? Arr::get($meta, 'username')
            ?? ''
        ));
    }

    private function extractEmailAddress(mixed $value): string
    {
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return strtolower(trim($value));
        }

        if (is_array($value)) {
            $email = trim((string) (
                Arr::get($value, 'identifier')
                ?? Arr::get($value, 'email')
                ?? Arr::get($value, 'address')
                ?? ''
            ));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return strtolower($email);
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function extractEmailAddresses(mixed $value): array
    {
        if (! is_array($value)) {
            $single = $this->extractEmailAddress($value);

            return $single !== '' ? [$single] : [];
        }

        $emails = [];
        foreach ($value as $item) {
            $email = $this->extractEmailAddress($item);
            if ($email !== '') {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Pull messages from Unipile when webhooks did not arrive (common on local dev).
     */
    public function syncMessagesFromProvider(V2Conversation $conversation): void
    {
        $provider = (string) $conversation->provider;
        if ($provider === 'email') {
            $this->syncEmailInboxForUser((int) $conversation->user_id);

            return;
        }

        $chatId = trim((string) ($conversation->provider_chat_id ?? ''));
        if ($chatId === '') {
            return;
        }

        if ($this->isGroupChatId($chatId, $provider) && ! $this->isTruthy(Arr::get($conversation->meta, 'is_group'))) {
            $this->invalidateProviderChatId($conversation, $chatId, 'group_chat_linked_to_direct_thread');

            return;
        }

        $this->cleanupReactionGhostMessages($conversation);

        $provider = (string) $conversation->provider;
        if ($provider === '') {
            return;
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountIdForProvider((int) $conversation->user_id, $provider);
        if (! $accountId) {
            return;
        }

        try {
            $response = app(UnipileProvider::class)->listMessages(
                $chatId,
                ['limit' => 50],
                ['account_id' => $accountId]
            );
        } catch (UnipileException $e) {
            if ($e->statusCode === 404) {
                $this->handleMissingProviderChat($conversation, $accountId);
            }

            return;
        } catch (\Throwable) {
            return;
        }

        $items = Arr::get($response, 'items');
        if (! is_array($items) || $items === []) {
            $items = Arr::get($response, 'data.items', []);
        }

        if (! is_array($items) || $items === []) {
            return;
        }

        $latestAt = $conversation->last_message_at;
        $newInboundBodies = [];
        $since = ($conversation->created_at ?? now())->copy()->subMinute();
        $normalizer = app(UnipileMessageNormalizer::class);

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $reactionEvent = $normalizer->resolveReactionEvent($item);
            if ($reactionEvent !== null) {
                continue;
            }

            if (! $normalizer->hasDisplayableContent($item)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));
            $attachments = $normalizer->extractAttachments($item);
            $reactions = $normalizer->extractReactions($item);

            $at = ! empty($item['timestamp'])
                ? Carbon::parse((string) $item['timestamp'])
                : now();

            if ($at->lt($since)) {
                continue;
            }

            $providerMessageId = trim((string) ($item['id'] ?? $item['message_id'] ?? ''));
            $isOutbound = $this->isTruthy($item['is_sender'] ?? false)
                || $this->isTruthy($item['from_me'] ?? false);
            $direction = $isOutbound ? 'outbound' : 'inbound';

            if ($providerMessageId !== '') {
                $existing = V2Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('provider_message_id', $providerMessageId)
                    ->first();

                if ($existing) {
                    $this->applyProviderMessageTimestamp($existing, $at, $direction);
                    $this->updateMessageMedia($existing, $attachments, $reactions, $text);

                    if (! $latestAt || $at->gt($latestAt)) {
                        $latestAt = $at;
                    }

                    continue;
                }
            }

            if ($direction === 'outbound' && $providerMessageId !== '') {
                $orphan = V2Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('direction', 'outbound')
                    ->whereNull('provider_message_id')
                    ->where('body', $text)
                    ->first();

                if ($orphan) {
                    $orphan->forceFill([
                        'provider_message_id' => $providerMessageId,
                        'sent_at' => $orphan->sent_at ?? $at,
                        'attachments' => $attachments !== [] ? $attachments : $orphan->attachments,
                    ])->save();
                    $this->updateMessageMedia($orphan, $attachments, $reactions, $text);

                    if (! $latestAt || $at->gt($latestAt)) {
                        $latestAt = $at;
                    }

                    continue;
                }
            }

            V2Message::query()->create([
                'conversation_id' => $conversation->id,
                'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
                'direction' => $direction,
                'body' => $text !== '' ? $text : ($attachments !== [] ? '[Attachment]' : ''),
                'attachments' => $attachments !== [] ? $attachments : null,
                'sent_at' => $direction === 'outbound' ? $at : null,
                'received_at' => $direction === 'inbound' ? $at : null,
                'meta' => array_filter([
                    'source' => 'unipile_sync',
                    'reactions' => $reactions !== [] ? $reactions : null,
                ], fn ($v) => $v !== null),
            ]);

            if ($direction === 'inbound' && $text !== '') {
                $newInboundBodies[] = $text;
            }

            if (! $latestAt || $at->gt($latestAt)) {
                $latestAt = $at;
            }
        }

        $this->reconcileReactionsFromProviderItems($conversation, $items, $normalizer);

        if ($latestAt) {
            $conversation->forceFill([
                'last_message_at' => $latestAt,
                'status' => 'active',
            ])->save();
        }

        if ($newInboundBodies !== [] && $conversation->isInboxThread()) {
            $replyService = app(UnifiedInboxReplyService::class);
            $fresh = $conversation->fresh();

            foreach ($newInboundBodies as $body) {
                if ($fresh) {
                    $replyService->handleInbound($fresh, $body, (int) $conversation->user_id);
                }
            }
        }

        $this->dedupeConversationMessages($conversation);
    }

    /**
     * Remove duplicate rows created by outreach + webhook/sync overlap.
     */
    public function dedupeConversationMessages(V2Conversation $conversation): void
    {
        $messages = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get();

        if ($messages->count() < 2) {
            return;
        }

        $keepers = [];

        foreach ($messages as $message) {
            $providerMessageId = trim((string) ($message->provider_message_id ?? ''));
            $bodyKey = mb_strtolower(trim((string) ($message->body ?? '')));
            $direction = (string) ($message->direction ?? '');

            if ($providerMessageId !== '') {
                $key = 'pid:'.$providerMessageId;
                if (isset($keepers[$key])) {
                    $message->delete();

                    continue;
                }
                $keepers[$key] = $message->id;

                continue;
            }

            if ($bodyKey === '') {
                continue;
            }

            $bodyKey = 'body:'.$direction.':'.$bodyKey;
            if (! isset($keepers[$bodyKey])) {
                $keepers[$bodyKey] = $message->id;

                continue;
            }

            $keeper = V2Message::query()->find($keepers[$bodyKey]);
            if (! $keeper) {
                $keepers[$bodyKey] = $message->id;

                continue;
            }

            $candidateHasProviderId = $providerMessageId !== '';
            $keeperHasProviderId = trim((string) ($keeper->provider_message_id ?? '')) !== '';

            if ($candidateHasProviderId && ! $keeperHasProviderId) {
                $keeper->delete();
                $keepers[$bodyKey] = $message->id;

                continue;
            }

            if ($keeperHasProviderId) {
                $message->delete();

                continue;
            }

            $keeperTime = $keeper->received_at ?? $keeper->sent_at ?? $keeper->created_at;
            $messageTime = $message->received_at ?? $message->sent_at ?? $message->created_at;
            if ($keeperTime && $messageTime && $messageTime->lt($keeperTime)) {
                $keeper->delete();
                $keepers[$bodyKey] = $message->id;

                continue;
            }

            $message->delete();
        }

        $latest = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('received_at')
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->first();

        if ($latest) {
            $conversation->forceFill([
                'last_message_at' => $latest->received_at ?? $latest->sent_at ?? $latest->created_at,
            ])->save();
        }
    }

    private function applyProviderMessageTimestamp(V2Message $message, Carbon $at, string $direction): void
    {
        $message->forceFill(
            $direction === 'outbound'
                ? ['sent_at' => $at]
                : ['received_at' => $at]
        )->save();
    }

    public function sendMessage(User $user, V2Conversation $conversation, string $text, ?UploadedFile $attachment = null): V2Message
    {
        $text = trim($text);
        if ($text === '' && ! $attachment) {
            throw new \InvalidArgumentException('Message cannot be empty.');
        }

        if (! $conversation->provider_chat_id) {
            throw new \RuntimeException('Conversation is not linked to a Unipile chat.');
        }

        $provider = (string) $conversation->provider;
        if ($attachment && ! InboxAttachmentSupport::supportsAttachments($provider)) {
            throw new \RuntimeException('File attachments are not supported on '.OutreachChannelRegistry::channelLabel($provider).'.');
        }

        $accountId = V2IntegrationAccount::activeUnipileAccountIdForProvider($user->id, $provider);
        if (! $accountId) {
            throw new \RuntimeException(
                'Connect '.OutreachChannelRegistry::channelLabel($provider).' via Integrations first.'
            );
        }

        $organizationId = (int) ($user->current_organization_id ?? 0);
        if ($organizationId <= 0) {
            throw new \RuntimeException('No workspace selected.');
        }

        $attachmentMeta = null;
        if ($attachment) {
            $attachmentMeta = [[
                'id' => null,
                'type' => str_starts_with((string) $attachment->getMimeType(), 'video/') ? 'video' : (str_starts_with((string) $attachment->getMimeType(), 'image/') ? 'img' : 'file'),
                'mimetype' => $attachment->getMimeType(),
                'filename' => $attachment->getClientOriginalName(),
                'unavailable' => false,
                'outbound' => true,
            ]];
        }

        $message = app(OutreachPersistenceService::class)->createOutboundMessage(
            $conversation->id,
            $text !== '' ? $text : '[Attachment]',
            'message',
            array_filter([
                'chat_id' => $conversation->provider_chat_id,
                'source' => 'unified_inbox',
                '_unipile_account_id' => $accountId,
                'channel' => $provider,
            ])
        );

        if ($attachmentMeta) {
            $message->forceFill(['attachments' => $attachmentMeta])->save();
        }

        if ($attachment) {
            $this->sendAttachmentNow(
                $conversation->provider_chat_id,
                $accountId,
                $text,
                $attachment,
                $message
            );
        } elseif ($provider === 'email') {
            $this->sendEmailNow($conversation, $accountId, $text, $message);
        } else {
            $this->sendTextNow(
                $conversation->provider_chat_id,
                $accountId,
                $text,
                $message
            );
        }

        $conversation->forceFill(['last_message_at' => now(), 'status' => 'active'])->save();

        return $message;
    }

    public function applyReactionToMessage(V2Conversation $conversation, string $providerMessageId, array $reaction): void
    {
        $message = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if ($message) {
            $this->applyReactionToMessageRecord($message, $reaction);
        }
    }

    /**
     * @param  array{value: string, sender_id?: string|null, target_provider_message_id?: string|null, is_sender?: bool}  $reaction
     */
    public function applyParsedReaction(V2Conversation $conversation, array $reaction): void
    {
        if (trim((string) ($reaction['value'] ?? '')) === '') {
            return;
        }

        $targetId = trim((string) ($reaction['target_provider_message_id'] ?? ''));
        if ($targetId === '') {
            return;
        }

        $target = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('provider_message_id', $targetId)
            ->first();

        if ($target) {
            $this->applyReactionToMessageRecord($target, [
                'value' => (string) $reaction['value'],
                'sender_id' => $reaction['sender_id'] ?? null,
                'is_sender' => (bool) ($reaction['is_sender'] ?? false),
            ]);
        }
    }

    /**
     * @param  array{value: string, sender_id?: string|null, is_sender?: bool}  $reaction
     */
    private function applyReactionToMessageRecord(V2Message $message, array $reaction): void
    {
        $meta = is_array($message->meta) ? $message->meta : [];
        $existing = is_array($meta['reactions'] ?? null) ? $meta['reactions'] : [];
        $meta['reactions'] = app(UnipileMessageNormalizer::class)->mergeReactions($existing, [[
            'value' => (string) ($reaction['value'] ?? ''),
            'sender_id' => isset($reaction['sender_id']) ? (string) $reaction['sender_id'] : null,
            'is_sender' => (bool) ($reaction['is_sender'] ?? false),
        ]]);
        $message->forceFill(['meta' => $meta])->save();
    }

    public function cleanupReactionGhostMessages(V2Conversation $conversation, ?UnipileMessageNormalizer $normalizer = null): void
    {
        $normalizer ??= app(UnipileMessageNormalizer::class);

        $messages = V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $previous = null;
        foreach ($messages as $message) {
            $parsed = $normalizer->parseReactionAnnouncementText(trim((string) ($message->body ?? '')));
            if ($parsed === null) {
                continue;
            }

            $message->delete();
        }
    }

    /**
     * @param  list<mixed>  $items
     */
    private function reconcileReactionsFromProviderItems(
        V2Conversation $conversation,
        array $items,
        UnipileMessageNormalizer $normalizer,
    ): void {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ($normalizer->resolveReactionEvent($item) !== null) {
                continue;
            }

            if (! array_key_exists('reactions', $item)) {
                continue;
            }

            $providerMessageId = trim((string) ($item['id'] ?? $item['message_id'] ?? ''));
            if ($providerMessageId === '') {
                continue;
            }

            $message = V2Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('provider_message_id', $providerMessageId)
                ->first();

            if (! $message) {
                continue;
            }

            $reactions = $normalizer->extractReactions($item);
            $meta = is_array($message->meta) ? $message->meta : [];

            if ($reactions === []) {
                unset($meta['reactions']);
            } else {
                $meta['reactions'] = $reactions;
            }

            $message->forceFill(['meta' => $meta])->save();
        }
    }

    private function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function sendTextNow(
        string $chatId,
        string $accountId,
        string $text,
        V2Message $message,
    ): void {
        $result = app(UnipileProvider::class)->sendMessage($chatId, [
            'text' => $text,
            'account_id' => $accountId,
        ], ['account_id' => $accountId]);

        app(OutreachPersistenceService::class)->markMessageResult($message, $result, 'sent');

        $providerMessageId = trim((string) ($result['id'] ?? $result['message_id'] ?? ''));
        if ($providerMessageId !== '') {
            $message->forceFill(['provider_message_id' => $providerMessageId, 'sent_at' => now()])->save();
        }
    }

    private function sendEmailNow(
        V2Conversation $conversation,
        string $accountId,
        string $text,
        V2Message $message,
    ): void {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $toEmail = trim((string) ($meta['prospect_email'] ?? $conversation->provider_chat_id ?? ''));
        if ($toEmail === '') {
            throw new \RuntimeException('This email thread has no recipient address.');
        }

        $subject = trim((string) ($meta['email_subject'] ?? ''));
        if ($subject === '') {
            $subject = 'Re: Your message';
        } elseif (! str_starts_with(strtolower($subject), 're:')) {
            $subject = 'Re: '.$subject;
        }

        $payload = [
            'to' => [['identifier' => $toEmail]],
            'subject' => $subject,
            'body' => $text,
        ];

        $replyTo = trim((string) ($meta['last_email_provider_id'] ?? ''));
        if ($replyTo !== '') {
            $payload['reply_to'] = $replyTo;
        }

        $result = app(UnipileProvider::class)->sendEmail($payload, ['account_id' => $accountId]);

        app(OutreachPersistenceService::class)->markMessageResult($message, $result, 'sent');

        $providerMessageId = $this->emailProviderMessageId(is_array($result) ? $result : []);
        if ($providerMessageId !== '') {
            $message->forceFill(['provider_message_id' => $providerMessageId, 'sent_at' => now()])->save();
            $conversation->forceFill([
                'meta' => array_merge($meta, ['last_email_provider_id' => $providerMessageId]),
            ])->save();
        }
    }

    private function sendAttachmentNow(
        string $chatId,
        string $accountId,
        string $text,
        UploadedFile $attachment,
        V2Message $message,
    ): void {
        $result = app(UnipileProvider::class)->sendMessage($chatId, [
            'text' => $text,
            'account_id' => $accountId,
            '_files' => [[
                'path' => $attachment->getRealPath(),
                'filename' => $attachment->getClientOriginalName(),
                'mime' => $attachment->getMimeType() ?: 'application/octet-stream',
            ]],
        ], ['account_id' => $accountId]);

        app(OutreachPersistenceService::class)->markMessageResult($message, $result, 'sent');

        $providerMessageId = trim((string) ($result['id'] ?? $result['message_id'] ?? ''));
        if ($providerMessageId !== '') {
            $message->forceFill(['provider_message_id' => $providerMessageId, 'sent_at' => now()])->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $attachments
     * @param  list<array<string, mixed>>  $reactions
     */
    private function updateMessageMedia(V2Message $message, array $attachments, array $reactions, string $text): void
    {
        $updates = [];
        if ($attachments !== []) {
            $updates['attachments'] = $attachments;
        }
        if ($text !== '' && trim((string) $message->body) === '') {
            $updates['body'] = $text;
        }

        $meta = is_array($message->meta) ? $message->meta : [];
        if ($reactions !== []) {
            $existing = is_array($meta['reactions'] ?? null) ? $meta['reactions'] : [];
            $meta['reactions'] = app(UnipileMessageNormalizer::class)->mergeReactions($existing, $reactions);
            $updates['meta'] = $meta;
        }

        if ($updates !== []) {
            $message->forceFill($updates)->save();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveProviderFromPayload(array $payload): ?string
    {
        $providerRaw = (string) (
            Arr::get($payload, 'data.account_type')
            ?? Arr::get($payload, 'account_type')
            ?? Arr::get($payload, 'data.provider')
            ?? Arr::get($payload, 'provider')
            ?? Arr::get($payload, 'data.account.provider')
            ?? Arr::get($payload, 'account.provider')
            ?? ''
        );

        if ($providerRaw !== '') {
            $mapped = OutreachChannelRegistry::integrationProviderForUnipileType($providerRaw)
                ?? strtolower($providerRaw);

            if ($mapped !== '' && $mapped !== 'linkedin') {
                return $mapped;
            }
        }

        $accountId = (string) (
            Arr::get($payload, 'data.account_id')
            ?? Arr::get($payload, 'account_id')
            ?? ''
        );

        if ($accountId !== '') {
            $account = V2IntegrationAccount::query()
                ->where('provider_account_id', $accountId)
                ->orWhere('meta->unipile_account_id', $accountId)
                ->orderByDesc('id')
                ->first();

            $provider = (string) ($account?->provider ?? '');
            if ($provider !== '' && $provider !== 'linkedin') {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $attendeeIds
     */
    private function matchOutreachLead(int $userId, string $provider, array $attendeeIds, array $payload): ?V2OutreachLead
    {
        $query = V2OutreachLead::query()
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $userId));

        $senderId = $this->extractSenderId($payload);
        if ($senderId !== '') {
            $lead = $this->matchLeadByIdentifier($query->clone(), $provider, $senderId);
            if ($lead) {
                return $lead;
            }
        }

        if ($this->isGroupChatPayload($payload, '', $provider)) {
            return null;
        }

        foreach ($attendeeIds as $attendeeId) {
            if ($attendeeId === $senderId) {
                continue;
            }

            if ($this->requiresStrictSenderMatch($provider)) {
                continue;
            }

            $lead = $this->matchLeadByIdentifier($query->clone(), $provider, $attendeeId);
            if ($lead) {
                return $lead;
            }
        }

        if ($this->requiresStrictSenderMatch($provider)) {
            return null;
        }

        if ($provider === 'whatsapp') {
            return null;
        }

        $senderName = trim((string) (
            Arr::get($payload, 'data.sender.display_name')
            ?? Arr::get($payload, 'sender.display_name')
            ?? Arr::get($payload, 'data.sender.name')
            ?? Arr::get($payload, 'sender.name')
            ?? ''
        ));

        if ($senderName !== '') {
            return $query->clone()
                ->where('full_name', $senderName)
                ->latest('id')
                ->first();
        }

        return null;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<V2OutreachLead>  $query
     */
    private function matchLeadByIdentifier($query, string $provider, string $identifier): ?V2OutreachLead
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        return match ($provider) {
            'whatsapp' => $query->clone()
                ->where(function ($q) use ($identifier) {
                    $q->where('phone', $identifier)
                        ->orWhere('meta->whatsapp_provider_id', $identifier);
                })
                ->latest('id')
                ->first(),
            'instagram' => $query->clone()
                ->where(function ($q) use ($identifier) {
                    $q->where('meta->instagram_provider_id', $identifier)
                        ->orWhere('meta->instagram_scoped_id', $identifier)
                        ->orWhereJsonContains('meta->instagram_attendee_ids', $identifier);
                })
                ->latest('id')
                ->first(),
            'telegram' => $query->clone()
                ->where('meta->telegram_provider_id', $identifier)
                ->latest('id')
                ->first(),
            'twitter' => $query->clone()
                ->where('meta->twitter_provider_id', $identifier)
                ->latest('id')
                ->first(),
            'email' => $query->clone()
                ->where('email', $identifier)
                ->latest('id')
                ->first(),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractContactName(array $payload, ?V2OutreachLead $lead): ?string
    {
        $fromLead = trim((string) ($lead?->full_name ?? ''));
        if ($fromLead !== '') {
            return $fromLead;
        }

        foreach ([
            Arr::get($payload, 'data.sender.display_name'),
            Arr::get($payload, 'sender.display_name'),
            Arr::get($payload, 'data.sender.name'),
            Arr::get($payload, 'sender.name'),
            Arr::get($payload, 'data.attendees.0.display_name'),
            Arr::get($payload, 'attendees.0.display_name'),
        ] as $name) {
            $value = trim((string) ($name ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function extractAttendeeIds(array $payload): array
    {
        $ids = [];

        foreach ([
            Arr::get($payload, 'data.attendees'),
            Arr::get($payload, 'attendees'),
        ] as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $attendee) {
                if (is_string($attendee) && $attendee !== '') {
                    $ids[] = $attendee;
                    continue;
                }

                if (is_array($attendee)) {
                    $id = (string) (
                        Arr::get($attendee, 'provider_id')
                        ?? Arr::get($attendee, 'id')
                        ?? Arr::get($attendee, 'attendee_provider_id')
                        ?? ''
                    );
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            }
        }

        foreach ([
            Arr::get($payload, 'data.sender_id'),
            Arr::get($payload, 'sender_id'),
            Arr::get($payload, 'data.sender.provider_id'),
            Arr::get($payload, 'sender.provider_id'),
            Arr::get($payload, 'data.sender.attendee_provider_id'),
            Arr::get($payload, 'sender.attendee_provider_id'),
        ] as $single) {
            if (is_string($single) && $single !== '') {
                $ids[] = $single;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function enrichConversationMeta(V2Conversation $conversation, array $payload): V2Conversation
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $attendeeIds = $this->extractAttendeeIds($payload);
        $outreachLead = $this->matchOutreachLead(
            (int) $conversation->user_id,
            (string) $conversation->provider,
            $attendeeIds,
            $payload
        );

        $updates = array_filter([
            'source' => 'unified_inbox',
            'prospect_name' => $meta['prospect_name'] ?? $this->extractContactName($payload, $outreachLead),
            'outreach_lead_id' => $meta['outreach_lead_id'] ?? $outreachLead?->id,
            'outreach_campaign_id' => $meta['outreach_campaign_id'] ?? $outreachLead?->outreach_campaign_id,
            'attendee_ids' => $attendeeIds !== [] ? $attendeeIds : ($meta['attendee_ids'] ?? null),
        ], fn ($value) => $value !== null && $value !== '');

        if ($updates !== []) {
            $conversation->forceFill(['meta' => array_merge($meta, $updates)])->save();
        }

        return $conversation->fresh();
    }

    public function repairConversationChatId(V2Conversation $conversation, string $chatId, array $payload = []): void
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return;
        }

        if ($this->isGroupChatId($chatId, (string) $conversation->provider) && ! $this->isTruthy(Arr::get($conversation->meta, 'is_group'))) {
            \Illuminate\Support\Facades\Log::warning('[Inbox] Refused to link group chat id to 1:1 outreach thread', [
                'conversation_id' => $conversation->id,
                'provider' => $conversation->provider,
                'chat_id' => $chatId,
            ]);

            return;
        }

        if ($payload !== [] && $this->isGroupChatPayload($payload, $chatId, (string) $conversation->provider) && ! $this->isTruthy(Arr::get($conversation->meta, 'is_group'))) {
            return;
        }

        $current = trim((string) ($conversation->provider_chat_id ?? ''));
        if ($current === $chatId) {
            return;
        }

        \Illuminate\Support\Facades\Log::info('[Inbox] Repaired provider chat id', [
            'conversation_id' => $conversation->id,
            'provider' => $conversation->provider,
            'from' => $current !== '' ? $current : null,
            'to' => $chatId,
        ]);

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        unset($meta['awaiting_chat_id'], $meta['invalid_provider_chat_id']);

        $conversation->forceFill([
            'provider_chat_id' => $chatId,
            'meta' => $meta,
        ])->save();
    }

    private function handleMissingProviderChat(V2Conversation $conversation, string $accountId): void
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $invalid = trim((string) ($conversation->provider_chat_id ?? ''));

        if ($invalid !== '') {
            $meta['invalid_provider_chat_id'] = $invalid;
            $conversation->forceFill([
                'provider_chat_id' => null,
                'meta' => $meta,
            ])->save();
        }

        $repaired = $this->discoverChatIdFromAttendees($conversation->fresh() ?? $conversation, $accountId);
        if ($repaired !== null) {
            $this->repairConversationChatId($conversation->fresh() ?? $conversation, $repaired);

            return;
        }

        $repaired = $this->discoverChatIdFromSiblingConversation($conversation);
        if ($repaired !== null) {
            $this->repairConversationChatId($conversation->fresh() ?? $conversation, $repaired);
        }
    }

    private function discoverChatIdFromAttendees(V2Conversation $conversation, string $accountId): ?string
    {
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $attendeeIds = Arr::get($meta, 'attendee_ids', []);
        if (! is_array($attendeeIds) || $attendeeIds === []) {
            return null;
        }

        try {
            $response = app(UnipileProvider::class)->listChats(
                ['limit' => 50],
                ['account_id' => $accountId],
            );
        } catch (\Throwable) {
            return null;
        }

        $items = Arr::get($response, 'items', Arr::get($response, 'data.items', []));
        if (! is_array($items)) {
            return null;
        }

        $targets = array_map('strval', $attendeeIds);
        $directMatch = null;

        foreach ($items as $chat) {
            if (! is_array($chat)) {
                continue;
            }

            if ($this->isGroupChatArray($chat, (string) $conversation->provider)) {
                continue;
            }

            $chatId = trim((string) ($chat['id'] ?? $chat['chat_id'] ?? ''));
            if ($chatId === '' || $this->isGroupChatId($chatId, (string) $conversation->provider)) {
                continue;
            }

            $chatAttendees = Arr::get($chat, 'attendees', Arr::get($chat, 'attendee_ids', []));
            if (! is_array($chatAttendees)) {
                continue;
            }

            $matchedTargets = [];
            foreach ($chatAttendees as $att) {
                $aid = is_array($att)
                    ? (string) (
                        Arr::get($att, 'provider_id')
                        ?? Arr::get($att, 'attendee_provider_id')
                        ?? Arr::get($att, 'id')
                        ?? ''
                    )
                    : (string) $att;

                if ($aid !== '' && in_array($aid, $targets, true)) {
                    $matchedTargets[] = $aid;
                }
            }

            if ($matchedTargets === []) {
                continue;
            }

            if (count(array_unique($matchedTargets)) === count($targets)) {
                return $chatId;
            }

            if ($directMatch === null) {
                $directMatch = $chatId;
            }
        }

        return $directMatch;
    }

    private function findConversationByProviderChatId(int $userId, string $provider, string $chatId): ?V2Conversation
    {
        return V2Conversation::query()
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->where('provider_chat_id', $chatId)
            ->whereDoesntHave('calls')
            ->where(function ($query) {
                $query->whereNull('meta->source')
                    ->orWhere('meta->source', '!=', 'call_manager');
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<int, string>  $attendeeIds
     */
    private function findStaleOutreachConversation(
        int $userId,
        string $provider,
        string $chatId,
        array $attendeeIds,
        array $payload = [],
    ): ?V2Conversation {
        if ($this->isGroupChatPayload($payload, $chatId, $provider)) {
            return null;
        }

        $senderId = $this->extractSenderId($payload);
        $candidates = V2Conversation::query()
            ->where('user_id', $userId)
            ->where('provider', $provider)
            ->forUnifiedInbox()
            ->whereNotNull('meta->outreach_lead_id')
            ->where(function ($query) use ($chatId) {
                $query->whereNull('provider_chat_id')
                    ->orWhere('provider_chat_id', '!=', $chatId);
            })
            ->orderByDesc('last_message_at')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        foreach ($candidates as $conversation) {
            $lead = $this->resolveOutreachLeadFromConversation($conversation);

            if ($lead && $senderId !== '') {
                if ($this->senderMatchesOutreachLead($lead, $provider, $senderId)) {
                    return $conversation;
                }

                if ($this->requiresStrictSenderMatch($provider)) {
                    continue;
                }
            }

            $stored = Arr::get($conversation->meta, 'attendee_ids', []);
            if (! is_array($stored)) {
                continue;
            }

            if ($senderId !== '' && $lead && $this->requiresStrictSenderMatch($provider)) {
                continue;
            }

            $storedIds = array_map('strval', $stored);
            $matchedStored = 0;
            foreach ($attendeeIds as $attendeeId) {
                if ($attendeeId === $senderId) {
                    continue;
                }

                if (in_array($attendeeId, $storedIds, true)) {
                    $matchedStored++;
                }
            }

            if ($matchedStored > 0 && $matchedStored === count($storedIds)) {
                return $conversation;
            }

            if ($lead && $provider === 'instagram') {
                $knownIds = $this->instagramAttendeeIdsForLead($lead);
                foreach ($attendeeIds as $attendeeId) {
                    if (in_array($attendeeId, $knownIds, true)) {
                        return $conversation;
                    }
                }
            }
        }

        if ($candidates->count() === 1 && $provider === 'instagram') {
            return $candidates->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mergeOrphanIntoOutreachConversation(
        V2Conversation $target,
        V2Conversation $orphan,
        string $chatId,
        array $payload,
    ): V2Conversation {
        if ($orphan->id !== $target->id) {
            $orphanMessages = V2Message::query()->where('conversation_id', $orphan->id)->get();

            foreach ($orphanMessages as $message) {
                if ($this->conversationHasMessageDuplicate($target, $message)) {
                    $message->delete();

                    continue;
                }

                $message->forceFill(['conversation_id' => $target->id])->save();
            }

            $orphan->delete();
        }

        $this->repairConversationChatId($target, $chatId, $payload);
        $this->dedupeConversationMessages($target);

        $lead = $this->resolveOutreachLeadFromConversation($target);
        if ($lead) {
            $this->rememberInstagramAttendeeAliases($lead, $this->extractAttendeeIds($payload));
        }

        return $this->enrichConversationMeta($target->fresh() ?? $target, $payload);
    }

    private function conversationHasMessageDuplicate(V2Conversation $conversation, V2Message $candidate): bool
    {
        $providerMessageId = trim((string) ($candidate->provider_message_id ?? ''));

        if ($providerMessageId !== '') {
            return V2Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('provider_message_id', $providerMessageId)
                ->exists();
        }

        $body = trim((string) ($candidate->body ?? ''));
        if ($body === '') {
            return false;
        }

        return V2Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', $candidate->direction)
            ->where('body', $body)
            ->exists();
    }

    private function discoverChatIdFromSiblingConversation(V2Conversation $conversation): ?string
    {
        $leadId = (int) (Arr::get($conversation->meta, 'outreach_lead_id') ?? 0);
        if ($leadId <= 0) {
            return null;
        }

        $orphan = V2Conversation::query()
            ->where('user_id', $conversation->user_id)
            ->where('provider', $conversation->provider)
            ->where('id', '!=', $conversation->id)
            ->whereNotNull('provider_chat_id')
            ->where('provider_chat_id', '!=', (string) ($conversation->provider_chat_id ?? ''))
            ->where('meta->source', 'unified_inbox')
            ->where('meta->outreach_lead_id', $leadId)
            ->orderByDesc('last_message_at')
            ->first();

        $chatId = trim((string) ($orphan?->provider_chat_id ?? ''));

        if ($chatId !== '' && $this->isGroupChatId($chatId, (string) $conversation->provider)) {
            return null;
        }

        return $chatId !== '' ? $chatId : null;
    }

    /**
     * @return array<int, string>
     */
    private function leadProviderIdentifiers(V2OutreachLead $lead, string $provider): array
    {
        $meta = is_array($lead->meta) ? $lead->meta : [];

        $identifiers = match ($provider) {
            'whatsapp' => [
                trim((string) ($lead->phone ?? '')),
                trim((string) Arr::get($meta, 'whatsapp_provider_id')),
            ],
            'instagram' => $this->instagramAttendeeIdsForLead($lead),
            'telegram' => [
                trim((string) Arr::get($meta, 'telegram_provider_id')),
                trim((string) Arr::get($meta, 'telegram_handle')),
            ],
            'twitter' => [
                trim((string) Arr::get($meta, 'twitter_provider_id')),
                trim((string) Arr::get($meta, 'twitter_handle')),
            ],
            'email' => [
                trim((string) ($lead->email ?? '')),
                trim((string) Arr::get($meta, 'prospect_email')),
            ],
            default => [],
        };

        return array_values(array_unique(array_filter($identifiers, fn ($id) => $id !== '')));
    }

    private function senderMatchesOutreachLead(V2OutreachLead $lead, string $provider, string $senderId): bool
    {
        $senderId = trim($senderId);
        if ($senderId === '') {
            return false;
        }

        if ($provider === 'email') {
            return strtolower($senderId) === strtolower(trim((string) ($lead->email ?? '')));
        }

        return in_array($senderId, $this->leadProviderIdentifiers($lead, $provider), true);
    }

    private function requiresStrictSenderMatch(string $provider): bool
    {
        return in_array($provider, ['whatsapp', 'telegram', 'twitter', 'email'], true);
    }

    private function resolveOutreachLeadFromConversation(V2Conversation $conversation): ?V2OutreachLead
    {
        $leadId = (int) (Arr::get($conversation->meta, 'outreach_lead_id') ?? 0);
        if ($leadId <= 0) {
            return null;
        }

        return V2OutreachLead::query()->find($leadId);
    }

    /**
     * @return array<int, string>
     */
    private function instagramAttendeeIdsForLead(V2OutreachLead $lead): array
    {
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $ids = [];

        foreach ([
            Arr::get($meta, 'instagram_provider_id'),
            Arr::get($meta, 'instagram_scoped_id'),
        ] as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        $aliases = Arr::get($meta, 'instagram_attendee_ids', []);
        if (is_array($aliases)) {
            foreach ($aliases as $alias) {
                $alias = trim((string) $alias);
                if ($alias !== '') {
                    $ids[] = $alias;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, string>  $attendeeIds
     */
    private function rememberInstagramAttendeeAliases(V2OutreachLead $lead, array $attendeeIds): void
    {
        if ($attendeeIds === []) {
            return;
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $known = $this->instagramAttendeeIdsForLead($lead);
        $aliases = is_array($meta['instagram_attendee_ids'] ?? null) ? $meta['instagram_attendee_ids'] : [];
        $changed = false;

        foreach ($attendeeIds as $attendeeId) {
            $attendeeId = trim($attendeeId);
            if ($attendeeId === '' || in_array($attendeeId, $known, true) || in_array($attendeeId, $aliases, true)) {
                continue;
            }

            $aliases[] = $attendeeId;
            $changed = true;
        }

        if (! $changed) {
            return;
        }

        $meta['instagram_attendee_ids'] = array_values(array_unique($aliases));
        $lead->forceFill(['meta' => $meta])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isGroupChatPayload(array $payload, string $chatId = '', string $provider = ''): bool
    {
        if ($provider === '') {
            $provider = (string) ($this->resolveProviderFromPayload($payload) ?? '');
        }

        if ($chatId !== '' && $this->isGroupChatId($chatId, $provider)) {
            return true;
        }

        foreach ([
            Arr::get($payload, 'data.is_group'),
            Arr::get($payload, 'is_group'),
            Arr::get($payload, 'data.chat.is_group'),
            Arr::get($payload, 'chat.is_group'),
        ] as $flag) {
            if ($this->isTruthy($flag)) {
                return true;
            }
        }

        foreach ([
            Arr::get($payload, 'data.chat.type'),
            Arr::get($payload, 'chat.type'),
            Arr::get($payload, 'data.type'),
            Arr::get($payload, 'type'),
        ] as $type) {
            $normalized = strtolower(trim((string) $type));
            if (in_array($normalized, ['group', 'channel', 'broadcast'], true)) {
                return true;
            }
        }

        $payloadChatId = (string) (
            Arr::get($payload, 'data.chat_id')
            ?? Arr::get($payload, 'chat_id')
            ?? ''
        );

        if ($payloadChatId !== '' && $this->isGroupChatId($payloadChatId, $provider)) {
            return true;
        }

        foreach ([
            Arr::get($payload, 'data.chat.name'),
            Arr::get($payload, 'chat.name'),
            Arr::get($payload, 'data.subject'),
            Arr::get($payload, 'subject'),
        ] as $subject) {
            $normalized = strtolower(trim((string) $subject));
            if ($provider === 'email' && str_starts_with($normalized, 'undisclosed recipients')) {
                return true;
            }
        }

        $attendees = Arr::get($payload, 'data.attendees', Arr::get($payload, 'attendees', []));
        $maxDirectAttendees = in_array($provider, ['whatsapp', 'telegram', 'twitter', 'instagram'], true) ? 2 : 3;
        if (is_array($attendees) && count($attendees) > $maxDirectAttendees) {
            return true;
        }

        return false;
    }

    private function isGroupChatId(string $chatId, string $provider = ''): bool
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return false;
        }

        $lower = strtolower($chatId);
        if (str_contains($lower, '@g.us')) {
            return true;
        }

        if ($provider === 'telegram' || $provider === '') {
            if (preg_match('/^-100?\d+$/', $chatId) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function isGroupChatArray(array $chat, string $provider = ''): bool
    {
        if ($this->isTruthy($chat['is_group'] ?? false)) {
            return true;
        }

        $type = strtolower(trim((string) ($chat['type'] ?? '')));
        if (in_array($type, ['group', 'channel', 'broadcast'], true)) {
            return true;
        }

        $chatId = trim((string) ($chat['id'] ?? $chat['chat_id'] ?? ''));
        if ($this->isGroupChatId($chatId, $provider)) {
            return true;
        }

        $attendees = Arr::get($chat, 'attendees', Arr::get($chat, 'attendee_ids', []));
        $maxDirectAttendees = in_array($provider, ['whatsapp', 'telegram', 'twitter', 'instagram'], true) ? 2 : 3;
        if (is_array($attendees) && count($attendees) > $maxDirectAttendees) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractSenderId(array $payload): string
    {
        foreach ([
            Arr::get($payload, 'data.sender.provider_id'),
            Arr::get($payload, 'sender.provider_id'),
            Arr::get($payload, 'data.sender.attendee_provider_id'),
            Arr::get($payload, 'sender.attendee_provider_id'),
            Arr::get($payload, 'data.sender_id'),
            Arr::get($payload, 'sender_id'),
        ] as $id) {
            $value = trim((string) ($id ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function invalidateProviderChatId(V2Conversation $conversation, string $invalidChatId, string $reason): void
    {
        $invalidChatId = trim($invalidChatId);
        if ($invalidChatId === '') {
            return;
        }

        \Illuminate\Support\Facades\Log::warning('[Inbox] Cleared invalid provider chat id from outreach thread', [
            'conversation_id' => $conversation->id,
            'provider' => $conversation->provider,
            'chat_id' => $invalidChatId,
            'reason' => $reason,
        ]);

        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $meta['invalid_provider_chat_id'] = $invalidChatId;
        $meta['invalid_provider_chat_id_reason'] = $reason;

        $conversation->forceFill([
            'provider_chat_id' => null,
            'meta' => $meta,
        ])->save();
    }

    /**
     * Unipile sometimes returns only a message id for startChat — wait for webhook chat id.
     *
     * @param  array<string, mixed>  $response
     */
    private function shouldDeferProviderChatId(array $response, string $chatId, string $providerMessageId): bool
    {
        $explicitChatId = trim((string) (
            Arr::get($response, 'chat_id')
            ?? Arr::get($response, 'data.chat_id')
            ?? ''
        ));

        if ($explicitChatId !== '') {
            return false;
        }

        if ($providerMessageId !== '' && $chatId === $providerMessageId) {
            return true;
        }

        $object = strtolower(trim((string) (
            Arr::get($response, 'object')
            ?? Arr::get($response, 'type')
            ?? Arr::get($response, 'data.object')
            ?? ''
        )));

        return in_array($object, ['message', 'chat_message'], true);
    }
}
