# AI Execution Boundary Inventory

Purpose: classify every active LLM entrypoint so customer-affecting decision/execution stays inside the governed SociFusion boundary.

## Classification legend
- `governed`: routed through `AgentOrchestrator` + `SociFusionAgent` + `GatedTool` safety boundary.
- `allowed_direct_copy_only`: direct generation only; no business action decisioning/execution.
- `needs_migration`: customer-affecting behavior still bypasses governed boundary.

## Inventory

| File | Method/Path | Category | Current behavior | Classification | Notes |
|---|---|---|---|---|---|
| `app/V2/Ai/Services/AgentOrchestrator.php` | `runAgentAndPersistReply()` | Agent reasoning | Main orchestrator for AI employee turns | governed | Primary governed AI path |
| `app/Ai/Agents/SociFusionAgent.php` | `prompt()` + tools | Agent reasoning | LLM reasoning and tool composition | governed | Uses `GatedTool` for all tool calls |
| `app/V2/Services/OpenAIContentService.php` | `generateOutreachContent()` | Content generation | Draft/paraphrase outreach copy | allowed_direct_copy_only | Keep for UI authoring and internal draft composition |
| `app/V2/Services/OpenAIContentService.php` | `generateInboxReply()` | User-facing AI operation | Generates reply text | needs_migration | Should run as governed tool path when auto-deciding send/next action |
| `app/V2/Services/OpenAIContentService.php` | `generateAgentJson()` | Classification/planning | JSON planning helper used by AI services | needs_migration | Planning that can influence actions should route through governed path |
| `app/V2/Services/OpenAIContentService.php` | `generateLinkedInPost()` / `rewritePost()` / `improvePost()` | Content generation | Post drafting only | allowed_direct_copy_only | Non-executional content creation |
| `app/Services/ChatGPT.php` | `generateContent()` and wrappers | Legacy | Legacy direct OpenAI calls in old flows | needs_migration | Keep temporarily for legacy UI, progressively phase down |
| `app/Http/Controllers/Web/OutreachAiWebController.php` | `POST /outreach/ai/content` | User-facing AI operation | Direct content generation endpoint | allowed_direct_copy_only | Harden as copy-only contract |
| `app/Http/Controllers/Web/ContentWebController.php` | `content/ai/*` endpoints | Content generation | Post generation/improvement | allowed_direct_copy_only | Drafting only |
| `app/Http/Controllers/Web/AiMessagesWebController.php` | AI writer endpoints | Legacy/content generation | Uses legacy `ChatGPT` | needs_migration | Legacy path still active |
| `app/Http/Controllers/Web/InspirationWebController.php` | remix endpoint | Content generation | Uses `ChatGPT` to rewrite content | allowed_direct_copy_only | Safe if drafting-only |
| `app/V2/Ai/Services/CampaignFirstTouchPersonalizationService.php` | personalization generation | Content generation | Generates first-touch copy for staged campaigns | allowed_direct_copy_only | Called from governed plan execution |
| `app/V2/Ai/Services/PersonalizedMessageCommandCenterService.php` | draft personalization | Content generation | Draft text only in command center flow | allowed_direct_copy_only | Governed by approval flow |
| `app/V2/Ai/Services/BookMeetingCommandCenterService.php` | message drafting | Content generation | Generates suggested message text | allowed_direct_copy_only | Governed execution still requires explicit action |
| `app/V2/Services/UnifiedInboxReplyService.php` | AI draft replies | Classification/content generation | Drafts reply candidates | allowed_direct_copy_only | Sending must remain governed |
| `app/V2/Services/LaravelAiWebResearchService.php` | `searchCompanies`/`fetchUrlSummary` | External research | LLM-assisted web research tools | governed | No execution side-effect; research support |
| `app/V2/Ai/Services/WhatsAppVoiceTranscriptionService.php` | transcription | Transcription | Converts voice notes to text | governed | Input transformation only |

## Guardrail decision

Customer-affecting AI operations are any path that can decide or execute:
- who to contact,
- whether to contact,
- what campaign/action to run,
- when to execute/schedule,
- or any external send/mutation.

Those operations must be served by the governed path (`AgentOrchestrator` + tools + policy gates + approval/autonomy).

Direct AI content services remain allowed for drafting-only UX and internal text generation inside already-governed flows.
