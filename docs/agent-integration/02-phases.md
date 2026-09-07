# Build phases

Aligned with the product brief (AI Copilot → Sales Execution → Autonomous Employee) and constrained by current SociFusion code.

## Phase 0 — Foundation (now)

**Goal:** Agent runtime + data model + gated tools (mostly stubs / read-only).

- [x] Docs under `docs/agent-integration/`
- [x] `composer require laravel/ai` (`^0.11`)
- [x] `config/socifusion_ai.php`
- [x] Tables: identities, conversations, messages, approvals, action logs, settings
- [x] `SociFusionAgent` (Alex persona, tenant context, tools)
- [x] Tool base with `read|prepare|execute` + autonomy gate
- [x] First tools: campaign stats, attention queue, draft strategy plan (prepare)
- [x] Kill switch + action logging
- [x] Zernio webhook stub + phone link service
- [x] Minimal web Command Center (`/ai-employee`)

**Exit criteria:** Authenticated user can chat via web API; tools respect gates; unlinked WA is rejected.

## Phase 1 — Command Center (web + WhatsApp, same session)

Product brief: “Tell me what you want” + phone as control interface.

See [07-command-center.md](./07-command-center.md).

1. Shared Command Center conversation (user+org), channel-agnostic ← done
2. Plan cards with **Review & Launch** (web buttons + WA `LAUNCH {id}`) ← done
3. AI ICP Builder (`build_icp`) ← done (OpenAI + Launch saves to workspace)
4. Strategy / campaign plan tools (`propose_strategy`, `draft_campaign_plan`) ← done (OpenAI-enriched)
5. WhatsApp shortcuts: status, attention, launch/reject/review ← done
6. Web UI loads shared history + pending approvals ← done
7. Wire Launch → create real outreach draft campaign ← done (activate still manual in outreach UI)
8. Prospecting tools: `find_prospects`, `analyze_competitor_audience` ← done (read existing lists)
9. Real `get_campaign_stats` from outreach ← done
10. Wire Launch → auto-activate / sync leads when list attached ← done
11. Attention queue from Unified Inbox ← done
12. ACTIVATE / PAUSE / pause all shortcuts (web + WhatsApp) ← done
13. Pass list_hash/list_src from find_prospects into plan tools ← done
14. `get_sales_brief` snapshot ← done
15. Competitor harvest kickoff from agent (prepare + LAUNCH) ← done
16. FullEnrich / enrichment from agent (prepare + LAUNCH) ← done
17. `draft_reply` for Unified Inbox (prepare + LAUNCH sends) ← done
18. AI Inbox UI layer on web (attention cards with inline draft) ← done

Existing campaign builders stay for power users.

**Exit criteria:** Same goal works on web or WhatsApp; approve on either surface; one conversation thread.

## Phase 2 — Harden WhatsApp control (Zernio production)

1. Production Zernio send/receive + idempotency ← wired (Inbox API + HMAC + event dedupe)
2. Interactive Launch/Reject buttons on pending approvals ← wired
3. Wire Launch → real draft outreach campaign create ← done (Phase 1)
4. Read + Prepare solid; Execute only with clear approve UX ← done

**Still needed:** live credentials + Zernio dashboard webhook subscription test.

**Exit criteria:** Linked user runs day-to-day Command Center from WhatsApp without opening the dashboard.

## Phase 3 — AI Sales Execution

Product brief Phase 2 items 5–9.

1. Personalization tools (evidence-grounded copy) ← `draft_personalized_message`
2. Follow-up / sequence adjustment (prepare) ← `adjust_follow_up`
3. Conversation classification on Unified Inbox ← `classify_reply` + attention queue enrichment
4. AI Inbox summary + Attention Queue UI ← done (Command Center sidebar)
5. Next Best Action on lead detail ← `set_next_best_action` + inbox sidebar + Command Center attention cards

## Phase 4 — Telegram control (optional)

Same identity + orchestrator pattern as WhatsApp. Only after WA is stable.

## Phase 5 — Autonomous Sales Employee

Product brief Phase 3.

1. Campaign Optimizer ← `optimize_campaign` + outreach UI panel
2. Qualification agent tools ← `qualify_lead`
3. Meeting brief / post-call CRM update ← `get_meeting_brief`, `post_call_crm_update`
4. AI Sales Manager weekly brief ← `get_weekly_sales_brief`
5. Higher autopilot levels ← `pause_outreach_campaign` on default allowlist; set autonomy 3+ in settings

**Ops:** `php artisan ai:diagnose` — check OpenAI, Zernio, migrations.

## Explicit non-goals (until later)

- OpenClaw / parallel agent runtimes
- Zoo of 15 agent UIs
- Prospect messaging from Zernio number
- Full Autonomous mode as default
- Replacing Unipile for outreach
