# Multi-Channel Outreach — Feature Plan

**Status:** Draft  
**Date:** 2026-07-19  
**Scope:** New standalone product module. Does **not** modify the existing LinkedIn Campaign system or browser extension flow.

> **Status & checklists:** See [00-feature-status-checklist.md](./00-feature-status-checklist.md) — phase boxes below are **historical** and many items are already shipped.

---

## 1. Vision

Build a **La Growth Machine–style multichannel outreach engine** inside the app:

- One **prospect** enters one **automation**
- Steps can mix **LinkedIn, Email, X (Twitter), WhatsApp, Telegram, Instagram**
- Each step belongs to exactly **one channel**
- **One connected account per channel per workspace** (no account picker in the builder)
- All execution goes through **Unipile** (no extension, no Phantom/Voyager for this module)
- Users manage it from its **own pages**, nav section, queues, and analytics

The existing **Campaign** feature (`/campaigns`) remains the LinkedIn + extension product. This new module is a separate lane for SaaS-style, API-powered outreach.

---

## 2. Product split (do not merge)

```
Your App
│
├── LinkedIn Campaigns (EXISTING — do not change behavior)
│     ├── Browser extension sync
│     ├── li_at cookie connect on Integrations
│     ├── V2Campaign + node_model tree
│     ├── ProcessCampaignLeadJob (campaigns queue)
│     └── CampaignFlowCanvas builder
│
└── Multi-Channel Outreach (NEW)
      ├── Unipile-only execution
      ├── Separate DB tables + routes + UI
      ├── ProcessMultichannelLeadJob (new queue)
      ├── Channel-agnostic sequence builder
      └── Shared: contacts/leads, workspace, billing, AI, Integrations hub
```

**Shared surfaces (safe to extend):**

| Shared | How |
|--------|-----|
| Lead lists | Reuse Audience / Sales Navigator lists as audience sources |
| Integrations page | Add more channel cards beside LinkedIn |
| Unipile webhooks | Extend handler to update multichannel progress |
| AI messages | Optional step type before send actions |
| Disconnect guard pattern | Reuse `CampaignLinkedInGuard` pattern per channel |

**Must stay isolated:**

| Existing | Rule |
|----------|------|
| `V2Campaign`, `ProcessCampaignLeadJob` | No multichannel step types |
| Extension API (`/api/v2/campaigns/*`) | No changes |
| `CampaignStepExecutor` | No email/WhatsApp/X steps added here |
| Extension runner | Never executes multichannel campaigns |

---

## 3. Unipile channels (source of truth)

Based on Unipile developer API account types and current app integration (`UnipileProvider.php`):

### Phase 1 — MVP channels (recommended launch order)

| UX channel | Unipile account type(s) | Auth method | Already in app? |
|------------|-------------------------|-------------|-----------------|
| **LinkedIn** | `LINKEDIN` | Hosted auth, credentials, **li_at cookie** | Yes (Integrations) |
| **Email** | `GOOGLE_OAUTH`, `OUTLOOK`, `MAIL`, `ICLOUD` | Hosted OAuth / IMAP | Partial (ESP is separate; not Unipile email) |
| **WhatsApp** | `WHATSAPP` | Hosted auth (QR) | No |
| **Instagram** | `INSTAGRAM` | Hosted auth | No |
| **Telegram** | `TELEGRAM` | Hosted auth | No |
| **X (Twitter)** | `TWITTER` | Hosted auth | No |

### Phase 2 — when Unipile + product ready

| Channel | Notes |
|---------|-------|
| Google / Outlook Calendar | Unipile supports; useful for “book meeting” steps later |
| SMS / RCS | On Unipile roadmap; skip until official |

### Important distinction for LinkedIn in this module

- **Existing campaigns:** extension-assisted, deeply LinkedIn-specific, `li_at` flow tied to extension identity.
- **Multichannel LinkedIn steps:** Unipile API only (visit profile, invite, message, like post, etc.) — same Unipile account record can back both, but **execution paths must not cross**.

Recommendation: store multichannel LinkedIn connection as a **separate `V2IntegrationAccount` row** with `context: multichannel` in meta, or use the same row but **never route extension jobs through multichannel executor**. Simplest v1: one LinkedIn account per workspace; multichannel executor reads `provider=linkedin` from integrations; extension continues as today.

---

## 4. Unipile capability → action mapping

Each channel exposes an **action library**. Only list actions Unipile can perform (verify against [Unipile API Reference](https://developer.unipile.com/reference) before shipping).

### LinkedIn (Unipile — already partially implemented)

| Action key | Unipile capability | App today |
|------------|-------------------|-----------|
| `visit_profile` | Profile view action | Yes (`performLinkedinProfileAction`) |
| `send_invite` | `POST /users/invite` | Yes |
| `send_message` | `startChat` + `sendMessage` | Yes |
| `like_post` | `reactToPost` | Yes |
| `comment_post` | Post comments API | Partial |
| `endorse_skills` | Profile action | Yes |
| `follow` | Limited / stub | Stub only — mark as beta or hide |
| `withdraw_invite` | `withdrawInvitation` | Yes |

### Email (Unipile — new provider work)

| Action key | Unipile capability |
|------------|-------------------|
| `send_email` | `POST /emails` |
| `reply_email` | Reply to thread |
| `forward_email` | Forward |

**Conditions (via webhooks / polling):**

| Condition key | Detection |
|---------------|-----------|
| `email_opened` | Unipile email tracking webhooks (if enabled) |
| `email_clicked` | Tracking webhook |
| `email_replied` | Inbound email webhook |
| `email_bounced` | Bounce event |

### WhatsApp / Instagram / Telegram (Unipile messaging API)

Unified messaging schema across providers:

| Action key | Unipile capability |
|------------|-------------------|
| `send_message` | `POST /chats/.../messages` or start chat |
| `send_media` | Attachment send (where supported) |

**Conditions:**

| Condition key | Detection |
|---------------|-----------|
| `message_replied` | Inbound message webhook |
| `message_read` | Read receipt webhook (provider-dependent) |

### X / Twitter (Unipile `TWITTER` account)

| Action key | Expected Unipile capability |
|------------|------------------------------|
| `send_dm` | Messaging API |
| `follow` | User relation action |
| `like_tweet` | Post reaction |
| `reply_tweet` | Reply / comment |

Verify exact endpoints in Unipile dashboard before UI promises. Hide actions not confirmed in API.

### Universal steps (no channel)

| Step type | Purpose |
|-----------|---------|
| `wait` | Delay N hours/days, or until weekday |
| `condition` | Branch on channel-specific event |
| `end` | Stop sequence for lead |
| `ai_compose` (optional v2) | Generate message body before next send step |

---

## 5. UX design

### 5.1 Navigation

Add a new sidebar group (do not rename existing Campaign):

```
Outreach
├── Leads
├── Campaign              ← existing LinkedIn campaigns
├── Multi-Channel         ← NEW (/outreach or /multichannel)
├── Call Manager
├── Conversations
└── Auto-Responses
```

### 5.2 Pages (new)

| Route | Page | Purpose |
|-------|------|---------|
| `GET /outreach` | `OutreachCampaigns.vue` | List multichannel campaigns |
| `GET /outreach/create` | `OutreachBuilder.vue` | Template → audience → sequence → launch |
| `GET /outreach/{id}` | `OutreachDetail.vue` | Live progress, activity, pause/resume |
| `GET /outreach/{id}/edit` | `OutreachBuilder.vue` | Edit sequence |

Integrations stay at **`/integrations`** — one hub for all channels.

### 5.3 Integrations page (extend, don’t fork)

Current: LinkedIn card + ESP section.

**Target layout:**

```
Connected Channels (Unipile)
────────────────────────────
LinkedIn     🟢 Connected    john@linkedin    [Verify] [Disconnect]
Email        🟢 Gmail         john@gmail.com   [Connect different]
X            🔴 Not connected                  [Connect]
WhatsApp     🔴 Not connected                  [Connect]
Instagram    🔴 Not connected                  [Connect]
Telegram     🔴 Not connected                  [Connect]

ESP (legacy lead export) — keep as-is, separate section
```

**Connection flow per channel:**

1. User clicks **Connect**
2. Backend calls Unipile **hosted auth link** for that provider type
3. User completes OAuth / QR / credentials on Unipile
4. Webhook or polling stores `V2IntegrationAccount` with `provider` = channel key
5. Integrations page shows live status via `GET /accounts/{id}` probe (same pattern as `LinkedInConnectionService`)

**Rule:** one row per `(organization_id, provider)` — connecting again replaces previous.

### 5.4 Campaign builder UX (Model 1 — mixed multichannel sequence)

Reuse the **visual patterns** from `CampaignFlowCanvas` (Vue Flow, fullscreen editor) but with a **new component** `OutreachFlowCanvas.vue` and **new types** — do not modify `CampaignFlowCanvas`.

**Add Step flow:**

```
+ Add Step
──────────────
LinkedIn      →  (only if connected)
Email         →  (only if connected)
X             →  (only if connected)
WhatsApp      →  (only if connected)
Instagram     →
Telegram      →
──────────────
Wait
Condition
End sequence
```

After channel pick → show that channel’s action list.

**Node visual:** every action node shows a **channel badge** (icon + color):

```
┌─────────────────────────┐
│ [in] LinkedIn           │
│ Send Connection Request │
└─────────────────────────┘
```

**Disconnected channel:** if user imports a template with X steps but X not connected, node shows:

> Connect X on Integrations to run this step.

Campaign can be saved as draft; launch blocked until required channels connected.

### 5.5 Campaign settings panel

```
Connected channels for this workspace
✅ LinkedIn   ✅ Email   ❌ X   ❌ WhatsApp

Steps using disconnected channels: 2
[Go to Integrations]
```

No per-step account picker (one account per workspace per channel).

### 5.6 Conditions UX

User picks **channel first**, then condition:

| Channel | Example conditions |
|---------|-------------------|
| LinkedIn | Invite accepted · Invite pending · Has replied · Is 1st connection |
| Email | Opened · Clicked · Replied · Bounced · No reply after N days |
| X | Followed back · DM replied · Replied to tweet |
| WhatsApp / IG / Telegram | Message replied · Conversation exists |

Implementation: webhook updates lead progress state; condition node reads stored flags (same pattern as `acceptance_status` on `V2CampaignLeadProgress`).

---

## 6. Technical architecture

### 6.1 New database tables

Prefix with `v2_outreach_` to avoid collision with `v2_campaigns`.

```
v2_outreach_campaigns
  id, user_id, organization_id, name, status, node_model (json), meta (json), timestamps

v2_outreach_leads
  id, outreach_campaign_id, source (aud/sn/manual), provider_profile_id,
  email, phone, full_name, status, meta, timestamps

v2_outreach_lead_progress
  id, outreach_campaign_id, outreach_lead_id,
  current_node_key, next_node_key, completed_keys (json),
  channel_state (json),   -- e.g. { linkedin: { invite_accepted: true }, email: { opened: false } }
  next_run_at, run_status, timestamps

v2_outreach_runs
  id, outreach_campaign_id, status, started_at, finished_at, meta

v2_outreach_node_events
  id, outreach_campaign_id, outreach_lead_id, run_id, node_key,
  channel, action, status, message, payload (json), executed_at
```

**Node model shape (channel-aware):**

```json
{
  "key": 3,
  "type": "action",
  "channel": "email",
  "action": "send_email",
  "label": "Follow-up email",
  "config": {
    "subject": "Quick follow-up",
    "body": "Hi {{firstName}}, ..."
  }
}
```

```json
{
  "key": 5,
  "type": "wait",
  "value": 2,
  "time": "days"
}
```

```json
{
  "key": 6,
  "type": "condition",
  "channel": "linkedin",
  "condition": "invite_accepted",
  "branches": { "yes": [...], "no": [...] }
}
```

### 6.2 Backend modules (new namespace)

```
app/V2/Outreach/
  OutreachSequenceResolver.php      # walk tree, branches, next node
  OutreachStepExecutor.php          # dispatch by channel + action
  OutreachRunDispatcher.php
  OutreachActivityLogger.php
  OutreachCompletionService.php
  OutreachChannelGuard.php          # pause if any required channel disconnects
  Channels/
    ChannelExecutorInterface.php
    LinkedInChannelExecutor.php     # wraps existing UnipileProvider methods
    EmailChannelExecutor.php        # new Unipile email calls
    TwitterChannelExecutor.php
    WhatsAppChannelExecutor.php
    InstagramChannelExecutor.php
    TelegramChannelExecutor.php
```

Register executors in a `OutreachChannelRegistry` — adding a channel = new executor class + integration card + builder actions.

### 6.3 Queue & execution

| Item | Value |
|------|-------|
| Queue name | `outreach-campaigns` (distinct from `campaigns` and `outreach`) |
| Job | `ProcessOutreachLeadJob` |
| Pattern | Same as `ProcessCampaignLeadJob`: one lead per job, self-requeue with delay |
| Disconnect | Return without retry; call `OutreachChannelGuard::handleDisconnect()` |
| Rate limits | Stagger dispatch (5s between leads); per-channel daily caps in meta |

**Execution pseudocode:**

```
for each lead in campaign:
  node = resolveNextNode(lead.progress)
  if node.type == wait: schedule requeue at next_run_at
  if node.type == condition: evaluate channel_state; branch
  if node.type == action:
    account = IntegrationAccount.for(org, node.channel)
    if !account.active: pause campaign; notify user; STOP
    result = ChannelRegistry.execute(node.channel, node.action, lead, config)
    log event; update progress; requeue or complete
```

### 6.4 Webhooks

Extend `ProcessUnipileWebhookEventJob` with a **separate handler path** for outreach:

| Event | Updates |
|-------|---------|
| `message.received` | `channel_state.{channel}.replied = true` |
| `invitation.accepted` | `channel_state.linkedin.invite_accepted = true` |
| `email.opened` / `email.replied` | email condition flags |
| `account.disconnected` | mark integration disconnected; pause outreach campaigns using that channel |

Do **not** write to `V2CampaignLeadProgress` from this path.

### 6.5 Integrations backend changes

Extend `V2IntegrationAccount`:

- `provider` enum: `linkedin | google_email | outlook | imap | whatsapp | instagram | telegram | twitter`
- Or keep `provider` as channel group + `meta.unipile_type` for Unipile account type

New controller methods (or generalize existing):

- `POST /integrations/unipile/connect/{channel}` → hosted auth link
- `GET /integrations/channels` → all channels + connection summary for workspace
- Reuse verify/disconnect patterns from `LinkedInConnectionService`

### 6.6 Frontend (new files only)

```
resources/js/pages/crm/outreach/
  OutreachCampaigns.vue
  OutreachBuilder.vue
  OutreachDetail.vue

resources/js/components/outreach/
  OutreachFlowCanvas.vue
  OutreachStepConfigPanel.vue
  OutreachChannelIcon.vue
  OutreachStepPreviewChip.vue
  OutreachDisconnectBanner.vue
  nodes/  (channel-styled Vue Flow nodes)

resources/js/components/outreach/channels/
  channelRegistry.ts    # channel → actions, conditions, colors, icons
```

Copy **patterns** from `components/campaign/*`, not files — avoids accidental coupling.

---

## 7. Phased delivery plan

**Moved.** Implementation status, remaining work, and staging checklist live in:

→ **[00-feature-status-checklist.md](./00-feature-status-checklist.md)** (Section 5: Multi-channel outreach)

The phase boxes below are kept for historical context only.

<details>
<summary>Historical phase checklists (archived)</summary>

### Phase 0 — Foundation (1–2 weeks)

- [x] ADR sign-off on isolation rules (this doc)
- [x] DB migrations for `v2_outreach_*` tables
- [x] Models + empty CRUD routes/controllers
- [x] Sidebar nav + empty list page
- [x] `OutreachChannelRegistry` scaffold

### Phase 1 — Integrations multi-channel (1–2 weeks)

- [x] Integrations UI: channel cards for Email, WhatsApp, Instagram, Telegram, X
- [x] Hosted auth connect per Unipile provider type
- [x] Store multiple `V2IntegrationAccount` rows per org
- [x] Shared Inertia prop: `connectedChannels` summary
- [x] Verify + disconnect + disconnected banner per channel

### Phase 2 — Builder MVP (2–3 weeks)

- [x] `OutreachFlowCanvas` with channel → action picker
- [x] Templates: LinkedIn-only, Email-only, LinkedIn→Email sequence
- [x] Audience attach (reuse campaign list attach logic, new pivot table)
- [x] Save draft / launch validation (channels connected)

### Phase 3 — Execution engine (2–3 weeks)

- [x] `OutreachRunDispatcher` + `ProcessOutreachLeadJob`
- [x] `LinkedInChannelExecutor` (wrap existing UnipileProvider)
- [x] `EmailChannelExecutor` (Unipile email API)
- [x] Wait + end steps
- [x] Activity log + detail page live polling
- [x] Disconnect guard + pause (mirror recent campaign work)

### Phase 4 — Conditions & webhooks (1–2 weeks)

- [x] LinkedIn: invite accepted condition
- [x] Email: replied / no reply
- [x] Webhook → `channel_state` updates
- [x] Re-dispatch lead job on condition met

### Phase 5 — Additional channels (rolling)

- [x] WhatsApp executor + conditions
- [x] Instagram executor
- [x] Telegram executor
- [x] X executor (after API verification)

### Phase 6 — Polish

- [x] Per-campaign stats + funnel (detail page)
- [x] AI auto-reply per channel
- [x] Save / duplicate templates (org-local; no public marketplace)

</details>

---

## 8. What to reuse vs rebuild

| Reuse as-is | Rebuild new |
|-------------|-------------|
| `UnipileProvider` LinkedIn methods | Email / X / WhatsApp Unipile methods |
| Vue Flow canvas **patterns** | `OutreachFlowCanvas` component |
| Lead list sources (Audience, SN) | `v2_outreach_*` tables |
| Integrations page shell | Channel-specific connect cards |
| Queue worker infrastructure | `outreach-campaigns` queue |
| Disconnect guard **pattern** | `OutreachChannelGuard` |
| Inertia shared props pattern | `connectedChannels` prop |
| `CampaignLinkedInGuard` banner UX | `OutreachDisconnectBanner` |

---

## 9. Non-goals (v1)

- Mixing extension execution with multichannel steps
- Multiple accounts per channel per workspace
- Channels outside Unipile (no direct SMTP, no Meta Graph API bypass)
- Migrating existing `V2Campaign` sequences into outreach campaigns
- Unified inbox replacing Conversations (future consideration)
- SMS until Unipile ships it

---

## 10. Risks & mitigations

| Risk | Mitigation |
|------|------------|
| Accidentally coupling to existing campaigns | Separate tables, routes, jobs, Vue components; code review checklist |
| Unipile action not available (e.g. X follow) | Capability matrix per channel; hide until verified in staging |
| LinkedIn double-connection confusion | Clear UX labels: “LinkedIn (Extension campaigns)” vs “LinkedIn (Multichannel)” or single account with two execution engines documented |
| Webhook conditions lag | Polling fallback (6h) like current campaign conditions |
| Rate limits / bans | Stagger jobs, daily caps, pause on provider errors |
| ESP vs Unipile email overlap | Multichannel uses Unipile email only; keep ESP for lead export |

---

## 11. Success criteria

1. User connects Email + LinkedIn on Integrations
2. User creates multichannel campaign: LinkedIn invite → wait → email follow-up
3. Campaign runs on `outreach-campaigns` queue without touching `/campaigns` jobs
4. Disconnect shows banner on `/outreach` and pauses run (no infinite retries)
5. Existing extension LinkedIn campaign flow unchanged in regression test

---

## 12. Open decisions (resolve before Phase 2)

1. **Route prefix:** `/outreach` vs `/multichannel` vs `/sequences` — recommend **`/outreach`**
2. **LinkedIn account:** share one `V2IntegrationAccount` row or tag usage context in meta?
3. **Lead identity across channels:** match by email? LinkedIn URL? manual mapping table?
4. **Naming in UI:** “Multi-Channel Outreach” vs “Outreach Sequences” vs “Cross-Channel Campaigns”
5. **Entitlement flag:** gate behind new plan tier?

---

## 13. Reference links

- [Unipile Getting Started](https://developer.unipile.com/docs/getting-started)
- [Unipile API Reference](https://developer.unipile.com/reference)
- [Unipile Outreach use case](https://www.unipile.com/use-case-outreach/)
- Internal: `v2/docs/v2/04-unipile-capability-matrix.md`
- Internal: `v2/app/V2/Integrations/Unipile/UnipileProvider.php`
- Internal (existing campaign — do not modify for this feature): `v2/app/Jobs/V2/ProcessCampaignLeadJob.php`

---

*This document is the implementation blueprint. Next step: Phase 0 ADR + empty routes/pages PR, then Integrations multi-channel connect.*
