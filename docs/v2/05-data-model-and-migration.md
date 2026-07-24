# Canonical V2 Schema and Migration Strategy

This file implements todo `define-data-migration`.

## Canonical tables

- `v2_integration_accounts`
- `v2_leads`
- `v2_lead_sources`
- `v2_conversations`
- `v2_messages`
- `v2_campaign_runs`
- `v2_campaign_steps`
- `v2_provider_events`

## Migration strategy

1. Keep v1 data stores untouched.
2. Backfill v2 tables using deterministic mapping jobs.
3. Enable dual-write on critical workflows (lead creation, campaign updates, messages).
4. Enable read-switch by feature flag and account cohort.
5. Retire v1 writes only after parity metrics stay green.

## Backfill jobs (to implement next)

- `BackfillV2LeadsJob`
- `BackfillV2ConversationsJob`
- `BackfillV2CampaignRunsJob`
- `BackfillV2ProviderEventsJob`
