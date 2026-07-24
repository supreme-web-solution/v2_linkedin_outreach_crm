# CRM V2 Modules and Provider Contracts

This file implements todo `design-v2-modules`.

## Bounded modules

| Module | Scope |
| --- | --- |
| `IdentityAndAccess` | extension authentication, user identity, session trust |
| `IntegrationAccounts` | provider account link/reconnect/status |
| `LeadIngestion` | lead search/import/enrichment normalization |
| `ConversationAndMessaging` | chats/messages/replies and auto-response rules |
| `CampaignRuntime` | campaign run state machine, step execution, retries |
| `CallAndReminder` | call-booking state and reminder queues |
| `ContentAndAnalytics` | post workflows and reporting |
| `TeamAndPermissions` | team membership and capability grants |
| `ProviderEvents` | webhook ingest, idempotency, event fanout |

## Provider interfaces

Contracts live under `app/V2/Contracts/Providers`:
- `AccountProviderInterface`
- `SearchProviderInterface`
- `InvitationProviderInterface`
- `MessagingProviderInterface`
- `ProfileProviderInterface`
- `PostProviderInterface`
- `WebhookProviderInterface`

All modules depend on these contracts, not provider SDK clients directly.

## Route isolation

- Existing APIs remain untouched in v1 system.
- In this new app, v2 APIs are served via `routes/api.php` and delegated to `routes/api_v2.php`.
- Route groups are module-aligned (`integration-accounts`, `leads`, `conversations`, `campaigns`, `calls`, `provider-events`).
