# V1 Feature and Endpoint Inventory

This file implements todo `inventory-v1-contracts` in the new Laravel v2 project.

## Preserved v1 capabilities

| Capability | V1 ownership | v2 module target |
| --- | --- | --- |
| Audience and lead ingestion | `linkdominator app/app/Http/Controllers/ChromeApiController.php`, `LeadController.php` | `LeadIngestion` |
| Campaign orchestration and progression | `CampaignController.php` + extension `background.js` | `CampaignRuntime` |
| Conversation/call automation | `CallManagerController.php`, `CallCampaignController.php` | `ConversationAndCall` |
| Auto-responses and AI content helpers | `ChromeApiController.php`, `AiwriterController.php` | `ConversationAndMessaging` |
| Content scheduling/analytics | `ContentCreatorController.php` | `ContentAndAnalytics` |
| Team/profile/settings | `ProfileController.php`, `TeamController.php` | `IdentityAndTeam` |

## Current extension API baseline (must retain parity)

Source: `linkdominator app/routes/api.php`.

- Campaign endpoints (`/campaigns`, `/campaign/{id}/sequence`, leadgen update/status routes)
- Calls/reminders endpoints (`/calls/*`, `/reminders/*`, `/call-campaigns/*`)
- Audience and lead endpoints (`/audience*`, `/snleads/*`, `/leads/export*`)
- Auto-response endpoints (`/autoresponse/*`, `/autoresponses`)
- Utility/auth endpoints (`/accessCheck`, `/auth/sync-linkedin-id`, `/conf`, `/activites`)
- Content helpers (`/post/generate-comment`, `/content-creator/*`, `/inspiration/save-viral-post`)

## Dependency map

| Dependency | Existing v1 use | v2 policy |
| --- | --- | --- |
| Unipile | not yet used | primary provider |
| PhantomBuster | search export, post likers/comments, enrichment edges | transitional fallback only |
| RapidAPI | feed/discovery paths | transitional fallback only |
| LinkedIn Voyager direct from extension | invite/messaging/search/action scripts | deprecate and remove |

## Contract freeze checklist

1. Keep this endpoint list as parity test baseline.
2. Define typed v2 request/response DTOs for each route group.
3. Add side-by-side parity checks before v1 endpoint deprecation.
