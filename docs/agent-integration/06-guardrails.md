# Guardrails

Ship with Phase 0/1. Product rule: **AI must not become a spam machine.**

## Hard rules

1. **Kill switch** — `ai_employee_settings.kill_switch` or `SOCIFUSION_AI_KILL_SWITCH` stops all tool side effects and agent prompts (optional: still allow read-only “AI disabled” reply).
2. **Entitlements** — AI Command Center requires existing org entitlements / capabilities (extend `EnsureV2Capability` when UI ships).
3. **Tenant isolation** — Tools receive org/user from authenticated context or linked identity, never from free-form model args for authz.
4. **Reply stops outreach** — Keep / reinforce existing Unipile inbox behavior: prospect reply pauses automated outreach unless a conversation workflow explicitly continues.
5. **Opt-out / DND** — Execute tools must respect opt-out flags before any send.
6. **Channel rate limits** — Continue Unipile daily limits; never bypass via agent tools.
7. **Duplicate prevention** — Prefer existing campaign/lead uniqueness checks.
8. **Approval for consequential actions** — External sends, launches, targeting changes need Prepare → Approve → Execute unless Autopilot allowlist says otherwise.
9. **Audit trail** — Every tool call → `ai_action_logs`; approvals → `ai_action_approvals`.
10. **Confidence / human judgment** — Low-confidence reply classification → Attention Queue, not auto-send.

## Autonomy defaults

| Environment | Default autonomy |
| --- | --- |
| Fresh org | Assisted (2) |
| Production launch | Assisted (2) |
| Autonomous (4) | Feature-flagged; admin-only enable |

## WhatsApp-specific

- Unlinked numbers cannot run tools.
- Control bot number never used for prospect outreach.
- Execute confirmations require explicit APPROVE / button, not fuzzy “ok do it” unless autonomy ≥ Autopilot and tool allowlisted.

## Undo / review

- Prefer reversible prepare drafts (campaign draft not launched).
- Launched campaigns: pause is the primary “undo”.
- Log enough payload to reconstruct what ran.

## Checklist before enabling Autopilot execute tools

- [ ] Allowlist reviewed per org
- [ ] Opt-out path tested
- [ ] Reply-pause tested on LinkedIn + email + WA (Unipile)
- [ ] Kill switch tested
- [ ] Action log visible in admin/settings
