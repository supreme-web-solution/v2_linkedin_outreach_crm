# Dual-Run Rollout Runbook

This file implements todo `create-rollout-plan`.

## Rollout phases

1. **Shadow mode**: v2 reads and mirrors events; v1 remains source of truth.
2. **Dual-write mode**: v1 writes also mirrored into v2 canonical tables.
3. **Cohort read-switch**: selected accounts read from v2 for specific features.
4. **Cohort write-switch**: selected accounts execute actions through v2 only.
5. **General cutover**: v2 primary; v1 paths frozen and then retired.

## Feature flags

- `v2.crm.enabled`
- `v2.extension.enabled`
- `v2.unipile.primary`
- `v2.fallback.phantom.enabled`
- `v2.fallback.rapidapi.enabled`
- `v2.read.leads`
- `v2.read.conversations`
- `v2.read.campaigns`
- `v2.write.messaging`
- `v2.write.invites`

## Observability gates

- invite send success rate
- message send success rate
- acceptance tracking parity
- reminder delivery parity
- webhook ingestion lag and retry volume
- fallback invocation rate by operation

## Rollback policy

1. Any sustained KPI drop beyond threshold triggers cohort rollback.
2. Rollback is flag-based (disable `v2.write.*`, keep shadow ingest on).
3. Preserve event logs for replay after fix.

## Cutover checklist

1. 7-day stable cohort metrics.
2. No P1/P2 migration incidents open.
3. Fallback usage below agreed threshold.
4. Data parity checks green for leads, campaigns, conversations, messages.
5. Release deprecation notice for v1 endpoints and extension package.
