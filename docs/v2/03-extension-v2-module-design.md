# Extension V2 Module Design

This file implements todo `design-extension-v2` in the new structure.

## New extension folder

- `v2-extension/` is the new standalone extension workspace.

## Module split

| Module | Purpose |
| --- | --- |
| `src/content/index.js` | lightweight page bootstrap and context capture |
| `src/ui/shell.js` | minimal UI trigger surface on LinkedIn |
| `src/service-worker/index.js` | runtime event routing + alarms |
| `src/service-worker/crmClient.js` | typed transport to CRM `/api/v2/*` |
| `src/service-worker/orchestrator/campaignRunner.js` | campaign runtime orchestration |
| `src/service-worker/workers/callWorker.js` | reminder/call polling workflows |

## Rules

1. No direct provider APIs in extension v2.
2. Provider logic is server-owned in CRM v2.
3. Campaign/call state is synced via typed CRM client only.
