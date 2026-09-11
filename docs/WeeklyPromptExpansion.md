# Weekly Prompt Expansion

Generated: 2026-09-11 18:29:42
Window: last 7 day(s)
Source: `ai_action_logs` where `tool = prompt_observer` and status indicates unknown/ambiguous/fallback.

## Candidate prompts to add into regression matrix

| # | Prompt | Count | Last seen | Statuses |
|---:|---|---:|---|---|
| 1 | hello what can you do | 1 | 2026-09-11T18:29:24+00:00 | unknown_pre_llm |

## Suggested weekly actions

1. Add top 10 prompts into `docs/PromptIntentCoverage.md`.
2. Add corresponding assertions into `tests/Feature/V2/Ai/PromptIntentCoverageMatrixTest.php`.
3. Run `php vendor/bin/phpunit tests/Feature/V2/Ai/PromptIntentCoverageMatrixTest.php`.
