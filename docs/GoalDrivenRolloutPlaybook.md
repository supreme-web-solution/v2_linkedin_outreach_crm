# Goal-Driven Rollout Playbook

## Dev
- Run migrations: `php artisan migrate`
- Run targeted suite:
  - `php vendor/bin/phpunit tests/Unit/V2/Ai/IntentGoalResolverServiceTest.php`
  - `php vendor/bin/phpunit tests/Unit/V2/Ai/ToolPolicyGateServiceTest.php`
  - `php vendor/bin/phpunit tests/Unit/V2/Ai/AiActivityLogServiceTest.php`
  - `php vendor/bin/phpunit tests/Unit/V2/Ai/PostExecutionVerifierServiceTest.php`
  - `php vendor/bin/phpunit tests/Unit/V2/Ai/LiveFindProspectDetailsRegressionTest.php`
- Manual checks:
  - `find prospect details` => no campaign creation, no send
  - `find 20 and reach out` => full chain allowed
  - `delete campaigns created today` => one bulk Confirm Delete
  - `what did you create today` => answer from `get_activity`

## Staging
- Replay incident conversation prompts from production logs/transcripts.
- Confirm verifier warnings are empty for expected paths.
- Confirm activity logs include tool, action, entity, and trigger text.
- Validate onboarding handoff metadata (`workspace_goal_profile`) exists and matches selected goal.

## Production
- Deploy during low-traffic window.
- Smoke test 4 prompts:
  - find-only
  - setup-only
  - outreach execute
  - delete-by-time-window
- Monitor:
  - `ai_activity_logs` volume and action mix
  - `ai_action_logs` denied counts for policy gate
  - verification warnings in assistant replies

## Rollback
- If behavior regresses, disable autonomous execution at settings level and revert deployment.
- Keep destructive actions approval-only (`Confirm Delete`) while triaging.
