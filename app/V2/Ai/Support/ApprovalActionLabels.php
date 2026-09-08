<?php

namespace App\V2\Ai\Support;

/**
 * Short, intent-based labels for approval actions (WhatsApp buttons + web UI).
 * Plan IDs stay in payloads / APIs — never in user-facing button titles.
 */
final class ApprovalActionLabels
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{
     *     approve: string,
     *     reject: string,
     *     preview: string,
     *     show_preview: bool,
     *     summary: string
     * }
     */
    public static function for(?string $tool, ?array $payload = null): array
    {
        $type = is_array($payload) ? (string) ($payload['type'] ?? '') : '';
        $tool = (string) ($tool ?? '');

        $approve = match (true) {
            self::isDelete($tool, $type) => 'Confirm Delete',
            self::isLinkedInPost($tool, $type) => 'Publish',
            self::isDraftReply($tool, $type) => 'Send',
            self::isPersonalizedMessage($tool, $type) => 'Save',
            self::isCsvImport($tool, $type) => 'Import',
            self::isEnrichment($tool, $type) => 'Enrich',
            self::isIcp($tool, $type) => 'Save ICP',
            default => 'Launch',
        };

        $reject = match (true) {
            self::isDelete($tool, $type) => 'Keep',
            self::isLinkedInPost($tool, $type) => 'Discard',
            default => 'Reject',
        };

        $showPreview = self::isLinkedInPost($tool, $type)
            || in_array($type, ['strategy', 'campaign', 'campaign_delete'], true)
            || in_array($tool, ['propose_strategy', 'draft_campaign_plan', 'delete_campaign'], true);

        $summary = match (true) {
            self::isDelete($tool, $type) => 'Delete requires your confirmation',
            self::isLinkedInPost($tool, $type) => 'LinkedIn post ready',
            self::isDraftReply($tool, $type) => 'Reply draft ready',
            self::isPersonalizedMessage($tool, $type) => 'Message draft ready',
            self::isIcp($tool, $type) => 'ICP plan ready',
            in_array($type, ['strategy', 'campaign'], true)
                || in_array($tool, ['propose_strategy', 'draft_campaign_plan'], true) => 'Outreach plan ready',
            default => 'Action ready to confirm',
        };

        return [
            'approve' => $approve,
            'reject' => $reject,
            'preview' => 'Preview',
            'show_preview' => $showPreview,
            'summary' => $summary,
        ];
    }

    private static function isLinkedInPost(string $tool, string $type): bool
    {
        return $tool === 'prepare_linkedin_post'
            || $type === 'linkedin_post'
            || str_contains($tool, 'linkedin_post');
    }

    private static function isDraftReply(string $tool, string $type): bool
    {
        return $tool === 'draft_reply' || $type === 'draft_reply';
    }

    private static function isPersonalizedMessage(string $tool, string $type): bool
    {
        return $tool === 'draft_personalized_message' || $type === 'personalized_message';
    }

    private static function isCsvImport(string $tool, string $type): bool
    {
        return $tool === 'import_leads_csv' || $type === 'csv_import';
    }

    private static function isEnrichment(string $tool, string $type): bool
    {
        return $tool === 'enrich_leads' || $type === 'enrichment';
    }

    private static function isIcp(string $tool, string $type): bool
    {
        return $tool === 'prepare_icp' || $type === 'icp' || str_contains($tool, 'icp');
    }

    private static function isDelete(string $tool, string $type): bool
    {
        return $tool === 'delete_campaign'
            || $type === 'campaign_delete'
            || str_starts_with($tool, 'delete_');
    }
}
