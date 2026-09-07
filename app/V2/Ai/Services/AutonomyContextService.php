<?php

namespace App\V2\Ai\Services;

use App\Models\AiConversation;
use App\Models\AiEmployeeSetting;
use App\V2\Ai\Enums\AiAutonomyLevel;

class AutonomyContextService
{
    public function label(int $level): string
    {
        return match ($level) {
            AiAutonomyLevel::Copilot->value => 'Copilot',
            AiAutonomyLevel::Assisted->value => 'Assisted',
            AiAutonomyLevel::Autopilot->value => 'Autopilot',
            AiAutonomyLevel::Autonomous->value => 'Autonomous',
            default => 'Assisted',
        };
    }

    /**
     * Track mode on the conversation and return a note when the user switched modes.
     */
    public function syncConversationMode(AiConversation $conversation, AiEmployeeSetting $settings): ?string
    {
        $current = (int) $settings->autonomy_level;
        $meta = is_array($conversation->meta) ? $conversation->meta : [];
        $previous = array_key_exists('autonomy_level', $meta) ? (int) $meta['autonomy_level'] : null;

        if ($previous !== null && $previous !== $current) {
            $note = sprintf(
                'User switched autonomy from %s to %s. Apply %s rules on this message only.',
                $this->label($previous),
                $this->label($current),
                $this->label($current),
            );
            $conversation->forceFill([
                'meta' => array_merge($meta, ['autonomy_level' => $current]),
            ])->save();

            return $note;
        }

        if ($previous !== $current) {
            $conversation->forceFill([
                'meta' => array_merge($meta, ['autonomy_level' => $current]),
            ])->save();
        }

        return null;
    }

    public function promptPrefix(AiEmployeeSetting $settings, ?string $modeChangeNote = null): string
    {
        $level = AiAutonomyLevel::tryFrom((int) $settings->autonomy_level) ?? AiAutonomyLevel::Assisted;
        $label = $this->label($level->value);

        $lines = [
            "[ACTIVE AUTONOMY FOR THIS MESSAGE: {$label} (level {$level->value})]",
            'Shared chat history may reflect an older mode — ignore prior tone if it conflicts with the rules below.',
        ];

        if ($modeChangeNote !== null) {
            $lines[] = $modeChangeNote;
        }

        $lines[] = match ($level) {
            AiAutonomyLevel::Copilot => 'COPILOT: Recommend only. Do NOT call propose_strategy or draft_campaign_plan (no Review & Launch). Do not claim anything was executed.',
            AiAutonomyLevel::Assisted => 'ASSISTED: For campaign/meeting goals call propose_strategy. Content: list_content_posts, prepare_linkedin_post, reschedule_content_posts — user approves via Review & Launch or LAUNCH {id}.',
            AiAutonomyLevel::Autopilot => 'AUTOPILOT: MUST call propose_strategy for campaign goals. Content reschedule and scheduled posts apply immediately — use list_content_posts + reschedule_content_posts. WhatsApp image+caption → prepare_linkedin_post with stored image.',
            AiAutonomyLevel::Autonomous => 'AUTONOMOUS: Full Content access — list_content_posts, prepare_linkedin_post, reschedule_content_posts run without asking user to open /content. WhatsApp image+caption → prepare_linkedin_post automatically.',
        };

        return implode("\n", $lines)."\n\n";
    }
}
