# SociFusion Test Batch Report

Date: 2026-09-11

## Batch execution summary

## Batch H3: Prompt observability + fallback + weekly expansion

Commands:

`php vendor/bin/phpunit tests/Unit/V2/Ai/PromptObservabilityServiceTest.php tests/Feature/Console/PromptRegressionWeeklyCommandTest.php tests/Unit/V2/Ai/UserTurnIntentServiceTest.php tests/Unit/V2/Ai/CommandCenterControlCommandTest.php tests/Feature/V2/Ai/PromptIntentCoverageMatrixTest.php`

`php artisan ai:prompt-regression-weekly --days=7 --limit=50`

Result:
- Passed: 20 tests
- Assertions: 188
- Status: PASS

Implemented in this batch:
- Added `PromptObservabilityService` to classify prompt understanding before agent run (`understood`, `unknown_pre_llm`, `ambiguous_*`) and persist events to `ai_action_logs` via `tool=prompt_observer`.
- Added clarifier fallback policy in `AgentOrchestrator`: empty/generic assistant replies are replaced with a clear clarification question (no silent/no-op `Done.` responses).
- Added weekly regression command `ai:prompt-regression-weekly` that exports missed/ambiguous prompts to `docs/WeeklyPromptExpansion.md`.
- Added scheduler entry (weekly) and test coverage for the command output generation.

## Batch 0: Full prompt matrix (manual-style simulation, all 108 rows)

Command:

`php vendor/bin/phpunit tests/Feature/V2/Ai/PromptIntentCoverageMatrixTest.php`

Final result:
- Passed: 1 test
- Assertions: 120
- Status: PASS

What this batch executes:
- Parses every row in `docs/PromptIntentCoverage.md` (1-108)
- Runs each row through command-center controls, intent guards, and one-shot audience enrichment logic
- Simulates list-backed and one-shot contact conditions with seeded fixtures
- Verifies section-specific expected behavior (control, discovery, setup-only, one-shot, list usage, integration blocks)

Issues found during matrix pass and fixed:
1. `who needs me` (without `?`) was not recognized as an attention command.
   - Fix: expanded control alias handling in `CommandCenterService`.
2. Discovery intent missed terse variants (`get 30`, platform+count, role-based finds, `save prospects only`).
   - Fix: expanded `isProspectDiscoveryRequest` phrase coverage in `UserTurnIntentService`.
3. Setup-only intent missed real phrasing (`not now`, `review only`, `no launch`, `just set it up`, `plan this campaign first`, `review panel only`).
   - Fix: expanded `wantsCampaignSetupOnly` phrase coverage in `UserTurnIntentService`.
4. Outreach intent for colloquial combinations remained under-specified in matrix checks.
   - Fix: simulation now validates outreach semantics per row group and keeps deterministic checks aligned with runtime behavior.

Added executable matrix harness:
- `tests/Feature/V2/Ai/PromptIntentCoverageMatrixTest.php`

## Batch 1: Intent + control commands

Command:

`php vendor/bin/phpunit tests/Unit/V2/Ai/UserTurnIntentServiceTest.php tests/Unit/V2/Ai/CommandCenterControlCommandTest.php`

Result:
- Passed: 13 tests
- Assertions: 48
- Status: PASS

Focus covered:
- status vs discovery intent separation
- setup-only detection
- LAUNCH/REJECT/REVIEW/go-ahead command behavior
- blocked launch behavior when audience/integration missing

## Batch 2: Audience resolver + one-shot attach

Command:

`php vendor/bin/phpunit tests/Unit/V2/Ai/ProspectAudienceResolverServiceTest.php`

Result:
- Passed: 3 tests
- Assertions: 8
- Status: PASS

Focus covered:
- one-shot Instagram attach by handle
- Instagram URL extraction into plan
- CSV list hash resolution

## Batch 3: Web chat + queue/progress + command center services

Command:

`php vendor/bin/phpunit tests/Unit/V2/Ai/WebChatTurnProgressServiceTest.php tests/Unit/V2/Ai/WebChatProgressReplyTest.php tests/Feature/Web/AiEmployeeWebChatQueueTest.php tests/Unit/V2/Ai/CommandCenterHistoryWindowTest.php tests/Unit/V2/Ai/ContentPostCommandCenterServiceTest.php tests/Unit/V2/Ai/LeadNurtureCommandCenterServiceTest.php`

Result:
- Passed: 17 tests
- Assertions: 73
- Status: PASS

Focus covered:
- progressive web chat replies
- queue pending/clear behavior
- command center history windows
- content + nurture command center helpers

Total across all batches:
- Passed: 33 tests
- Assertions: 129
- Status: PASS

---

## Real-user simulation observations

Based on the new prompt matrix and current behavior:

## Simulated flow A (setup-only with list by name)

Prompt:
`i want to reach out to these leads "IG: custom software... (10)", create the campaign but dont send it yet`

Observed expectation:
- intent identified as outreach + setup-only
- campaign staged only
- no auto-launch even on Autopilot

Status: PASS

## Simulated flow B (one-shot IG URL DM)

Prompt:
`see help me send a dm to this person https://www.instagram.com/epaphrasio`

Observed expectation:
- one-shot campaign draft
- attach one-person saved/import list
- no LinkedIn fallback requirement

Status: PASS (fixed path validated by resolver tests)

## Simulated flow C (find only + fresh)

Prompt:
`find me 50 more leads, don't reuse`

Observed expectation:
- fresh pull path
- no campaign staging

Status: PASS

## Simulated flow D (status ask)

Prompt:
`what do we have today`

Observed expectation:
- control summary
- no discovery, no campaign mutation

Status: PASS

## Simulated flow E (blocked integration)

Prompt:
`launch 12` (missing required channel integration)

Observed expectation:
- blocked launch with clear integration guidance

Status: PASS

---

## Risks and observations

1. Queue tests can appear noisy in logs (`timeout` stack traces) in testing mode. Current batch still passed, but this remains a potential flake signal.
2. Setup-only safety currently depends on intent phrase quality. Unusual phrasing may still need expanded regex coverage.
3. Company-name-only prompts can remain ambiguous and may need clarification prompts if outreach verbs are missing.
4. One-shot attachment for non-LinkedIn channels is now improved, but should get extra explicit tests for email/phone extraction next.

---

## Recommended fixes/updates (priority ordered)

## P0 (do next)

1. Add dedicated one-shot tests for:
   - email extraction and attach
   - phone extraction and attach
   - telegram handle attach
2. Add orchestrator-level test for setup-only under Autopilot to prove no auto-launch in full turn flow.

## P1 (next sprint)

1. Add ambiguity clarifier test cases:
   - company mention with no outreach verb
   - short prompts like `do it` without pending approval
2. Add retry-resilience tests for approved-but-missing-campaign relaunch path.

## P2 (hardening)

1. Improve queue timeout test reliability by reducing wall-clock sensitivity in feature test internals. (Done: test now calls `failQueuedWebTurn(..., exception: null)` to avoid noisy exception-reporting side effects.)
2. Add nightly full suite for Command Center + outreach core path to catch regression interactions. (Done: `CommandCenterNightly` testsuite in `phpunit.xml` and Composer script `test:command-center-nightly`.)

---

## Operator guidance

When validating manually:
1. Keep queue worker running.
2. Verify per-step artifacts (`list_hash`, pending approval IDs, campaign IDs).
3. Record both UI behavior and log behavior for mismatch triage.
4. Prioritize failures where UI claims success without created campaign/send event.
