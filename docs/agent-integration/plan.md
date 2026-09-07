# SociFusion AI — Executive plan

**Full docs:** [README.md](./README.md) · [Command Center](./07-command-center.md)

SociFusion already has the **execution layer** (campaigns, outreach, Unipile channels, inbox, enrichment, CRM). What’s missing is the **control plane**: one AI brain, tools that wrap existing services, and WhatsApp as a command interface — not another outreach channel.

## Core product insight (stick to this)

Users should not learn audiences, sequences, enrichment, and CRM stages.  
They tell SociFusion what they want — from the **web or WhatsApp** — and Alex runs the Command Center.

WhatsApp is **not** a second product. It is the same Command Center on the phone (Zernio control number). Unipile WhatsApp stays prospect outreach only.

## Verdict

| Idea | Take |
| --- | --- |
| One SociFusion AI + tools (not 15 UI agents) | Build this |
| Laravel AI SDK over OpenClaw | Correct for this stack |
| WhatsApp control via Zernio | Same brain as web; separate from Unipile prospect WA |
| Shared Command Center session | One conversation per user+org across channels |
| Autopilot levels + approval gates | From day one |

Don’t rebuild SociFusion. Add a brain on top.

## Dual WhatsApp

| Layer | Provider | Purpose |
| --- | --- | --- |
| **Control bot** | Zernio (SociFusion number) | User talks to “Alex” / Command Center |
| **Prospect channel** | Unipile (user’s WA) | Outreach + Unified Inbox |

See [04-whatsapp-zernio.md](./04-whatsapp-zernio.md) and [07-command-center.md](./07-command-center.md).

## Shape

```text
SociFusionAgent (Laravel AI SDK) — "Alex"
  ├── instructions + autonomy + tenant context
  ├── shared Command Center memory
  └── tools → existing V2 services only
```

Runtime: `App\Ai\Agents\SociFusionAgent` implements `Laravel\Ai\Contracts\Agent` and uses the `Promptable` trait. `AgentOrchestrator` calls `(new SociFusionAgent($context))->prompt($message)`.

Never: Agent → SQL. Always: Agent → Tool → Service → Model.

## Build order

1. **Phase 0** — Foundation ← done  
2. **Phase 1** — Command Center (web + WhatsApp same session) ← done  
3. **Phase 2** — Zernio production ← **code done**; you add live `ZERNIO_*` keys when ready  
4. **Phase 3** — AI Sales Execution ← done  
5. **Phase 5** — Autonomous Employee ← **core tools done** (optimizer + auto wait adjust, meeting brief, qualify, post-call CRM, weekly brief, book_meeting, discover_prospects, inbox_brief)
6. **Later** — Telegram control, Slack control, voice notes, full Autonomous default

## Vision checklist (Command Center)

| Capability | Status |
| --- | --- |
| Full AI Inbox brief (“AI handled N, M need you”) | Done — `get_attention_queue` + sidebar `inbox_brief` |
| Auto book meetings from chat | Done — `book_meeting` tool + Launch sends calendar link |
| Telegram control | Deferred — outreach Telegram via Unipile only |
| Slack / other control surfaces | Deferred |
| Voice notes to Alex | Deferred |
| Default autonomy | Done — **Autopilot (3)** default + onboarding; Autonomous remains opt-in (admin) |
| Action history / undo UI | Done — sidebar shows **latest 5**; full paginated list at `/ai-employee/activity` |
| Web/widget chat queue | Done — `ProcessWebAiChatJob` + poll; navigation no longer waits on LLM |
| Optimizer auto-adjusts on Launch | Done — `shorten_waits` when funnel drop-off detected |
| Net-new discovery without lists | Partial — `discover_prospects` merges lists + competitor harvest |
| **Let AI Execute** (multi-step sales manager) | Done — `let_ai_execute` tool + Dashboard “Stage plan” + one Launch |
| Goal-based onboarding wizard | Done — Alex modal on Dashboard (`/onboarding/*`) |
| Integration readiness before Launch | Done — `check_integrations` + Launch blocked until channels connected (web + WhatsApp) |
| Campaign inbox AI (auto-reply + context) | Done — `configure_campaign_inbox_ai` → Review & Launch |
| Nurture due follow-ups | Done — `get_nurture_due_queue`, daily `nurture:flag-due`, Alex starter in Command Center |
| CSV import via Alex | Done — `import_leads_csv` stages import → Launch attaches `list_hash` |
| Plan funnel on Review & Launch | Done — Audience → Source → Contacts → Channels → Sequence → Goal |
| Create Campaign primary UX → Alex | Additive — Ask Alex added beside existing Create / New outreach / builder CTAs |
| Full Sales Manager execute batch | Done — follow-up, nurture, pause, scale 20%, activate drafts, channel-mix shift |
| First-touch personalization on Launch | Done — AI campaigns flag personalize; job after sync; send path uses draft |
| Net-new discover with target_count | Done — LinkedIn auto-search respects target (~100/run) |
| build_icp depth (website/customers/competitors) | Done — full loop + “find prospects” CTA after Launch |
| Next best action on outreach leads | Done — strip on Outreach Detail lead rows |

## WhatsApp (two layers — do not mix)

| Layer | Provider | Role in AI agent |
| --- | --- | --- |
| **Command Center** | Zernio (`ZERNIO_*`) | User talks to Alex; LAUNCH/REJECT; same session as web |
| **Prospect outreach + inbox** | Unipile (user’s WA) | Sequence channel + unified inbox threads Alex reads/replies to |

Alex never uses Zernio to message prospects. Alex uses Unipile for prospect WhatsApp in campaigns and inbox.

Details: [02-phases.md](./02-phases.md). Ops: `php artisan ai:diagnose`.

## Product north star

User (web or WhatsApp): *“I need 30 meetings with US SaaS founders this month.”*  
Alex proposes strategy, prospects, channels, sequence → **Review & Launch** — complexity stays under the hood; power-user builders remain available.
