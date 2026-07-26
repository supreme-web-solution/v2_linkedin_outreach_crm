# V2 Feature Status Checklist

**Last updated:** 2026-07-26  
**Scope:** Current v2 CRM + extension only. **No legacy v1 parity work.**

This is the **single source of truth** for what exists, how solid it is, and what’s left to validate or finish. Older docs (`10`, `11`, `13`, etc.) are archived planning notes — follow **this file** instead.

---

## How to read this doc

| Status | Meaning |
|--------|---------|
| **Solid** | Built, tested or QA’d, safe to ship with integrations connected |
| **Working** | Core flow works; needs staging run-through on real accounts |
| **Partial** | Usable but known gaps — listed below |
| **Todo** | Not built yet (only if we still want it in v2) |

**Routes:** CRM pages live under `/dashboard`, `/leads`, `/campaigns`, `/outreach`, `/calls`, `/conversations`, `/inbox`, etc.

**Tests:** Run `php artisan test` — key suites listed per section.

---

## What A / B / C meant (for context)

Those were **focus options**, not three products:

| Option | Focus |
|--------|--------|
| **A** | Double down on **Multi-channel Outreach** (conditions, analytics, staging) |
| **B** | Double down on **Calls + Inbox AI** (batch toggles, generic inbox reply) |
| **C** | **Extension + legacy parity** — **out of scope** for you |

**Your direction:** Stick with what you have; make it **solid**. The “Remaining work” section below is ordered for that — validation and finishing partial items, not rebuilding old v1.

---

## 1. Platform & auth

| Feature | Status | Notes |
|---------|--------|-------|
| Login / register / 2FA | Solid | `tests/Feature/Auth/*` |
| Org + team + invites | Working | Team accept flow; audit UX thin |
| Billing / entitlement gate | Working | `entitlement:FE` on CRM routes |
| Dashboard | Working | Total contacts = LinkedIn + imported CSV; inbox threads vs pipeline counts |
| Settings (profile, security) | Solid | |

**Remaining**
- [ ] Manual QA: new user → extension connect → org created
- [ ] Team invite end-to-end on staging

---

## 2. Integrations

| Feature | Status | Notes |
|---------|--------|-------|
| LinkedIn via Unipile (hosted) | Working | `/integrations` |
| Email / WhatsApp / IG / Telegram / X connect | Working | Per-channel cards |
| Google / Outlook calendar | Working | Call booking links |
| Account disconnect webhooks | Working | Pauses outreach campaigns |
| Daily action limits (Unipile) | Working | Surfaced on `/analytics`; alerts via `OpsAlertService` |

**Remaining**
- [ ] Staging: connect each channel you plan to sell
- [ ] Set `OPS_SLACK_WEBHOOK_URL` in production for alerts

---

## 3. Leads

| Feature | Status | Notes |
|---------|--------|-------|
| LinkedIn lists (Audience + SN) | Solid | Extension sync |
| Imported CSV / Excel / ODS | Solid | `/leads` → Imported tab |
| List CRUD, export, email fetch | Working | Enrichment limits apply |
| Dashboard + leads total count | Solid | Includes imported contacts |

**Tests:** `LeadsWebRoutesTest`, `DashboardTest`

**Remaining**
- [ ] Import a real CSV → use in outreach → verify lead rows
- [ ] Mobile table scroll UX (minor polish)

---

## 4. LinkedIn campaigns (extension-driven)

| Feature | Status | Notes |
|---------|--------|-------|
| Campaign builder (CRM) | Solid | `/campaigns/create` |
| Extension execution | Working | `v2-extension` campaign runner |
| Activity log + status | Working | `CampaignActivityTest` |
| Conditions (invite accepted) | Working | Extension-side boolean branch |

**Remaining**
- [ ] Full run: create → attach list → launch from extension → see activity in CRM
- [ ] Extension **sequence runner UI** in panel — partial (Phase 2 in extension doc)

**Not in scope:** Matching every old v1 campaign node alias.

---

## 5. Multi-channel outreach (`/outreach`)

| Feature | Status | Notes |
|---------|--------|-------|
| Template picker + builder | Solid | 8 presets + saved templates |
| Flow canvas (LinkedIn, email, social) | Solid | `OutreachFlowCanvas.vue` |
| Import lists + readiness prep | Solid | `OutreachLeadReadinessPanel` |
| Launch / pause / duplicate | Solid | |
| Save / duplicate / delete templates | Solid | Org-scoped, not public marketplace |
| Channel executors (6 channels) | Working | Baseline; **needs staging per channel** |
| Step conditions | Working | invite accepted, replied, no reply, email opened/bounced; configurable timeout |
| Webhook progression | Working | Invite, inbox reply, email tracking, disconnect pause |
| Per-campaign stats + step funnel | Working | On detail page, not separate analytics route |
| AI auto-reply per campaign/channel | Working | Switch on detail + inbox; requires `OPENAI_API_KEY` |
| Pause on reply | Working | Per campaign per channel |

**Tests:** `OutreachProgressTest`, `OutreachEnrichmentTest`, `UnifiedInboxTest`

**Remaining (to call outreach “solid”)**
- [ ] **Staging:** Run one live campaign per channel (LinkedIn, email, WhatsApp, IG, Telegram, X)
- [ ] **Staging:** Branch test — invite accepted → yes/no paths
- [ ] **Staging:** Inbound reply → pause + optional AI auto-reply
- [ ] Verify disconnect pauses campaign and shows reason on list/detail
- [ ] Optional: dedicated `/outreach/analytics` page (org rollup, charts) — **not required for MVP**

**Explicitly not built (don’t expect these yet)**
- Public template marketplace
- Advanced conditions (`invite_pending`, `message_read`, `email_clicked`, …)
- Draft-before-send for outreach AI (Calls has suggest flow; outreach sends immediately)

---

## 6. Call Manager (`/calls`)

| Feature | Status | Notes |
|---------|--------|-------|
| Launch from leads / lists | Solid | Wizard on `Calls/Index.vue` |
| Booking links + public book page | Solid | `CallBookingTest` |
| Calendar sync (Google/Outlook) | Working | `CalendarTest` |
| AI opening message + analyze | Working | Requires OpenAI |
| Per-chat AI auto-send toggle | Solid | `Calls/Show.vue` |
| **Batch** AI auto-send toggle | Working | Conversations → Flow page + Call Manager launch wizard |
| Pipeline stages (engaged / scheduling / booked) | Solid | |
| Daily pacing limits | Working | `CallLaunchPacingTest` |

**Tests:** `CallBookingTest`, `CallLaunchPacingTest`, `FlowAutoSendTest`

**Remaining**
- [ ] Staging: launch batch → book call via link → calendar webhook

---

## 7. Conversations (call flows)

| Feature | Status | Notes |
|---------|--------|-------|
| Flow list + flow detail | Solid | |
| Launch all chats in flow | Working | Requires Unipile LinkedIn |
| Delete flow / prospect | Working | |
| Mobile card layout on flow detail | Working | |

**Tests:** `ConversationsTest`, `FlowAutoSendTest`

**Remaining**
- [ ] Staging: launch flow → converse → stage moves to booked

---

## 8. Unified Inbox (`/inbox`)

| Feature | Status | Notes |
|---------|--------|-------|
| Platform hub + thread list | Solid | 6 platforms |
| Send / receive / attachments | Working | Unipile-backed |
| Outreach thread tagging | Working | `meta.outreach_campaign_id` |
| Campaign channel AI settings | Working | Per campaign on outreach detail + inbox sidebar |
| Mobile master-detail layout | Working | List ↔ chat on small screens at `/inbox/{platform}` |
| Global auto-responses | Working | Org-level rules |

**Tests:** `UnifiedInboxTest`

**Remaining**
- [ ] Staging: reply on each connected platform

---

## 9. Content, analytics, calendar

| Feature | Status | Notes |
|---------|--------|-------|
| Content creator / inspiration | Working | AI + Cloudinary |
| Analytics page | Working | Extension stats + Unipile quotas; `AnalyticsTest` |
| Calendar view | Working | `CalendarTest` |
| AI message library (`/ai-messages`) | Working | Separate from outreach templates |

**Remaining**
- [ ] Content attribution advanced weighting — basic only, **not a blocker**
- [ ] Analytics charts polish in extension panel

---

## 10. Chrome extension

| Feature | Status | Notes |
|---------|--------|-------|
| Auth + CRM API client | Solid | |
| Lead lists, search, bulk actions | Working | |
| Campaign hub (LinkedIn campaigns) | Working | |
| Sidebar tools (withdraw, greetings, etc.) | Working | |

**Remaining**
- [ ] Campaign **visual sequence runner** in panel — partial
- [ ] Extension analytics blocks — functional, not polished

**Not in scope:** Extension driving multi-channel outreach (CRM/Unipile only).

---

## 11. Ops & production

| Feature | Status | Notes |
|---------|--------|-------|
| Horizon queue dashboard | Working | Platform admins only |
| `queue:recover` scheduled | Working | Every 5 min |
| Ops alerts (Slack optional) | Working | `OpsAlertService` |
| Deploy checklist | Solid | [14-production-deploy-checklist.md](./14-production-deploy-checklist.md) |
| Rollout / feature flags doc | Reference | [08-dual-run-rollout-runbook.md](./08-dual-run-rollout-runbook.md) |

**Remaining**
- [ ] Production: Redis queue + Horizon supervisors
- [ ] Configure Slack webhook + trigger test alert
- [ ] Run deploy checklist once on staging

---

## Priority order — “make it solid” (recommended)

Do these in order. Each is **validation or finishing partial**, not new product lanes.

### Week 1 — Prove core loops
1. [ ] Connect LinkedIn + one other channel on staging
2. [ ] Leads: import CSV → appears in outreach builder
3. [ ] Outreach: launch small campaign (5 leads) → watch activity + funnel
4. [ ] Calls: launch batch → one booking via public link
5. [ ] Inbox: send/reply on LinkedIn thread

### Week 2 — Edge cases
6. [ ] Outreach condition: invite accepted branch
7. [ ] Outreach: inbound reply → pause on reply
8. [ ] Disconnect LinkedIn → campaign pauses
9. [ ] Flow batch auto-send off → verify no auto replies
10. [ ] Run full test suite in CI / locally: `php artisan test`

### Week 3 — Production readiness
11. [ ] Complete [14-production-deploy-checklist.md](./14-production-deploy-checklist.md)
12. [ ] Horizon + workers running
13. [ ] Ops Slack webhook tested
14. [ ] Fix any issues found in weeks 1–2

---

## Test suite quick reference

```bash
cd v2
php artisan test                                    # full suite
php artisan test tests/Feature/Web/OutreachProgressTest.php
php artisan test tests/Feature/Web/UnifiedInboxTest.php
php artisan test tests/Feature/Web/CallBookingTest.php
php artisan test tests/Feature/DashboardTest.php
```

---

## Out of scope (do not track)

- Legacy v1 feature parity (`11-old-vs-v2-feature-coverage.md`)
- Public outreach template marketplace
- ESP live push per vendor (config exists; not required for current CRM)
- Rebuilding old campaign node aliases
- Extension executing multi-channel outreach steps

---

## Doc index

| Doc | Use |
|-----|-----|
| **This file (`00-feature-status-checklist.md`)** | **Follow this** |
| [14-production-deploy-checklist.md](./14-production-deploy-checklist.md) | Deploy / ops |
| [09-api-quickstart.md](./09-api-quickstart.md) | Extension API dev |
| [13-multichannel-outreach-feature-plan.md](./13-multichannel-outreach-feature-plan.md) | Architecture reference only (phases outdated) |
| [10-feature-parity-status.md](./10-feature-parity-status.md) | Archived — legacy parity |
| [11-old-vs-v2-feature-coverage.md](./11-old-vs-v2-feature-coverage.md) | Archived — legacy comparison |

**When in doubt:** code + tests + this checklist beat any older checkbox list.
