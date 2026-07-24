# Feature Parity Status (Old -> New V2)

Last updated: 2026-06-18

## Completed in V2

- Extension token auth and tenant context.
- Unipile account link + sync endpoints.
- Lead search and storage.
- Outreach queue (invite/start chat/message) with idempotency.
- Conversation and message listing.
- Webhook ingest pipeline (dedupe + async processing).
- Capability and organization guard middleware.
- OpenAPI baseline and DTO validation for core flows.
- Campaign management APIs (list/create/show/status/run).
- Auto-response CRUD APIs.
- Activity/mini-stats ingest + summary API.
- Calls/reminders/call-campaign APIs (`/calls/*`, `/reminders/*`, `/call-campaigns/*`).
- Content creator and inspiration baseline APIs.
- ESP config + lead export endpoints.
- Team administration baseline APIs (members/invites/accept/switch organization).
- ESP delivery baseline APIs (push leads, delivery list, callback feedback ingestion).

## In Progress / Partially Done

- Campaign runtime execution: deep parity now includes wait jitter, explicit tracking nodes, stop-if-no-reply/end-sequence behavior, tracking hooks, provider fallback execution attempts, and per-provider step diagnostics.
- Extension UI: side-panel now supports richer team policy editing (template quick-apply, member status controls, template preview, bulk template apply) and richer attribution rendering (first-touch/last-touch/linear model blocks).
- Webhook event mapping: long-tail coverage expanded to invitation expiry, chat unread/reopen, relation unfollowed, post shared, and account connected/error variants; any unknown event still lands in unmapped event activity logs.

## Remaining for Full Old-System Parity

- SN lead-specific import/list workflow baseline is now implemented in API + extension side panel.
- Deep content analytics parity now includes first-touch/last-touch/linear attribution models and visual blocks; next depth is experimentation/ML weighting and cross-session identity stitching.
- ESP provider-specific execution parity now includes adapter factory with provider-specific dispatch payload semantics and provider-aware signature extraction; next depth is live provider API transport and response normalization per vendor.
- Team administration UX now has invite/member/template/matrix/preview/bulk controls; remaining depth is advanced policy auditing, approvals, and role-diff history UX.
- Campaign node parity is close; remaining depth is final legacy one-off node aliases discovered during real production replay.

## Recommended Next Build Order

1. Advanced analytics views for content and campaign attribution.
2. ESP provider-specific push execution with delivery feedback loops.
3. Team administration UX parity (member management and permissions screens).
4. Webhook coverage expansion for non-message event families.
5. Hardening pass: retries, monitoring, and alerting around new parity modules.
