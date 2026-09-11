# E2E Scenario Checklist (Web + WhatsApp)

Use this checklist for full-flow QA of SociFusion behavior.

## Test environment prerequisites

- Queue worker running: `php artisan queue:work --queue=webhooks,campaigns,outreach,default`
- AI Employee enabled
- At least one org and user
- Integrations test states prepared:
  - LinkedIn connected and disconnected variants
  - Instagram connected and disconnected variants
  - Email connected and disconnected variants

## Evidence to capture per scenario

For each case collect:
1. user prompt
2. assistant response
3. pending approval payload (if any)
4. `list_hash` + `list_src` outcome
5. campaign ID/status if created
6. relevant log lines for failures/retries

---

## A. Status scenarios

- [ ] A1 `brief` returns summary without discovery calls.
- [ ] A2 `weekly brief` returns weekly metrics only.
- [ ] A3 `what do we have today` returns control summary and does not trigger LLM discovery.
- [ ] A4 `attention` returns queue summary and no outbound send.

Expected:
- no pending outreach approval created
- no new prospect list created

---

## B. Discovery-only scenarios

- [ ] B1 `find 20 saas founders in UK` creates saved list and report only.
- [ ] B2 `get me more 30 leads` forces fresh pull, not stale reuse.
- [ ] B3 `do not reuse saved ones, find 50` uses `prefer_fresh`.
- [ ] B4 `search instagram coffee 20` uses IG discovery path and saves IG list.

Expected:
- `execution_report` is discovery-only
- no campaign staged unless outreach intent exists

---

## C. Find + outreach scenarios

- [ ] C1 `find 20 leads and start outreach` stages campaign approvals.
- [ ] C2 same prompt in Assisted mode requires Launch.
- [ ] C3 same prompt in Autopilot may auto-launch if not setup-only and integrations connected.
- [ ] C4 parallel all-channel discovery stages per-platform campaigns.

Expected:
- staging includes funnel and channel-specific campaign
- no mixed-channel single list attachment when separate channel lists exist

---

## D. Setup-only scenarios

- [ ] D1 `create campaign but don't send yet` stages plan only.
- [ ] D2 same prompt in Autopilot still does not auto-launch.
- [ ] D3 `stage this for review only` blocks launch automation.
- [ ] D4 Launch button appears inline in chat card but action is user-driven.

Expected:
- approval remains `pending`
- no campaign run queued until explicit Launch

---

## E. One-shot scenarios

- [ ] E1 Instagram URL one-shot: `send dm https://www.instagram.com/epaphrasio`.
- [ ] E2 Instagram @handle one-shot: `message @epaphrasio once`.
- [ ] E3 Email one-shot: `send one message to john@acme.com`.
- [ ] E4 WhatsApp one-shot by phone.
- [ ] E5 LinkedIn one-shot by profile URL.
- [ ] E6 One-shot setup-only: create without sending.

Expected:
- one-person audience attached (`target_count=1`, `one_shot=true`)
- no multi-step sequence nodes
- no LinkedIn fallback for non-LinkedIn one-shot

---

## F. List-resolved scenarios

- [ ] F1 Prompt references list by name: `"IG: custom software... (10)"`.
- [ ] F2 Prompt references explicit `list_hash` and `list_src`.
- [ ] F3 CSV list attach in campaign launch works.
- [ ] F4 Wrong list name should not attach weak fuzzy mismatch.

Expected:
- attached list matches intended source/name
- launch succeeds when list is valid and integrations are connected

---

## G. Control-command scenarios

- [ ] G1 `LAUNCH {id}` executes pending plan.
- [ ] G2 `REVIEW {id}` returns plan card, no execute.
- [ ] G3 `REJECT {id}` cancels pending.
- [ ] G4 bare `go ahead` launches latest pending non-delete.
- [ ] G5 `confirm delete` confirms pending destructive plans only.

Expected:
- no model call needed for control commands
- approval state transitions are correct

---

## H. Integration blocker scenarios

- [ ] H1 Launch outreach while LinkedIn disconnected returns blocked guidance.
- [ ] H2 Launch IG outreach while IG disconnected returns blocked guidance.
- [ ] H3 Launch email outreach while Email disconnected returns blocked guidance.
- [ ] H4 Re-launch after connecting channel succeeds.

Expected:
- clear missing-channel message
- no false success

---

## I. Inbox scenarios

- [ ] I1 `draft reply` creates pending draft in Assisted.
- [ ] I2 Launch draft sends recipient-facing text only.
- [ ] I3 `book meeting` only after conversation interest.
- [ ] I4 `move to nurture` pauses follow-up path.

Expected:
- no strategy/internal notes sent to prospect
- inbox actions reflected in attention/activity views

---

## J. Content and call manager scenarios

- [ ] J1 `prepare linkedin post` stages content post approval.
- [ ] J2 `reschedule posts` stages/executes correctly per mode.
- [ ] J3 `prepare call manager launch` creates launch-ready plan.

Expected:
- correct module URLs returned
- correct integration checks applied

---

## K. Error and recovery scenarios

- [ ] K1 simulated provider credit error returns user-friendly guidance.
- [ ] K2 queue timeout produces fallback assistant error message.
- [ ] K3 duplicate provider message ID does not double-execute.
- [ ] K4 approved-but-not-executed launch retry works.

Expected:
- stable recovery path and clear user messaging

---

## Sign-off criteria

- [ ] All critical scenarios pass in web surface.
- [ ] All critical scenarios pass in WhatsApp surface.
- [ ] Setup-only guard verified in all autonomy modes.
- [ ] One-shot attach works for LinkedIn/Instagram/Email/Phone inputs.
- [ ] No silent failures in logs for tested flows.
