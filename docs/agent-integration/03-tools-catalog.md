# Tools catalog

Tools are thin adapters. They call existing SociFusion services; they do not reimplement Unipile, campaigns, or CRM.

## Permission + autonomy

| Permission | Copilot (1) | Assisted (2) | Autopilot (3) | Autonomous (4) |
| --- | --- | --- | --- | --- |
| `read` | run | run | run | run |
| `prepare` | return plan only* | create pending approval | create pending approval | may auto-prepare |
| `execute` | blocked | needs approve | allowlisted auto | within rules |

\*Copilot still returns a human-readable plan; no side effects.

## Phase 0–1 tools

| Tool | Permission | Wraps (examples) | Status |
| --- | --- | --- | --- |
| `get_campaign_stats` | read | `OutreachCampaignStatsService` | Wired |
| `get_attention_queue` | read | `AttentionQueueService` + inbox_brief stats | Wired |
| `get_sales_brief` | read | `DashboardStatsService` + outreach stats | Wired |
| `build_icp` | prepare | ICP from offer / competitors | Scaffold |
| `draft_campaign_plan` | prepare | Full campaign plan card (accepts list_hash/list_src) | Wired |
| `propose_strategy` | prepare | Goal → execution plan (accepts list_hash/list_src) | Wired |
| `find_prospects` | read | `LeadListService` lists | Wired |
| `discover_prospects` | read | Lists + competitor audiences + `ready_for_campaign` | Wired |
| `analyze_competitor_audience` | read | Audience / engagers | Wired |
| `prepare_competitor_harvest` | prepare | `CompetitorEngagerHarvestService` via job | Wired |
| `prepare_enrichment` | prepare | FullEnrich + Unipile enrichment jobs | Wired |
| `draft_reply` | prepare | `UnifiedInboxReplyService` draft + send on Launch | Wired |
| `book_meeting` | prepare | Call Manager booking link + inbox send on Launch | Wired |
| Launch (`LAUNCH id`) | execute | Draft + auto-activate when list attached | Wired |
| `activate_outreach_campaign` | execute | Start outreach run | Wired |
| `pause_outreach_campaign` | execute | Pause one or all | Wired |

## Phase 3 tools (AI Sales Execution)

| Tool | Permission | Wraps | Status |
| --- | --- | --- | --- |
| `classify_reply` | read | `InboxClassificationService` | Wired |
| `draft_personalized_message` | prepare | `OpenAIContentService` + lead evidence | Wired |
| `adjust_follow_up` | prepare | Campaign `node_model` delay adjust on Launch | Wired |
| `set_next_best_action` | prepare | `V2OutreachLead.meta.next_best_action` on Launch | Wired |

## Phase 5 tools (Autonomous Employee — early)

| Tool | Permission | Wraps | Status |
| --- | --- | --- | --- |
| `get_meeting_brief` | read | `MeetingBriefService` + Call Manager | Wired |
| `optimize_campaign` | prepare | `CampaignOptimizerService` → meta + auto `shorten_waits` on Launch when drop-off | Wired |
| `qualify_lead` | prepare | Lead `meta.qualification` on Launch | Wired |
| `post_call_crm_update` | prepare | Call + CRM `meta` on Launch | Wired |
| `get_weekly_sales_brief` | read | 7-day snapshot + upcoming calls | Wired |
| `let_ai_execute` | prepare | Pause struggling campaigns + inbox follow-ups in one Launch | Wired |

## Phase 3+ tools

| Tool | Permission | Notes |
| --- | --- | --- |
| `enrich_contact` | prepare | `LeadEnrichmentService`, FullEnrich |
| `draft_personalized_message` | prepare | Must cite evidence fields |
| `classify_reply` | read | Intent / stage / recommended action |
| `set_next_best_action` | prepare | Lead CRM meta |
| `move_lead_to_nurture` | execute | CRM stage |
| `book_meeting` | prepare | Call Manager / calendar — stages booking link; sends on Launch |
| `optimize_campaign` | prepare | Suggest channel mix / copy changes |
| `get_meeting_brief` | read | Pre-call packet |

## Implementation rules

1. Every tool extends `App\Ai\Tools\Concerns\GatedTool` (or base class) and declares `permission(): string`.
2. Log every invocation to `ai_action_logs` (success, deny, error).
3. `prepare` tools return JSON the UI/WA can render as Review cards; they create `ai_action_approvals` rows when side effects are staged.
4. `execute` tools require `approval_id` **or** autopilot allowlist match.
5. Pass `user_id` + `organization_id` into tools via constructor from the agent context — never trust model-supplied tenant ids for authorization.

## Mapping product “agents” → tools

| Named agent (product) | Tool group |
| --- | --- |
| Strategy | `propose_strategy`, `build_icp` |
| Prospecting | `find_prospects`, `analyze_competitor_audience` |
| Research & Enrichment | `enrich_contact`, briefs |
| Personalization | `draft_personalized_message` |
| Outreach | `draft_campaign`, `launch_campaign`, `pause_campaign` |
| Conversation | `classify_reply`, `draft_reply`, `get_attention_queue` |
| Sales Manager | `get_sales_brief`, `get_campaign_stats`, `optimize_campaign` |
| Meeting | `get_meeting_brief`, `book_meeting` |
