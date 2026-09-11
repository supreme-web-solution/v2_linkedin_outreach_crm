# Prompt Intent Coverage

This file is the canonical prompt-behavior matrix for Soci in Command Center.

For each prompt:
- Path = expected primary flow (tool or control command)
- Approval = what should happen in Copilot / Assisted / Autopilot+
- Forbidden = behavior that must never happen

## 1) Status and reporting (1-12)

| # | Prompt example | Path | Approval | Forbidden |
|---:|---|---|---|---|
| 1 | brief | `get_sales_brief` | none | discovery |
| 2 | weekly brief | `get_weekly_sales_brief` | none | campaign staging |
| 3 | status | `get_campaign_stats` | none | tool side effects |
| 4 | what do we have today | control `status_brief` | none | discover prospects |
| 5 | pipeline update | `get_sales_brief` | none | outreach launch |
| 6 | what's active | control rewrite -> stats/brief | none | launch/reject actions |
| 7 | attention | `get_attention_queue` | none | send outbound |
| 8 | who needs me | `get_attention_queue` | none | campaign edits |
| 9 | nurture due | `get_nurture_due_queue` | none | prospect discovery |
| 10 | meeting brief | `get_meeting_brief` | none | outreach creation |
| 11 | help | control help menu | none | LLM tool chain |
| 12 | commands | control help menu | none | hidden execution |

## 2) Discovery only (13-24)

| # | Prompt example | Path | Approval | Forbidden |
|---:|---|---|---|---|
| 13 | find 20 clients in london | `discover_prospects` | none | draft campaign |
| 14 | get me 100 prospects | `discover_prospects target_count=100` | none | exceed 100 |
| 15 | find 50 more prospects | `discover_prospects prefer_fresh` | none | stale list reuse |
| 16 | do not reuse old list, get 30 | `discover_prospects prefer_fresh` | none | cached return |
| 17 | search instagram coffee 40 | `discover_prospects platform=instagram` | none | ask handle first |
| 18 | find founders in saas lagos | `discover_prospects` filters | none | outreach execution |
| 19 | get people by title cmo | `discover_prospects title` | none | launch approval |
| 20 | save prospects only | discovery report | none | messaging |
| 21 | fetch 10 fresh leads all channels | `discover_prospects platform=all` | none | one merged list |
| 22 | discover new people, no campaign | discovery report | none | stage plan |
| 23 | find ideal customers | `discover_prospects` or `build_icp` when unclear | mode-specific | immediate pitch |
| 24 | get me people then stop there | discovery only | none | drafting outreach |

## 3) Find + outreach (25-36)

| # | Prompt example | Path | Approval | Forbidden |
|---:|---|---|---|---|
| 25 | find 50 and start outreach | discover -> draft | mode-based | skip list attach |
| 26 | find leads and message them | discover -> draft | mode-based | discovery-only reply |
| 27 | find ig leads and dm them | discover IG -> draft IG | mode-based | linkedin default |
| 28 | discover all and run campaigns | parallel discover -> split stage | mode-based | single mixed campaign |
| 29 | get customers and launch now | discover -> draft -> launch rules | mode-based | Assisted auto-launch |
| 30 | find and build campaign | discover -> draft | mode-based | fake execute |
| 31 | run outreach for found list | draft with returned `list_hash` | mode-based | re-discover unnecessarily |
| 32 | find and activate winners | discover + draft + optional `activate` | mode-based | activation without draft |
| 33 | source leads then email sequence | discover -> draft Email | mode-based | LinkedIn-only sequence |
| 34 | find from competitors then outreach | competitor fallback + draft | mode-based | claim competitor run pre-launch |
| 35 | discover 80 and market to them | discover -> draft | mode-based | overfetch over 100 |
| 36 | get me clients + multichannel outreach | discover -> draft channels | mode-based | one-channel hardcode |

## 4) Setup-only no-send intent (37-48)

| # | Prompt example | Path | Approval | Forbidden |
|---:|---|---|---|---|
| 37 | create campaign but don't send yet | `draft_campaign_plan setup_only` | pending only | auto-launch |
| 38 | stage outreach not now | draft setup-only | pending only | activate campaign |
| 39 | prepare this for review only | draft setup-only | pending only | send any DM/email |
| 40 | make campaign and hold | draft setup-only | pending only | queue run |
| 41 | create sequence, launch later | draft setup-only | pending only | immediate launch |
| 42 | just set it up | setup-only detection | pending only | auto-send |
| 43 | build this but no launch | setup-only detection | pending only | LAUNCH control |
| 44 | draft and wait for my go | setup-only detection | pending only | autopilot override |
| 45 | do outreach setup only | setup-only detection | pending only | execute tool |
| 46 | review and launch later | setup-only detection | pending only | status executed |
| 47 | plan this campaign first | draft stage | pending only | activate nodes |
| 48 | keep it in review panel only | draft stage | pending only | send first touch |

## 5) One-shot recipient asks (49-60)

| # | Prompt example | Path | Approval | Forbidden |
|---:|---|---|---|---|
| 49 | dm https://www.instagram.com/epaphrasio | one-shot IG attach | mode-based | require LinkedIn search |
| 50 | send one email to john@acme.com | one-shot Email attach | mode-based | multi-step sequence |
| 51 | whatsapp +234... one intro | one-shot WA attach | mode-based | add follow-ups |
| 52 | message @brandfounder once | one-shot IG by handle | mode-based | broad IG discovery |
| 53 | single linkedin dm to profile url | one-shot LinkedIn | mode-based | multi-lead list |
| 54 | just one greeting no follow-up | one-shot sequence | mode-based | Wait N days |
| 55 | telegram one message to @user | one-shot TG attach | mode-based | LinkedIn-first flow |
| 56 | quick dm no campaign drip | one-shot true | mode-based | drip sequence |
| 57 | one-time invite email only | one-shot Email | mode-based | LinkedIn discovery |
| 58 | send this single IG intro | one-shot IG | mode-based | campaign with 500 target |
| 59 | write and send one message | one-shot + launch rules | mode-based | silent no-op |
| 60 | create one-shot but don't send | one-shot + setup_only | pending only | auto-send |

## 6) List-referenced asks (61-72)

| # | Prompt example | Path | Approval | Forbidden |
|---:|---|---|---|---|
| 61 | reach out to list IG: ... (10) | resolve list name -> draft | mode-based | wrong list attach |
| 62 | use list_hash abc src csv | explicit list merge | mode-based | override explicit list |
| 63 | run my latest instagram list | resolve latest csv IG list | mode-based | force LinkedIn |
| 64 | campaign for audience list 50 leads | strict match by name/score | mode-based | weak fuzzy mismatch |
| 65 | dm all in imported list | draft from csv list | mode-based | rediscover prospects |
| 66 | use saved contacts list | draft with `list_hash` | mode-based | list detach |
| 67 | launch list # hash now | control LAUNCH with audience check | command path | missing audience bypass |
| 68 | stage from this lead list only | draft with list constraint | mode-based | auto-prospecting |
| 69 | use linkedin saved audience | draft aud/sn list | mode-based | switch source silently |
| 70 | target this existing list and email | draft Email + list | mode-based | channel mismatch |
| 71 | run whatsapp on imported phones list | draft WA + csv | mode-based | linkedin invite steps |
| 72 | create campaign from list, no find | draft only | mode-based | discovery call |

## 7) Control commands (73-84)

| # | Prompt example | Path | Approval | Forbidden |
|---:|---|---|---|---|
| 73 | LAUNCH 12 | control launch | executes pending | model call |
| 74 | APPROVE 12 | alias -> launch | executes pending | duplicate approval |
| 75 | REJECT 12 | control reject | reject pending | launch on reject |
| 76 | REVIEW 12 | control review card | none | execute |
| 77 | go ahead | fuzzy confirm newest pending | control | random launch |
| 78 | yes | fuzzy confirm | control | unrelated action |
| 79 | confirm delete | bulk delete confirm | control | launch non-delete |
| 80 | ACTIVATE 55 | campaign activate | direct execute | new draft staging |
| 81 | PAUSE 55 | campaign pause | direct execute | launch other plans |
| 82 | pause all campaigns | pause all command | direct execute | delete campaigns |
| 83 | PREVIEW | alias to review newest pending | control | execute silently |
| 84 | DISCARD | alias to reject newest pending | control | launch |

## 8) Integrations and blocking (85-96)

| # | Prompt example | Path | Approval | Forbidden |
|---:|---|---|---|---|
| 85 | launch linkedin campaign disconnected | launch block response | no execute | fake sent |
| 86 | launch ig campaign no instagram integration | block + integration link | no execute | switch channel |
| 87 | publish post disconnected | block/schedule logic | no execute | claim publish success |
| 88 | call manager launch no linkedin | block guidance | no execute | queue chats |
| 89 | email campaign without email integration | block with missing channel | no execute | fallback to LinkedIn |
| 90 | all channels disconnected | block with list of channels | no execute | partial hidden run |
| 91 | one channel connected one missing | partial block details | no execute | silent skip |
| 92 | retry launch after connect | relaunch same approval | execute if valid | stale block message |
| 93 | launch failed by missing list | missing audience response | no execute | create empty campaign |
| 94 | setup-only in autopilot | keep pending | no execute | auto-launch |
| 95 | stale plan id launch | approval-not-pending response | none | create new unknown plan |
| 96 | approved-but-no-campaign retry | relaunch allowed | execute retry | terminal failure loop |

## 9) Inbox, content, and operations (97-108)

| # | Prompt example | Path | Approval | Forbidden |
|---:|---|---|---|---|
| 97 | draft reply for hottest lead | attention -> `draft_reply` | mode-based | send without approval in Assisted |
| 98 | send reply now | `send_inbox_reply` or launch reply draft | mode-based | send planning text |
| 99 | book meeting with this lead | `book_meeting` | mode-based | push meeting before qualify |
| 100 | classify this reply | `classify_reply` | none | campaign mutation |
| 101 | move lead to nurture | `move_lead_to_nurture` | mode-based | continue active sequence |
| 102 | next best action | `set_next_best_action` | mode-based | auto-execute in Assisted |
| 103 | create linkedin post tomorrow | `prepare_linkedin_post` | mode-based | immediate publish in Assisted |
| 104 | reschedule monday posts to friday | `reschedule_content_posts` | mode-based | edit unrelated posts |
| 105 | list content drafts | `list_content_posts` | none | create post |
| 106 | optimize campaign 88 | `optimize_campaign` | mode-based | invented metrics |
| 107 | let ai execute recommendations | `let_ai_execute` | mode-based | destructive auto actions |
| 108 | start acquisition experiment agencies 500 | `start_acquisition_experiment` then discovery | mode-based | optimize for message volume only |

## Coverage checks

- Includes link-based prompts (`linkedin.com/in/...`, `instagram.com/...`), @handle prompts, and company/entity mentions.
- Includes setup-only, find-only, find+outreach, and launch-control flows.
- Includes cross-channel one-shot flows (LinkedIn, Instagram, Email, WhatsApp, Telegram).
- Includes failure and recovery behavior (missing integrations, missing audience, retries, stale approvals).
