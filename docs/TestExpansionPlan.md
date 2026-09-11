# Automated Test Expansion Plan

This file defines concrete automated coverage additions for intent boundaries and launch guards.

## Added in this pass

1. New resolver tests in `tests/Unit/V2/Ai/ProspectAudienceResolverServiceTest.php`:
   - attaches existing Instagram import list for one-shot handle
   - extracts Instagram URL/handle from goal text
   - resolves csv import list by hash

2. Updated command-center control test expectation:
   - ICP relaunch now asserts stable confirmation response instead of brittle `already launched` wording

## Next test increments

## Intent boundary tests

- add explicit tests for mixed prompts:
  - discovery + setup-only in one sentence
  - status words coexisting with outreach words
  - company mention + no outreach verb should remain discovery-only

## Launch guard tests

- add feature tests for:
  - setup-only in Autopilot from full orchestrator path
  - missing integration blockers per channel (IG/Email/WA/TG)
  - retry path when approval is `approved` but campaign ID missing

## One-shot attach tests

- add coverage for:
  - one-shot email extraction and attach
  - one-shot phone extraction and attach
  - one-shot telegram handle attach
  - one-shot with explicit list hash must win over fuzzy list match

## Regression tests for stale reuse behavior

- verify `prefer_fresh` overrides cached LinkedIn and Instagram recent lists
- verify `more 50` and `don't reuse` patterns set fresh behavior

## Suggested CI grouping

- `tests/Unit/V2/Ai/UserTurnIntentServiceTest.php`
- `tests/Unit/V2/Ai/ProspectAudienceResolverServiceTest.php`
- `tests/Unit/V2/Ai/CommandCenterControlCommandTest.php`
- targeted feature tests for command-center web route approvals
