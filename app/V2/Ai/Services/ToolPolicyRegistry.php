<?php

namespace App\V2\Ai\Services;

class ToolPolicyRegistry
{
    /**
     * @return array{action_class:string,requires_explicit_intent:bool,approval_policy:string,autonomy_min_level:int}
     */
    public function policyFor(string $tool): array
    {
        $tool = trim(strtolower($tool));

        $read = ['search_prospects', 'find_prospects', 'get_sales_brief', 'get_weekly_sales_brief', 'get_campaign_stats', 'get_attention_queue', 'get_meeting_brief', 'get_nurture_due_queue', 'check_integrations', 'research_prospect', 'classify_reply', 'list_content_posts', 'get_activity', 'search_activity', 'get_workflow_run'];
        $prepare = ['propose_strategy', 'draft_campaign_plan', 'build_icp', 'prepare_enrichment', 'prepare_linkedin_post', 'prepare_competitor_harvest', 'prepare_call_manager_launch', 'configure_campaign_inbox_ai', 'move_lead_to_nurture', 'adjust_follow_up', 'set_next_best_action', 'optimize_campaign', 'import_leads_csv', 'let_ai_execute', 'qualify_lead', 'post_call_crm_update', 'draft_reply', 'draft_personalized_message', 'delete_campaign', 'delete_resource'];
        $mutate = ['discover_prospects', 'save_contacts', 'update_sender_profile', 'reschedule_content_posts'];
        $external = ['activate_outreach_campaign', 'pause_outreach_campaign', 'send_inbox_reply', 'book_meeting'];
        $destructive = ['delete_campaign', 'delete_resource'];

        if (in_array($tool, $destructive, true)) {
            return ['action_class' => 'destructive', 'requires_explicit_intent' => true, 'approval_policy' => 'strict', 'autonomy_min_level' => 1];
        }
        if (in_array($tool, $external, true)) {
            return ['action_class' => 'external', 'requires_explicit_intent' => true, 'approval_policy' => 'standard', 'autonomy_min_level' => 3];
        }
        if (in_array($tool, $mutate, true)) {
            return ['action_class' => 'mutate', 'requires_explicit_intent' => true, 'approval_policy' => 'standard', 'autonomy_min_level' => 2];
        }
        if (in_array($tool, $prepare, true)) {
            return ['action_class' => 'prepare', 'requires_explicit_intent' => false, 'approval_policy' => 'standard', 'autonomy_min_level' => 1];
        }
        if (in_array($tool, $read, true)) {
            return ['action_class' => 'read', 'requires_explicit_intent' => false, 'approval_policy' => 'none', 'autonomy_min_level' => 1];
        }

        return ['action_class' => 'prepare', 'requires_explicit_intent' => true, 'approval_policy' => 'standard', 'autonomy_min_level' => 2];
    }
}
