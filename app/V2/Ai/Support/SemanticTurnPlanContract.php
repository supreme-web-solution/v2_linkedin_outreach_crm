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
 *   preferred_channels: list<string>,
 *   channel_scope: string,
 *   geography: string|null,
 *   schedule_hint: string|null,
 *   data_preference: string,
 *   execution_mode: string,
 *   prepare_only: bool,
 *   send_requested: bool,
 *   delete_requested: bool,
 *   cold_one_shot: bool,
 *   recipient_correction: bool,
 *   message_correction: bool,
 *   offer_override: string|null,
 *   inbox_reply: bool,
 *   audience_intent: string,
 *   audience_ref: string|null,
 *   requires_clarification: bool,
 *   clarification_reason: string|null,
 *   ambiguous_referent: string|null,
 *   handoff_brief: string|null,
 *   handoff_query: string|null,
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

    public const CHANNELS = [
        'whatsapp',
        'linkedin',
        'email',
        'instagram',
        'telegram',
        'twitter',
    ];

    public const CHANNEL_SCOPES = [
        'single',
        'multi',
        'unspecified',
    ];

    public const AUDIENCE_INTENTS = [
        'discover',
        'reuse_any',
        'reuse_named',
        'explicit_list',
        'unspecified',
    ];

    public static function systemPrompt(): string
    {
        return <<<'PROMPT'
You sit BETWEEN the user and the executing Command Center agent.
The user never talks to that agent directly. You read the thread, decide what they meant, and write the handoff that agent will run. It will not re-read the chat — it only sees your fields.

You receive:
- user_message: the latest utterance (may be short, paraphrased, or a confirmation)
- thread: prior turns in this conversation (Soci + user)
- workspace: saved business/ICP/channels from onboarding
- pending_plans_waiting: whether a Review & Launch plan already exists
- recent_cold_outbound: null, or the last one-shot outbound in this thread (channel, recipient, research_url, has_draft) — use this to detect recipient_correction / message_correction

Handoff (required whenever you are not clarifying):
- handoff_brief: one or two sentences the executing agent should treat as the real request. Written for a colleague who did not see the chat.
- handoff_query: the audience/search string discovery or outreach should use (titles, industry, offer, geography). Never the raw confirmation ("go ahead", "ok", "just 10").
- target_segment + quantity + geography must match that same rewritten meaning.

Rules:
- Infer meaning from the THREAD + workspace + latest turn, not from keywords in isolation.
- CHANNELS (meaning, not wording):
  preferred_channel = primary channel when one dominates.
  preferred_channels = all named send/discovery channels (whatsapp|linkedin|email|instagram|telegram|twitter). Use twitter for X.
  channel_scope=multi when they want multichannel / all channels / LinkedIn+Email+…; single when one channel; unspecified otherwise.
  "Find on LinkedIn then email them" → preferred_channels=["linkedin","email"], preferred_channel=email for send, handoff_brief states discover on LinkedIn / outreach on email.
- INTERACTION MODE (pick one meaning):
  inbox_reply=true when they want a reply inside an existing Unified Inbox thread (that email we received, open conversation, attention queue reply). Then cold_one_shot=false.
  cold_one_shot=true when they want a single outbound to a named contact (email, LinkedIn/IG/Telegram/X URL or handle, WhatsApp number) without a multi-person list and without needing an inbox thread. quantity=1. Also true when rewriting the last cold outbound (message_correction) even if they do not re-paste the contact.
  recipient_correction=true ONLY when the recipient identity actually changes from recent_cold_outbound (wrong address → different address). Same email/handle again is NOT recipient_correction. When true: cold_one_shot=true; handoff_brief says reuse prior research/draft only if the draft was already good — otherwise rewrite.
  message_correction=true when they keep the same recipient but want the draft rewritten — wrong offer, too vague/short, did not use the research URL, not personalized, softer/harder tone, etc. Also true when they criticize the last cold outbound draft without naming a new contact (see recent_cold_outbound). Set offer_override when they state a corrected offer; otherwise null. cold_one_shot=true. Do NOT switch to discover_prospects / Instagram search.
  Finding N prospects then messaging them → cold_one_shot=false, inbox_reply=false (list outreach).
- AUDIENCE BINDING:
  audience_intent=discover — assemble/find new targets.
  audience_intent=reuse_any — use whatever saved list already fits; do not rediscover unless empty.
  audience_intent=reuse_named or explicit_list — bind a specific named/saved list; set audience_ref to the list name or description from the utterance (never invent a hash).
  audience_intent=unspecified when unclear.
  "DM my latest Instagram list" / "use that CSV" → reuse_named or explicit_list + audience_ref.
- Rewrite the user's intent into target_segment, quantity, geography, handoff_brief, and handoff_query. Do not copy a short confirmation into those fields.
- Short answers resolve against Soci's last question: a count fills quantity; a place fills geography; yes/go ahead/proceed means continue the work already proposed in the thread.
- If the thread already agreed who to reach (titles, industry, offer) or workspace.icp has it, reuse that ICP. Do not set requires_clarification for facts already in the thread or workspace.
- Different phrasings of the same goal must produce the same semantic fields.
- Word numbers ("fifty") and digits ("50") are equivalent quantities.
- "Companies", "founders", "prospects", "leads", "people" are target entities — normalize entity type, not wording.
- "Already approached", "previously contacted", "ignore existing outreach" → exclude_previously_contacted=true.
- "NEW", "fresh", "net-new", "don't reuse" → new_only=true and data_preference=new_only when quantity discovery is requested.
- "More", "additional", "another N", "N more", "get me another" → quantity=N when stated, new_only=true, data_preference=discover_new (incremental net-new discovery — do NOT satisfy from existing saved lists).
- "Use what we already have", "existing list" → data_preference=reuse_existing_first, audience_intent=reuse_any unless a named list is given.
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
- pending_plans_waiting=false: a short "go ahead" / "yes" / "do it" after Soci offered to source or map a list is discover_prospects (find_and_save), not execute_outreach. They are continuing the thread, not launching a plan that does not exist.
- pending_plans_waiting=true AND the latest turn is only approving that plan (no new count, audience, or channel) → execute_outreach / send_requested only if they clearly want that staged plan launched. If they change count or audience, treat as a new discovery/prepare turn.
- NEVER name tools, APIs, or implementation steps. Describe the outcome (find N of this ICP; stage outreach; answer from existing records).
- NEVER invent tenant IDs, user IDs, list hashes, or database counts.
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
  "preferred_channel": null or "whatsapp|linkedin|email|instagram|telegram|twitter",
  "preferred_channels": ["whatsapp|linkedin|email|instagram|telegram|twitter", "..."] or [],
  "channel_scope": "single|multi|unspecified",
  "geography": null or string,
  "schedule_hint": null or short phrase like "tomorrow morning",
  "data_preference": "reuse_existing_first|discover_new|new_only|unspecified",
  "execution_mode": "read_only|find_and_save|prepare_outreach|send_outreach|delete|execute_management|clarify",
  "prepare_only": boolean,
  "send_requested": boolean,
  "delete_requested": boolean,
  "cold_one_shot": boolean,
  "recipient_correction": boolean,
  "message_correction": boolean,
  "offer_override": null or short corrected offer/pitch angle,
  "inbox_reply": boolean,
  "audience_intent": "discover|reuse_any|reuse_named|explicit_list|unspecified",
  "audience_ref": null or named list / audience description from the user,
  "requires_clarification": boolean,
  "clarification_reason": null or string,
  "ambiguous_referent": null or "campaign|lead_list|prospect|resource",
  "handoff_brief": "rewritten request for the executing agent, or null if clarifying",
  "handoff_query": "audience search string the next step should use, or null",
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
            'preferred_channels',
            'channel_scope',
            'geography',
            'schedule_hint',
            'data_preference',
            'execution_mode',
            'prepare_only',
            'send_requested',
            'delete_requested',
            'cold_one_shot',
            'recipient_correction',
            'message_correction',
            'offer_override',
            'inbox_reply',
            'audience_intent',
            'audience_ref',
            'requires_clarification',
            'clarification_reason',
            'ambiguous_referent',
            'handoff_brief',
            'handoff_query',
            'confidence',
        ];
    }

    /**
     * Laravel AI HasStructuredOutput schema — single source of truth with requiredKeys().
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public static function structuredSchema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array
    {
        return [
            'user_objective' => $schema->string()->enum(self::OBJECTIVES)->required(),
            'target_entity' => $schema->string()->enum([
                'prospects', 'companies', 'campaigns', 'lead_lists', 'activity', 'mixed', 'unknown',
            ])->required(),
            'target_segment' => $schema->string()->nullable()->required(),
            'quantity' => $schema->integer()->min(0)->max(100)->nullable()->required(),
            'new_only' => $schema->boolean()->required(),
            'exclude_previously_contacted' => $schema->boolean()->required(),
            'decision_maker_required' => $schema->boolean()->required(),
            'preferred_channel' => $schema->string()->enum(self::CHANNELS)->nullable()->required(),
            'preferred_channels' => $schema->array()->items($schema->string()->enum(self::CHANNELS))->required(),
            'channel_scope' => $schema->string()->enum(self::CHANNEL_SCOPES)->required(),
            'geography' => $schema->string()->nullable()->required(),
            'schedule_hint' => $schema->string()->nullable()->required(),
            'data_preference' => $schema->string()->enum(self::DATA_PREFERENCES)->required(),
            'execution_mode' => $schema->string()->enum(self::EXECUTION_MODES)->required(),
            'prepare_only' => $schema->boolean()->required(),
            'send_requested' => $schema->boolean()->required(),
            'delete_requested' => $schema->boolean()->required(),
            'cold_one_shot' => $schema->boolean()->required(),
            'recipient_correction' => $schema->boolean()->required(),
            'message_correction' => $schema->boolean()->required(),
            'offer_override' => $schema->string()->nullable()->required(),
            'inbox_reply' => $schema->boolean()->required(),
            'audience_intent' => $schema->string()->enum(self::AUDIENCE_INTENTS)->required(),
            'audience_ref' => $schema->string()->nullable()->required(),
            'requires_clarification' => $schema->boolean()->required(),
            'clarification_reason' => $schema->string()->nullable()->required(),
            'ambiguous_referent' => $schema->string()->enum([
                'campaign', 'lead_list', 'prospect', 'resource',
            ])->nullable()->required(),
            'handoff_brief' => $schema->string()->nullable()->required(),
            'handoff_query' => $schema->string()->nullable()->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }
}
