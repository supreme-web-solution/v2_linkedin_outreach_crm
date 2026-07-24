# Unipile Capability Matrix and Fallback Policy

This file implements todo `map-unipile-capabilities`.

## Feature-by-feature mapping

| Feature | v1 path | Unipile coverage | v2 default | fallback |
| --- | --- | --- | --- | --- |
| Account connect/reconnect | custom sync + stored LinkedIn session | yes | Unipile | none |
| LinkedIn search | Phantom + Voyager | yes | Unipile | Phantom temporary |
| Profile retrieval/enrichment | Phantom + custom parsing | yes | Unipile | Phantom temporary |
| Invitations | extension Voyager flows | yes | Unipile | none except outage |
| Messaging/chats | extension + CRM mixed flow | yes | Unipile | none except outage |
| Message/reaction/read events | polling-heavy logic | yes (webhooks/events) | Unipile webhooks | temporary polling |
| Posts/comments/reactions | Phantom/RapidAPI mixed | partial/yes | Unipile first | RapidAPI temporary |
| Competitor/feed discovery | RapidAPI + Phantom | partial | mixed | RapidAPI temporary |

## Policy

1. New features must start on Unipile contracts.
2. Phantom/RapidAPI use must be feature-flagged and explicitly justified.
3. Every fallback must include decommission milestone and parity ticket.
