# Validation Runbook (SociFusion Acquiring SociFusion Customers)

This runbook defines how to execute, measure, and optimize a real customer-acquisition experiment.

## Objective

Primary metric:

`qualified_conversations_per_100_targeted`

Secondary chain:

`targeted -> contacted -> responses -> meaningful_conversations -> qualified_opportunities -> demos -> customers`

## Scope for first experiment

- Niche: agency owners (2-20 employees)
- Offer context: custom software + practical AI + outbound enablement
- Target volume: 300-500 prospects over 2-4 weeks
- Channels: LinkedIn + Instagram first; Email as optional second lane

## Weekly cadence

## Monday (Plan and source)

1. confirm ICP and targeting filters
2. run discovery for planned weekly volume
3. split lists by channel/source
4. stage campaigns with first-touch angle A/B/C

## Tuesday-Thursday (Execution and qualification)

1. send first-touch within channel limits
2. process replies in Attention queue
3. qualify intent before pitching product
4. move low-intent prospects to nurture

## Friday (Review and optimize)

1. calculate conversion rates by angle and channel
2. pause underperforming sequences
3. adjust opening language and follow-up timing
4. set next week's targeting hypotheses
5. run `php artisan ai:prompt-regression-weekly --days=7 --limit=50` and convert top misses into new matrix rows/tests

## Weekly target envelope (starting point)

- targeted: 120
- contacted: >= 90
- response_rate target: >= 12%
- meaningful_conversation_rate target: >= 6%
- qualified_opportunity_rate target: >= 3%
- demo_rate target: >= 1.5%

## Decision thresholds

## Keep

- response_rate >= 12% and qualified_conversations_per_100 >= 5

## Improve

- response_rate 7%-11% or qualified_conversations_per_100 3-4.9
- action: tighten ICP, adjust opener angle, improve reply ladder

## Stop/replace angle

- response_rate < 7% for 2 consecutive weekly batches
- action: replace message angle and/or targeting assumptions

## Message angle experiments

Run all three each week with balanced samples.

- Angle A: outbound consistency problem question
- Angle B: current acquisition mix (referrals vs outbound)
- Angle C: competitor-account acquisition behavior

Minimum sample per angle before decision:

- at least 40 contacted prospects per angle

## Channel strategy

## LinkedIn

- prioritize quality targeting and controlled pacing
- avoid aggressive invitation behavior
- use invite-accepted gating before DM when needed

## Instagram

- use conversational style opener
- avoid email-style long copy
- qualify quickly and move to next step only on intent signal

## Email (optional lane)

- concise context + one question
- no hard pitch in first touch
- test subject/body variants only after baseline achieved

## Operational guardrails

- setup-only prompts must never auto-send
- one-shot asks must produce one send only
- no fake personalization
- no fabricated metrics in reports
- no claiming send success unless execute path confirms success

## Daily operator checklist

- [ ] queue worker healthy
- [ ] integrations healthy
- [ ] pending approvals reviewed
- [ ] sends executed within limits
- [ ] replies triaged within SLA
- [ ] errors and failed channels logged

## SLA targets

- first human/AI reply to inbound: within 4 business hours
- high-intent reply handling: within 1 business hour
- meeting-ready lead handoff: same day

## Reporting template (weekly)

Use this format in internal review:

1. volume:
   - targeted
   - contacted
2. outcomes:
   - responses
   - meaningful conversations
   - qualified opportunities
   - demos
   - customers
3. conversion rates:
   - response_rate = responses/contacted
   - qualified_per_100 = qualified_opportunities/targeted*100
4. by-angle:
   - A/B/C contacted, responses, qualified
5. by-channel:
   - LinkedIn vs Instagram vs Email
6. decisions:
   - keep / improve / stop per angle

## Experiment completion criteria

Minimum proof threshold for phase completion:

- at least 300 targeted prospects processed
- at least 10 qualified opportunities generated
- at least 3 demos booked
- repeatable winning opener angle identified

If achieved, transition to scale plan with doubled weekly volume and stricter automation QA.
