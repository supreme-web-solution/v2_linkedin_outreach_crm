# WhatsApp control via Zernio

WhatsApp here is a **control interface** for the AI Sales Employee (“Alex”), not prospect outreach.

## Dual WhatsApp reminder

| | Control (this doc) | Prospect (existing) |
| --- | --- | --- |
| Provider | Zernio | Unipile |
| Number | SociFusion shared bot number | User’s connected WhatsApp |
| Messages | User ↔ Alex | User/system ↔ leads |
| Inbox | AI conversation + approvals | Unified Inbox |

## Link flow (identity)

1. Web: **Command Center → Connect WhatsApp**
2. App creates `ai_channel_link_codes` row: code `LINK-XXXXX`, expires ~15 min, scoped to `user_id` + `organization_id`
3. UI shows: message `+ZERNIO_NUMBER` with the code (optional `wa.me` deep link)
4. User texts Zernio number
5. Webhook `message.received` → if body matches link code → upsert `ai_channel_identities` (`channel=whatsapp`, E.164 phone, user, org, `status=active`)
6. Store `zernio_conversation_id` + `zernio_account_id` on identity `meta` from webhook payload
7. Reply: “You’re connected to SociFusion as {name}. Ask me anything.”
8. On number change: require new link; deactivate old identity

Unlinked inbound messages get a short “Open SociFusion → Connect WhatsApp” reply. No agent tools run.

## Webhook flow (Zernio Inbox API)

```text
POST /api/v2/provider-events/zernio
  → verify X-Zernio-Signature (HMAC-SHA256 of raw body)
  → dedupe on payload.id (ai_zernio_webhook_events)
  → only handle event=message.received (ack others with 200)
  → normalize text + button payloads (LAUNCH_42 → LAUNCH 42)
  → if link code → ChannelIdentityService::link()
  → else resolve identity by phone
  → if missing → send connect instructions
  → if kill switch / AI disabled → refuse
  → AgentOrchestrator::handle(channel: whatsapp, ...)
  → Zernio typing indicator while Alex prepares reply (refreshed every ~18s)
  → ZernioClient::sendToIdentity(...) with optional Launch/Reject buttons
```

Subscribe in Zernio dashboard to at least: `message.received`.

Idempotency: Zernio retries for up to ~51h. We store `payload.id` before processing and skip duplicates.

## Send path (production)

Uses Zernio Inbox API:

```http
POST /v1/inbox/conversations/{conversationId}/messages
Authorization: Bearer {ZERNIO_API_KEY}

{
  "accountId": "{ZERNIO_ACCOUNT_ID or from webhook}",
  "message": "Reply text",
  "buttons": [
    { "type": "postback", "title": "Launch", "payload": "LAUNCH_42" },
    { "type": "postback", "title": "Reject", "payload": "REJECT_42" }
  ]
}
```

`conversationId` + `accountId` are captured from each inbound webhook and stored on `ai_channel_identities.meta`.

Long replies are chunked at Zernio's 1024-character inbox limit. Stub mode logs when `ZERNIO_API_KEY` is unset.

While Alex generates a reply, we call `POST /v1/inbox/conversations/{conversationId}/typing` so the user sees WhatsApp’s native “typing…” bubble. WhatsApp clears it after ~25 seconds, so we refresh every `ZERNIO_TYPING_REFRESH_SECONDS` (default 18) until the reply is sent.

## Approvals on WhatsApp

Prepare tools create `ai_action_approvals`. Alex replies with a plan card and:

- Text: `LAUNCH {id}` / `REJECT {id}` / `REVIEW {id}`
- Buttons: **Launch #{id}** / **Reject #{id}** (when inbox API enabled) — payloads `LAUNCH_{id}` / `REJECT_{id}`. If WhatsApp sends only the button title, bare **Launch** maps to your newest pending plan.

Execute tools only after approval (unless Autopilot allowlist).

## Command examples (same tools as web)

- “How’s the campaign going?” → `get_campaign_stats`
- “Who needs my attention?” → `get_attention_queue`
- “Find 500 US agencies…” → `propose_strategy` / `draft_campaign_plan`
- “Pause all campaigns.” → `pause_outreach_campaign`
- Button tap **Launch** → approve pending plan

## Env keys

```env
ZERNIO_BASE_URL=https://api.zernio.com
ZERNIO_API_KEY=
ZERNIO_WEBHOOK_SECRET=
ZERNIO_ACCOUNT_ID=          # WhatsApp account id in Zernio (fallback if not in webhook)
ZERNIO_USE_INBOX_API=true
ZERNIO_TYPING_INDICATOR=true
ZERNIO_TYPING_REFRESH_SECONDS=18
ZERNIO_FROM_NUMBER=+19295320453
SOCIFUSION_AI_ENABLED=true
SOCIFUSION_AI_KILL_SWITCH=false
OPENAI_API_KEY=               # required for agent replies
```

Webhook URL: `POST https://{your-domain}/api/v2/provider-events/zernio`

Configure webhook secret in Zernio dashboard; same value as `ZERNIO_WEBHOOK_SECRET`.

## Voice notes

Inbound WhatsApp **voice / audio** messages are transcribed with Laravel AI (`Transcription::fromPath`) and then run through the **same** Command Center orchestrator as text.

Flow:
1. Zernio `message.received` with audio attachment (or `audio` / `voice` fields)
2. Download media → temp file under `storage/app/tmp/whatsapp-voice`
3. `WhatsAppVoiceTranscriptionService` → OpenAI (default transcription provider)
4. Prefixed message `[Voice note]\n{transcript}` → queue / `AgentOrchestrator`
5. Reply delivered on WhatsApp as text (same approval buttons)

Images still require a text caption. Voice notes do not.

Requires `OPENAI_API_KEY` (or whatever `config('ai.default_for_transcription')` uses).

## Telegram (Phase 4)

Same identity + orchestrator pattern as WhatsApp. Only after WA is stable.
