# Old vs V2 Feature Coverage (CRM + Extension)

Last updated: 2026-06-18

This file is a direct feature-by-feature checklist of:
- what existed in the old app/extension,
- what is implemented in v2,
- and what is still partial or not yet wired.

Status legend:
- `DONE` = implemented and wired in v2 flow
- `PARTIAL` = implemented baseline, but not full parity depth
- `NOT WIRED` = missing or not connected end-to-end yet

## CRM Feature Coverage

| Feature Area | Old CRM (v1) | V2 CRM Status | Notes |
| --- | --- | --- | --- |
| Auth + extension access | Session/auth sync + utility auth routes | `DONE` | Extension token issuance, bearer auth, tenant context enabled. |
| Organization + team membership | Team/profile management | `DONE` | Organizations, memberships, invites, accept, switch org, role/capability checks are present. |
| Capability permissions | Role-like controls in old flows | `DONE` | Capability middleware and templates/role matrix endpoints implemented. |
| Integration account connect/sync | LinkedIn account sync patterns | `DONE` | Unipile account link/sync endpoints and provider manager wiring implemented. |
| Lead search + ingestion | Audience/leads endpoints | `DONE` | Lead search, persistence, list, and SN source/import listing implemented. |
| SN import/list workflow | SN lead import/listing | `DONE` | API endpoints implemented and consumed by extension. |
| Outreach invite/chat/message | Mixed extension+CRM delivery | `DONE` | Queued outreach with idempotency and persistence trail in conversations/messages/provider events. |
| Conversation/message history | Call manager + messaging context | `DONE` | Conversation + message read APIs implemented. |
| Campaign CRUD + run trigger | Campaign definition/run | `DONE` | List/create/show/status/run endpoints implemented. |
| Campaign deep runtime parity | Legacy step variants/hooks | `PARTIAL` | Many variants implemented (wait jitter, tracking nodes/hooks, stop-if-no-reply, post/profile/invite actions), but final niche legacy aliases/behaviors may remain. |
| Auto-response rules | Auto-response module | `DONE` | CRUD endpoints implemented. |
| Calls + reminders + call campaign | Call manager/call campaign/reminders | `DONE` | Call orchestration endpoints and queue-related APIs implemented. |
| AI call reply/message assist | AI assistance in call flow | `DONE` | Analyze/process/generate call message endpoints implemented. |
| Content creator + inspiration | Content creator module | `DONE` | Content post and inspiration APIs implemented. |
| Content analytics summary | Stats/dashboard style summary | `DONE` | Summary + daily analytics implemented. |
| Content cohort analytics | Cohort reporting | `DONE` | Cohort endpoint implemented. |
| Content attribution models | Attribution metrics | `PARTIAL` | Channel + funnel + first-touch/last-touch/linear models present; advanced weighting/identity stitching still pending. |
| ESP config + push + feedback | Lead export/ESP callbacks | `PARTIAL` | Config, push, deliveries, feedback and signature verification exist; full live per-provider transport/normalization still pending. |
| Webhook pipeline | Event ingest/update | `PARTIAL` | Dedupe + async processor + broad event family mapping + unmapped fallback done; long-tail edge variants can still expand. |
| OpenAPI + DTO normalization | Contract consistency | `DONE` | OpenAPI baseline and DTO usage implemented for main v2 surfaces. |

## Extension Feature Coverage

| Feature Area | Old Extension (v1) | V2 Extension Status | Notes |
| --- | --- | --- | --- |
| Auth/connect context | Access/auth utility flows | `DONE` | Sign-in, token storage, API base config, org context configuration are wired. |
| CRM typed transport layer | Mixed direct/API calls | `DONE` | `crmClient` wraps v2 endpoints with tenant/idempotency handling. |
| Lead search/list + SN workflows | Audience + SN actions | `DONE` | Search/load/import/list SN flows wired in UI and orchestrator. |
| Outreach actions | Invite/chat/message actions | `DONE` | Invite/start chat/send message wired from UI to CRM APIs. |
| Campaign panel + run | Campaign runtime controls | `DONE` | Create/list/run campaign actions wired. |
| Auto-response panel | Auto-response flows | `DONE` | List/create/update/delete wired. |
| Conversation viewer | Message browsing | `DONE` | Conversation list + message list wired. |
| Calls/reminders/call queue | Call automation surfaces | `DONE` | Call create, ready queue, reminders, and AI call message tooling wired. |
| Content + inspiration panels | Content workflow UI | `DONE` | Create/list content and load inspiration wired. |
| Analytics panels | Dashboard/analytics views | `PARTIAL` | Functional visual blocks for summary/cohorts/attribution and model bars are present, but not a final polished chart-library UX yet. |
| ESP operations panel | ESP push/feedback ops | `DONE` | Push leads, load deliveries, and mark feedback statuses wired. |
| Team management panel | Team invite/member policies | `PARTIAL` | Invite/list/remove/update/status/template preview/bulk apply wired; advanced admin audit/history UX still pending. |
| AI comment generation for posts | `POST /posts/generate-comment` | **Feed post button** ✓ | Done |
| AI chat reply generation (non-call) | Legacy assistant-like messaging helpers | `NOT WIRED` | Call AI exists, but generic chat reply generation UI flow is not yet wired end-to-end. |
| Final UI polish | Mature production UX | `PARTIAL` | Workspace UI is functional and broad, but still needs final product polish and refinement. |

## High-Priority Not Yet Wired

1. AI comment generation flow (CRM endpoint + extension UI).
2. Generic AI chat reply generation beyond call orchestration.
3. Final campaign niche legacy node behavior parity.
4. ESP full live provider transport parity (vendor-by-vendor operational semantics).
5. Extension final UI polish and advanced admin audit UX.
