# Extension v2 — Old Sidebar Feature Parity

Architecture: **Extension UI → CRM `/api/v2/*` → Unipile**. No direct Unipile calls from the extension.

## Old sidebar → Status

| Old feature | Unipile / CRM capability | V2 extension today | Phase |
|-------------|--------------------------|-------------------|-------|
| **Audience Creation** | `POST /leads/search`, `POST /leads/sn/import`, CRM Phantom audiences | **Lists** tab ✓ | Done |
| **Campaign** | `GET/POST /campaigns/*` | **Campaigns** tab (list, run/pause, add leads) | Phase 2 — sequence runner UI |
| **Message All Connections** | `GET /outreach/relations` + `POST /outreach/start-chat` | **Actions** → Msg All Connects ✓ | Phase 1 done |
| **Message Targeted Users** | `GET /leads/sn/imported` + start-chat | **Actions** → Msg Targeted ✓ | Phase 1 done |
| **View Connections** | `POST /outreach/profile-action` `view_profile` | **Actions** → View Connects ✓ | Phase 1 done |
| **Follow Connections** | `POST /outreach/profile-action` `follow` | **Actions** → Follow Connects ✓ | Phase 1 done |
| **Congrats On Anniversary** | LinkedIn notifications (content script) + `start-chat` | **Actions** → Anniversary ✓ | Done |
| **Congrats On New Job** | Same | **Actions** → New Job ✓ | Done |
| **Withdraw Sent Invites** | `GET /outreach/invitations/sent` + cancel | **Actions** → Withdraw ✓ | Phase 1 done |
| **Accept Received Invites** | received invitations + accept | **Inbox → Requests** ✓ | Done |

## File layout (extension)

```
v2-extension/src/
  content/
    index.js
    snCollector.js          # SN page scrape → CRM import
  ui/
    core/
      le2-core.js           # tokens, batch runner, shared DOM helpers
    features/
      action-registry.js    # menu items (id, label, color, panel)
      panels/
        withdraw-invites.js
        bulk-message.js       # message all + targeted
        bulk-profile.js       # view + follow
        greetings.js          # anniversary / new job (phase 2)
    tabs/
      actions.js            # vertical menu + panel host
    shell.js                # bootstrap, auth, settings, tab bar
  service-worker/
    crmClient.js
    orchestrator/
      campaignRunner.js     # thin dispatcher
      handlers/
        outreach.js
        leads.js
        auth.js
```

## Rules

1. One panel = one file; no 2000-line shell.
2. Bulk jobs run in the **content script** with delay between items (extension shows progress).
3. Each item calls a **single CRM action** (queued on server via Unipile).
4. Message templates use `{{name}}`, `{{firstName}}`, `{{lastName}}` (maps old `@name` etc.).

## Auth

Keep v2 flow: email/password → Bearer token → optional `X-Organization-Id`. **No** `lk-id` header.
