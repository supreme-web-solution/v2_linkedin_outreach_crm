# SociFusion Validation Experiment (Phase 4)

Use SociFusion to acquire SociFusion customers. The goal is **qualified conversations per 100 targeted prospects** — not message volume.

## How to start

In Command Center, tell Soci:

> Start a validation experiment for marketing agencies with 2–20 employees. Target 500 prospects.

Soci runs `start_acquisition_experiment`, then:

1. `discover_prospects` with `platform=all` and `target_count=500`
2. Single-channel outreach per list (LinkedIn list → LinkedIn only; IG list → Instagram only)
3. Conversation-first first messages (earn a reply — no pitch)
4. Inbox qualification → sales page or webinar → meeting link (last card)

## Dashboard funnel

The dashboard shows live counts:

| Stage | Meaning |
|-------|---------|
| Targeted prospects | Leads in outreach campaigns / lists |
| Successfully contacted | First touch sent (running/done/replied) |
| Responses | Prospects who replied |
| Meaningful conversations | Back-and-forth inbox threads |
| Qualified opportunities | `qualify_lead` stage: sql, qualified, meeting_booked |
| Demos booked | Calls with `booked` status + scheduled time |
| Customers won | `qualify_lead` stage: customer, or post-call outcome: customer |

## Message angles to A/B (one niche at a time)

**A — Problem:** "Are you currently doing outbound to consistently bring new prospects into the pipeline?"

**B — Acquisition:** "Are most new clients still coming through referrals, or have you built outbound too?"

**C — Competitive:** "Are you reaching out to prospects already buying from other agencies, or is acquisition mostly inbound?"

Measure: responses → meaningful conversations → qualified → demos → customers.

## Success benchmark (example)

```
500 targeted → 400 contacted → 80 responses → 35 conversations
→ 15 qualified → 8 demos → 3 customers
```

That is **15 qualified opportunities from 500 targeted prospects** — a compelling product proof.

## Mark outcomes in Soci

- **Qualified:** `qualify_lead` with stage `qualified` or `sql`
- **Demo booked:** `book_meeting` tool
- **Customer won:** `qualify_lead` stage `customer` or `post_call_crm_update` outcome `customer`

Each action updates the dashboard funnel automatically.
