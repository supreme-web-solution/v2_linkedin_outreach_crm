<?php

namespace App\V2\Ai\Support;

/**
 * Structured semantic turn plan — meaning only, never tool routing.
 *
 * @phpstan-type SemanticPlan array{
 *   user_objective: string,
 *   target_entity: string,
 *   target_segment: string|null,
 *   quantity: int|null,
 *   new_only: bool,
 *   exclude_previously_contacted: bool,
 *   decision_maker_required: bool,
 *   preferred_channel: string|null,
 *   geography: string|null,
 *   schedule_hint: string|null,
 *   data_preference: string,
 *   execution_mode: string,
 *   prepare_only: bool,
 *   send_requested: bool,
 *   delete_requested: bool,
 *   requires_clarification: bool,
 *   clarification_reason: string|null,
 *   ambiguous_referent: string|null,
 *   confidence: float
 * }
 */
final class SemanticTurnPlanContract
{
    public const OBJECTIVES = [
        'discover_prospects',
        'prepare_outreach',
        'execute_outreach',
        'report_state',
        'manage_resources',
        'general_assist',
    ];

    public const EXECUTION_MODES = [
        'read_only',
        'find_and_save',
        'prepare_outreach',
        'send_outreach',
        'delete',
        'execute_management',
        'clarify',
    ];

    public const DATA_PREFERENCES = [
        'reuse_existing_first',
        'discover_new',
        'new_only',
        'unspecified',
    ];

    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
You are the semantic planning layer for SociFusion Command Center.
Interpret the user's message into structured meaning ONLY.

Rules:
- Infer meaning from context and intent, not keyword matching.
- Different phrasings of the same goal must produce the same semantic fields.
- Word numbers ("fifty") and digits ("50") are equivalent quantities.
- "Companies", "founders", "prospects", "leads", "people" are target entities — normalize entity type, not wording.
- "Already approached", "previously contacted", "ignore existing outreach" → exclude_previously_contacted=true.
- "NEW", "fresh", "net-new", "don't reuse" → new_only=true and data_preference=new_only when quantity discovery is requested.
- "More", "additional", "another N", "N more", "get me another" → quantity=N when stated, new_only=true, data_preference=discover_new (incremental net-new discovery — do NOT satisfy from existing saved lists).
- "Use what we already have", "existing list" → data_preference=reuse_existing_first, avoid implying external discovery unless quantity deficit remains.
- Questions about past actions, counts, history, or current records → execution_mode=read_only, user_objective=report_state.
- Showing/listing existing data without asking to message → read_only.
- DISCOVERING TARGETS vs REPORTING ON EXISTING STATE (context, not keywords):
  When the user asks you to obtain, assemble, or produce a set of target entities (prospects, founders, companies, leads) — including phrasings like find N, identify N, list N, build a list of N, give me N, get N, surface N potential targets — interpret as discovery (user_objective=discover_prospects, execution_mode=find_and_save) when they want NEW targets to work with, not information about records already in the workspace.
  When the user asks to identify, determine, or show WHICH OF OUR / EXISTING records match a criterion (e.g. "identify which of our prospects have WhatsApp", "which campaigns are active", "show who we already contacted") → report_state, execution_mode=read_only. The referent is existing workspace data, not assembling a new target set.
  Quantity + target segment strongly suggests discovery; "our/existing/already have/we have" strongly suggests reporting or reuse.
- Find/save without send → find_and_save, send_requested=false.
- Compound find + contact: when the user asks to find prospects AND start conversations, reach out, message them, or begin outreach → send_requested=true and execution_mode=send_outreach (or find_and_save with send_requested=true). "Start relevant conversations" after finding targets is outreach intent, not find-only.
- Prepare/stage/get ready/start tomorrow WITHOUT explicit send now → prepare_outreach, prepare_only=true, send_requested=false.
- Explicit reach out, contact, message, send, launch outreach → send_outreach unless user forbids sending.
- Delete/remove/wipe campaigns, lists, leads → delete_requested=true, execution_mode=delete when target is clear.
- "Send the campaign" / "Delete that campaign" with no identifiable referent → requires_clarification=true, execution_mode=clarify.
- NEVER name tools, APIs, or implementation steps.
- NEVER invent tenant IDs, user IDs, or database counts.
- confidence: 0.0-1.0 how clear the interpretation is.

Return JSON with exactly these keys:
{
  "user_objective": "discover_prospects|prepare_outreach|execute_outreach|report_state|manage_resources|general_assist",
  "target_entity": "prospects|companies|campaigns|lead_lists|activity|mixed|unknown",
  "target_segment": "short free-text segment or null",
  "quantity": integer or null,
  "new_only": boolean,
  "exclude_previously_contacted": boolean,
  "decision_maker_required": boolean,
  "preferred_channel": null or "whatsapp|linkedin|email|instagram|telegram",
  "geography": null or string,
  "schedule_hint": null or short phrase like "tomorrow morning",
  "data_preference": "reuse_existing_first|discover_new|new_only|unspecified",
  "execution_mode": "read_only|find_and_save|prepare_outreach|send_outreach|delete|execute_management|clarify",
  "prepare_only": boolean,
  "send_requested": boolean,
  "delete_requested": boolean,
  "requires_clarification": boolean,
  "clarification_reason": null or string,
  "ambiguous_referent": null or "campaign|lead_list|prospect|resource",
  "confidence": number
}
PROMPT;
    }

    /**
     * @return list<string>
     */
    public static function requiredKeys(): array
    {
        return [
            'user_objective',
            'target_entity',
            'target_segment',
            'quantity',
            'new_only',
            'exclude_previously_contacted',
            'decision_maker_required',
            'preferred_channel',
            'geography',
            'schedule_hint',
            'data_preference',
            'execution_mode',
            'prepare_only',
            'send_requested',
            'delete_requested',
            'requires_clarification',
            'clarification_reason',
            'ambiguous_referent',
            'confidence',
        ];
    }
}
