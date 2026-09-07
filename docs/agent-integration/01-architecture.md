# Architecture — SociFusion AI Control Plane

## Principle

WhatsApp, Telegram, and the web app are **interfaces**. They do not own business logic.

```text
                   USER
                     │
          ┌──────────┼──────────┐
          │          │          │
       WhatsApp   Telegram   Web App
       (Zernio)   (later)   (Command Center)
          │          │          │
          └──────────┼──────────┘
                     │
              SOCIFUSION AI
              ORCHESTRATOR
           (Laravel AI SDK)
                     │
       ┌─────────────┼─────────────┐
       │             │             │
   Strategy      Prospecting   Conversation
   (tools)         (tools)        (tools)
       │             │             │
       └─────────────┼─────────────┘
                     │
             SOCIFUSION ENGINE
        (existing V2 services/jobs)
                     │
    ┌────────┬───────┼────────┬─────────┐
    │        │       │        │         │
 LinkedIn  Email  WhatsApp  Instagram Telegram
 (Unipile)                (prospect channel)
    │
   CRM / Calendar / Analytics / Inbox
```

## One agent, many capabilities

Product language may say “Strategy Agent”, “Prospecting Agent”, etc. **Implementation** is one `SociFusionAgent` with capability tools:

| Product capability | Implementation |
| --- | --- |
| Strategy | `build_icp`, `propose_strategy` tools |
| Prospecting | `find_prospects`, `analyze_competitor_audience` |
| Research & enrichment | `enrich_contact`, `build_prospect_brief` |
| Personalization | `draft_personalized_message` |
| Outreach | `draft_campaign`, `launch_campaign`, `pause_campaign` |
| Conversation | `classify_reply`, `draft_reply`, `get_attention_queue` |
| Sales manager | `get_sales_brief`, `get_campaign_stats` |
| Meetings | `get_meeting_brief`, `book_meeting` (later) |

Optional later: sub-agents via Laravel AI `CanActAsTool` if prompts get too large — still one user-facing “Alex”.

## Dual WhatsApp (critical)

| Layer | Provider | Audience | Purpose |
| --- | --- | --- | --- |
| Control bot | **Zernio** (SociFusion number) | Paying user | Commands, approvals, status |
| Prospect channel | **Unipile** (user’s WA) | Leads | Outreach + Unified Inbox |

Never send prospect messages from the Zernio control number. Never treat Unipile WA webhooks as Alex commands.

## Permission model

Every tool declares a permission class:

| Class | Examples | Gate |
| --- | --- | --- |
| `read` | stats, attention queue, sales brief | Auto (if AI enabled) |
| `prepare` | find prospects, draft campaign, draft reply | Creates approval / review payload |
| `execute` | launch, send, pause, retarget | Autonomy level + explicit approve |

### Autopilot levels (org/user setting)

| Level | Name | Behavior |
| --- | --- | --- |
| 1 | Copilot | Recommend only; user executes in UI |
| 2 | Assisted | AI prepares; user must approve |
| 3 | Autopilot | Auto-run allowlisted execute tools |
| 4 | Autonomous | Full workflow within hard guardrails |

Default ship: **Assisted (2)**. Autonomous only after trust.

## Runtime flow

```text
Inbound (Web | Zernio WA | Telegram)
  → Resolve user + organization
  → Check kill switch / entitlements
  → Load/create ai conversation
  → SociFusionAgent::prompt(...)
  → Tool calls (gated)
       → V2 services
       → DB / queues / Unipile
  → Persist messages + action logs
  → Reply on same channel
```

## Identity for messaging control

Control channels require `ai_channel_identities` binding phone (or TG chat id) → `user_id` + `organization_id`. See [04-whatsapp-zernio.md](./04-whatsapp-zernio.md).

## Stack choice

| Choice | Why |
| --- | --- |
| `laravel/ai` | First-party agents, tools, memory, queues — fits Laravel 13 app |
| Not OpenClaw | Avoids a second runtime between user and SociFusion |
| Wrap V2 services | Keeps Unipile, campaigns, inbox as source of truth |
