# Command Center — one brain, every surface

This is the product insight from the original brief, implemented as architecture:

> WhatsApp and Telegram should not contain the business logic.  
> They should simply be interfaces into the SociFusion AI orchestration layer.

## One Command Center

```text
        Web  /ai-employee          WhatsApp (Zernio)         Telegram (later)
                 │                        │                        │
                 └────────────┬───────────┴────────────────────────┘
                              │
                    COMMAND CENTER SESSION
                    (one open conversation per user + org)
                              │
                       SociFusionAgent
                              │
                    tools → V2 services
```

**There is no separate “WhatsApp bot product.”**  
Alex on WhatsApp *is* the Command Center. The web page is the same session with a richer Review & Launch UI.

## Shared session rules

1. One open `ai_conversations` row per `user_id` + `organization_id` (title: Command Center).
2. Inbound channel (`web` / `whatsapp`) is stored on each **message** (`meta.channel`), not as separate brains.
3. Pending `ai_action_approvals` belong to that conversation — approve on web or reply `LAUNCH 12` / `APPROVE 12` on WhatsApp.
4. Same tools, same autonomy gates, same audit logs.

## Canonical UX loop (both surfaces)

User goal → Alex plan card → **Review** → **Launch** → status updates.

### Plan card fields

| Field | Example |
| --- | --- |
| Goal | Book 20 meetings with US SaaS founders |
| ICP | Marketing agencies, 5–50, US, Founder/CEO |
| Prospects (est.) | 500 |
| Channels | LinkedIn → Email |
| Follow-up | 21 days |
| Steps | Find → enrich → outreach → stop on reply → qualify → book |
| `approval_id` | 42 |

### WhatsApp text shape

```text
Got it. Here's the plan:
• Target: US marketing agencies (5–50)
• Decision maker: Founder/CEO
• Prospects: ~500
• Channels: LinkedIn + Email
• Follow-up: 21 days
• Goal: Book meetings

Reply:
LAUNCH 42 — start when ready
REVIEW 42 — see full steps
REJECT 42 — discard
```

### Web shape

Same plan object rendered as a card with **Review** / **Launch** / **Reject** buttons calling `/ai-employee/approvals/decide`. Launch creates a draft at `/outreach/{id}` and activates when the plan includes a prospect list.

**Attention sidebar** (web only): unread Unified Inbox threads with priority badges, **Draft reply** (stages `draft_reply` approval), and editable reply text before **Send**.

## Shortcut commands (WhatsApp + web chat)

| User says | Behavior |
| --- | --- |
| Goal in natural language | `propose_strategy` / `build_icp` / `draft_campaign_plan` |
| `status` / “how’s it going?” | `get_campaign_stats` |
| `brief` / “how am I doing?” | `get_sales_brief` |
| `attention` / “who needs me?” | `get_attention_queue` |
| `LAUNCH {id}` / `APPROVE {id}` | Approve plan → draft outreach campaign; auto-activate if lead list attached |
| `ACTIVATE {campaign_id}` | Start a draft outreach campaign (requires Unipile channel) |
| `pause all` / `PAUSE {id}` | Pause active outreach campaign(s) |
| `REJECT {id}` | Reject |
| `REVIEW {id}` | Re-print plan card |

## Dual WhatsApp (unchanged)

| | Control Command Center | Prospect outreach |
| --- | --- | --- |
| Provider | Zernio | Unipile |
| Number | SociFusion bot | User’s WA |
| Session | This Command Center | Unified Inbox |

## Build implication

Phase 1 (Command Center capabilities) and Phase 2 (WhatsApp) ship as **one surface** — WhatsApp is not a later rewrite; it is the same orchestrator with a text formatter.
