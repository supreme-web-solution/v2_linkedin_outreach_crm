# Data model — SociFusion AI

Tables are prefixed `ai_` and scoped by `organization_id` (+ `user_id` where personal).

## `ai_employee_settings`

Per org (optionally overridden per user later).

| Column | Notes |
| --- | --- |
| organization_id | FK |
| user_id | nullable — null = org default |
| enabled | bool |
| kill_switch | bool — hard stop all AI actions |
| autonomy_level | 1–4 |
| employee_name | default `Alex` |
| allowed_execute_tools | JSON allowlist for Autopilot |
| meta | JSON |

## `ai_channel_identities`

| Column | Notes |
| --- | --- |
| organization_id, user_id | Who is chatting |
| channel | `whatsapp`, `telegram`, … |
| external_id | E.164 phone or TG chat id |
| status | `active`, `revoked` |
| verified_at | |
| meta | JSON |

Unique: `(channel, external_id)` where status active.

## `ai_channel_link_codes`

| Column | Notes |
| --- | --- |
| organization_id, user_id | |
| channel | |
| code | e.g. `LINK-48291` |
| expires_at | |
| consumed_at | |

## `ai_conversations`

| Column | Notes |
| --- | --- |
| organization_id, user_id | |
| channel | `web`, `whatsapp`, `telegram` |
| channel_identity_id | nullable |
| title | optional |
| status | `open`, `archived` |
| laravel_ai_conversation_id | nullable link to SDK memory if used |
| meta | JSON (last plan, campaign refs) |

## `ai_messages`

| Column | Notes |
| --- | --- |
| conversation_id | |
| role | `user`, `assistant`, `system`, `tool` |
| content | text |
| provider_message_id | Zernio/WA id for idempotency |
| meta | tool calls, tokens, etc. |

## `ai_action_approvals`

| Column | Notes |
| --- | --- |
| organization_id, user_id | |
| conversation_id | nullable |
| tool | tool name |
| permission | `prepare` / `execute` |
| payload | JSON staged action |
| status | `pending`, `approved`, `rejected`, `expired`, `executed` |
| decided_at, decided_by | |
| result | JSON |

## `ai_action_logs`

| Column | Notes |
| --- | --- |
| organization_id, user_id | |
| conversation_id | nullable |
| tool | |
| permission | |
| status | `allowed`, `denied`, `success`, `error` |
| input, output | JSON |
| error | text |
| duration_ms | |

## Relationships to existing models

- `User`, `V2Organization` — tenancy
- Approvals may reference `v2_outreach_campaigns`, `v2_campaigns`, `v2_conversations` inside `payload` / `meta` — no hard FK required in Phase 0

## Migration

See `database/migrations/2026_09_06_150000_create_socifusion_ai_tables.php`.
