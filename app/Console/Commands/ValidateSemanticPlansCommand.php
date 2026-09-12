<?php

namespace App\Console\Commands;

use App\Models\AiEmployeeSetting;
use App\Models\User;
use App\V2\Ai\Services\ToolPolicyGateService;
use App\V2\Ai\Services\TurnPlanBuilderService;
use Illuminate\Console\Command;

class ValidateSemanticPlansCommand extends Command
{
    protected $signature = 'ai:validate-semantic-plans {--user=1} {--org=1} {--runs=1}';

    protected $description = 'Live semantic planner validation against milestone test prompts';

    /** @var list<string> */
    private array $mutationTools = [
        'discover_prospects',
        'save_contacts',
        'draft_campaign_plan',
        'activate_outreach_campaign',
        'delete_campaign',
    ];

    public function handle(
        TurnPlanBuilderService $builder,
        ToolPolicyGateService $gate,
    ): int {
        $user = User::find((int) $this->option('user'));
        if (! $user) {
            $this->error('User not found');

            return self::FAILURE;
        }

        $orgId = (int) $this->option('org');
        $settings = AiEmployeeSetting::query()
            ->where('user_id', $user->id)
            ->first() ?? new AiEmployeeSetting(['user_id' => $user->id]);

        $prompts = [
            'discovery_fifty' => 'I need a list of fifty SaaS founders.',
            'discovery_identify' => 'Can you identify 50 SaaS startup founders?',
            'reuse_outreach' => 'Use the SaaS founders we already have and reach out to them.',
            'new_only_outreach' => 'Find 100 NEW SaaS founders and contact them.',
            'compound_whatsapp' => 'I want to build a list of companies we could sell our service to, but don\'t bother me with anyone we\'ve already approached. Focus on businesses where we can actually reach a decision maker on WhatsApp, and get everything ready so we can start tomorrow.',
            'readonly_campaigns' => 'What campaigns did you create today?',
            'readonly_whatsapp' => 'Which prospects have WhatsApp?',
            'readonly_saas' => 'Show me our SaaS prospects.',
            'contextual_identify' => 'Identify which of our prospects have WhatsApp.',
        ];

        $runs = max(1, (int) $this->option('runs'));

        for ($run = 1; $run <= $runs; $run++) {
            if ($runs > 1) {
                $this->info("========== RUN {$run}/{$runs} ==========");
                $this->newLine();
            }

            $this->info('Semantic planner live validation');
            $this->line('User: '.$user->id.' | Org: '.$orgId);
            $this->newLine();

            foreach ($prompts as $key => $message) {
                $this->info("=== {$key} ===");
                $this->line('Prompt: '.$message);
                $this->newLine();

                $plan = $builder->build($user, $orgId, $message, $settings);

                if (($plan['semantic_source'] ?? '') === 'regex_fallback') {
                    $this->warn('Semantic LLM: FALLBACK');
                } elseif (isset($plan['semantic'])) {
                    $this->line('Semantic (LLM):');
                    $this->line(json_encode($plan['semantic'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }

                $this->newLine();
                $this->line('Enforcement plan:');
                $this->line(json_encode([
                    'semantic_source' => $plan['semantic_source'] ?? null,
                    'planning_degraded' => $plan['planning_degraded'] ?? false,
                    'goal' => $plan['goal'] ?? null,
                    'required_outcome' => $plan['required_outcome'] ?? null,
                    'side_effect_budget' => $plan['side_effect_budget'] ?? null,
                    'constraints' => $plan['constraints'] ?? [],
                    'measurable_expectations' => $plan['measurable_expectations'] ?? [],
                    'state_evaluation' => isset($plan['state_evaluation']['prompt_summary'])
                        ? $plan['state_evaluation']['prompt_summary']
                        : ($plan['state_evaluation'] ?? null),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $this->newLine();
                $this->line('Policy gate (mutation tools):');
                foreach ($this->mutationTools as $tool) {
                    $check = $gate->check($tool, $plan);
                    $status = $check['allowed'] ? 'ALLOW' : 'BLOCK';
                    $reason = $check['reason'] ?? '';
                    $this->line("  {$tool}: {$status}".($reason ? " — {$reason}" : ''));
                }

                $this->newLine();
            }

            if ($run < $runs) {
                $this->newLine();
            }
        }

        return self::SUCCESS;
    }
}
