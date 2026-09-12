<?php

namespace App\Ai\Tools;

use App\Models\V2OutreachLead;
use App\V2\Ai\Enums\AiToolPermission;
use App\V2\Ai\Services\CommandCenterResearchService;
use App\V2\Ai\Services\ProspectResearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ResearchProspectTool extends GatedTool
{
    public function toolName(): string
    {
        return 'research_prospect';
    }

    public function permission(): AiToolPermission
    {
        return AiToolPermission::Read;
    }

    public function description(): Stringable|string
    {
        return 'Scrape a prospect bio/website link and store prospect_intelligence for personalized messages. '
            .'Runs automatically when the user pastes a URL in Command Center; call explicitly to persist on an outreach lead.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'outreach_lead_id' => $schema->integer()->nullable()->description('Outreach lead to research and persist'),
            'profile_url' => $schema->string()->nullable()->description('LinkedIn or website URL when no lead id'),
            'headline' => $schema->string()->nullable(),
            'about' => $schema->string()->nullable()->description('Bio / about text'),
            'company' => $schema->string()->nullable(),
            'scrape_url' => $schema->string()->nullable()->description('Optional explicit URL to scrape'),
        ];
    }

    protected function run(Request $request): array
    {
        $leadId = isset($request['outreach_lead_id']) ? (int) $request['outreach_lead_id'] : null;

        if ($leadId) {
            $lead = V2OutreachLead::query()
                ->whereKey($leadId)
                ->whereHas('campaign', fn ($q) => $q->where('user_id', $this->context->user->id))
                ->firstOrFail();

            $intel = app(ProspectResearchService::class)->researchLead(
                $lead,
                isset($request['scrape_url']) ? (string) $request['scrape_url'] : null,
            );

            return [
                'outreach_lead_id' => $lead->id,
                'prospect_intelligence' => $intel,
                'message' => 'Prospect research saved on lead '.$lead->id.'. Use signals for a conversation-first opening (question only — no pitch).',
            ];
        }

        $url = trim((string) ($request['scrape_url'] ?? $request['profile_url'] ?? ''));
        $intel = app(ProspectResearchService::class)->research([
            'profile_url' => $request['profile_url'] ?? null,
            'headline' => $request['headline'] ?? null,
            'about' => $request['about'] ?? null,
            'company' => $request['company'] ?? null,
            'scrape_url' => $request['scrape_url'] ?? null,
        ]);

        $researchService = app(CommandCenterResearchService::class);
        $snapshot = $researchService->snapshotFromIntel($url !== '' ? $url : 'manual', $intel);
        $researchService->persistSnapshots($this->context->conversation, [$snapshot]);

        return [
            'prospect_intelligence' => $intel,
            'conversation_research' => $snapshot,
            'message' => 'Research complete and saved on this Command Center conversation. Summarize signals and offer draft_personalized_message if outreach is next.',
        ];
    }
}
