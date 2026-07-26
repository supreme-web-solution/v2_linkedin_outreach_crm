# Production deploy checklist

Use this alongside [08-dual-run-rollout-runbook.md](./08-dual-run-rollout-runbook.md). The rollout runbook covers migration strategy and feature flags; this checklist covers a single production release.

> **Note:** Feature-plan checklists in `10-feature-parity-status.md` and `13-multichannel-outreach-feature-plan.md` are planning artifacts (last updated June–July 2026). Verify behavior in code and tests before treating any checkbox as shipped.

## Pre-deploy

- [ ] All migrations reviewed (`php artisan migrate:status`)
- [ ] `.env` production values set (see below)
- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] Database: MySQL/PostgreSQL (not SQLite)
- [ ] Queue: Redis (`QUEUE_CONNECTION=redis`)
- [ ] Cache/session: Redis recommended
- [ ] Horizon configured and platform admin emails set (`billing.platform_admin_emails`)
- [ ] Unipile webhook URL registered and secret verified
- [ ] `OPENAI_API_KEY` set if AI features required
- [ ] `OPS_SLACK_WEBHOOK_URL` set for ops alerts (optional but recommended)

## Deploy steps

1. Enable maintenance mode if needed
2. Pull release tag / merge to production branch
3. `composer install --no-dev --optimize-autoloader`
4. `npm ci && npm run build`
5. `php artisan migrate --force`
6. `php artisan config:cache && php artisan route:cache && php artisan view:cache`
7. Restart PHP-FPM / app servers
8. Restart Horizon: `php artisan horizon:terminate` (supervisor will respawn)
9. Confirm scheduler cron: `* * * * * php artisan schedule:run`

## Post-deploy smoke tests

- [ ] Login / org context loads
- [ ] Dashboard stats load (`/dashboard`)
- [ ] Extension API health (`/api/v2/health` if exposed)
- [ ] Horizon dashboard reachable by platform admin (`/horizon`)
- [ ] Send test webhook (Unipile) — check `v2_provider_events` processed
- [ ] Launch a draft outreach or call flow in staging cohort first

## Workers & queues

Expected queues (verify Horizon supervisors):

| Queue | Purpose |
|-------|---------|
| `default` | General jobs |
| `webhooks` | Unipile webhook processing |
| `outreach` | Multi-channel outreach steps |
| `calls` | Call launch / messaging |

Scheduled tasks (`routes/console.php`):

- `queue:recover --release-stale` — every 5 min
- `horizon:snapshot` — every 5 min
- `calls:dispatch-due` — every minute

## Observability

- **Horizon** — failed jobs, wait times, throughput
- **Logs** — `[ops.alert]` prefix for OpsAlertService events
- **Slack** — `OPS_SLACK_WEBHOOK_URL` receives queue health, daily limit hits, webhook failures
- **Analytics page** — Unipile daily usage quotas

Alert thresholds (`.env`):

```
OPS_SLACK_WEBHOOK_URL=https://hooks.slack.com/...
OPS_ALERT_DAILY_LIMITS=true
OPS_ALERT_QUEUE_HEALTH=true
OPS_ALERT_FAILED_JOBS_THRESHOLD=10
```

## Rollback

1. Revert deploy artifact / previous release tag
2. Roll back migrations only if the release migration is reversible and safe
3. `php artisan horizon:terminate`
4. Toggle feature flags off per rollout runbook (`v2.crm.enabled`, etc.)
5. Investigate failed jobs in Horizon before mass `queue:retry`

## Hardening pass (recommended before full cutover)

- [ ] Rate limits reviewed for public routes
- [ ] Platform admin list audited
- [ ] Failed job alerting verified (trigger test failure in staging)
- [ ] Database backups automated
- [ ] Redis persistence / eviction policy documented
- [ ] CORS and extension origin allowlist verified
