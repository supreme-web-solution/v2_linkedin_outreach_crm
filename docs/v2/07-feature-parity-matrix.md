# Feature Parity Matrix (v1 -> v2)

| v1 capability | v2 module | primary provider route | status target |
| --- | --- | --- | --- |
| Audience creation/search export | `LeadIngestion` | Unipile search/users | parity required |
| SN lead import/listing | `LeadIngestion` | Unipile search/users + account context | parity required |
| Campaign step execution | `CampaignRuntime` | Unipile invites/messages/actions | parity required |
| Messaging and follow-up | `ConversationAndMessaging` | Unipile chats/messages | parity required |
| Auto-response templates | `ConversationAndMessaging` | CRM-managed rules + Unipile send | parity required |
| Call manager/reminders | `CallAndReminder` | CRM orchestration + Unipile transport | parity required |
| Content post status tracking | `ContentAndAnalytics` | Unipile posts/events where available | parity required |
| Team/profile/settings | `IdentityAndAccess`, `TeamAndPermissions` | CRM native | parity required |
| Dashboard activity metrics | `ContentAndAnalytics` | CRM native + provider events | parity required |

## Exit criteria

- No critical v1 feature regressions in cohort tests.
- Message/invitation delivery KPIs within agreed thresholds.
- Fallback usage under target threshold and declining release-over-release.
