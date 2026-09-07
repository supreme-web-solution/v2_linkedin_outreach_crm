# SociFusion AI Sales Employee — Integration Docs

Integrate an **AI control plane** into SociFusion without rebuilding the product.

**Positioning:** Users tell SociFusion what they want. Specialized capabilities (strategy, prospecting, enrichment, outreach, conversation) run as **tools** behind one orchestrator. Existing campaigns, Unipile channels, CRM, inbox, and enrichment remain the execution layer.

| Doc | Purpose |
| --- | --- |
| [plan.md](./plan.md) | Executive summary & decisions |
| [01-architecture.md](./01-architecture.md) | Orchestrator, interfaces, dual WhatsApp |
| [02-phases.md](./02-phases.md) | Build order (Phase 0 → later) |
| [03-tools-catalog.md](./03-tools-catalog.md) | Tools mapped to existing V2 services |
| [04-whatsapp-zernio.md](./04-whatsapp-zernio.md) | Control-bot identity & Zernio |
| [05-data-model.md](./05-data-model.md) | Tables / models |
| [06-guardrails.md](./06-guardrails.md) | Autonomy, approvals, anti-spam |
| [07-command-center.md](./07-command-center.md) | One session: web + WhatsApp |

## Non-negotiables

1. **One brain** — `SociFusionAgent` (Laravel AI SDK). Not 15 UI agents.
2. **Tools → services → DB** — never Agent → SQL.
3. **Dual WhatsApp** — Zernio = user↔Alex control; Unipile = prospect outreach.
4. **Read / Prepare / Execute** gates + autopilot levels from day one.
5. **Web first**, then WhatsApp control, then Telegram / voice.

## Code location (Phase 0+)

```text
app/Ai/Agents/SociFusionAgent.php
app/Ai/Tools/*
app/V2/Ai/*
config/socifusion_ai.php
database/migrations/*_create_socifusion_ai_tables.php
```
