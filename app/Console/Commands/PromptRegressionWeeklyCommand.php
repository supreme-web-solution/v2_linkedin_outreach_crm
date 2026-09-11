<?php

namespace App\Console\Commands;

use App\Models\AiActionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class PromptRegressionWeeklyCommand extends Command
{
    protected $signature = 'ai:prompt-regression-weekly {--days=7 : Lookback window in days} {--limit=40 : Max prompts to include}';

    protected $description = 'Generate weekly prompt-expansion candidates from ambiguous/unknown prompt events.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $since = Carbon::now()->subDays($days);

        $rows = AiActionLog::query()
            ->where('tool', 'prompt_observer')
            ->whereIn('status', [
                'unknown_pre_llm',
                'ambiguous_short',
                'ambiguous_empty',
                'ambiguous_no_pending_action',
                'fallback_clarifier',
            ])
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(2000)
            ->get();

        $grouped = $this->groupCandidates($rows)->take($limit);
        $path = base_path('docs/WeeklyPromptExpansion.md');

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->renderReport($days, $grouped));

        $this->info('Prompt expansion report generated: '.$path);
        $this->line('Candidates: '.$grouped->count());

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, AiActionLog>  $rows
     * @return Collection<int, array{prompt:string,count:int,last_seen:string,statuses:list<string>}>
     */
    private function groupCandidates(Collection $rows): Collection
    {
        /** @var Collection<int, array{prompt:string,count:int,last_seen:string,statuses:list<string>}> $grouped */
        $grouped = $rows
            ->map(function (AiActionLog $log): array {
                $input = is_array($log->input) ? $log->input : [];
                $prompt = trim((string) ($input['message'] ?? ''));

                return [
                    'prompt' => $prompt,
                    'status' => (string) $log->status,
                    'created_at' => (string) $log->created_at?->toIso8601String(),
                ];
            })
            ->filter(fn (array $row) => $row['prompt'] !== '')
            ->groupBy(fn (array $row) => mb_strtolower($row['prompt']))
            ->map(function (Collection $items): array {
                $first = $items->first();

                return [
                    'prompt' => (string) ($first['prompt'] ?? ''),
                    'count' => $items->count(),
                    'last_seen' => (string) $items->max('created_at'),
                    'statuses' => array_values(array_unique(
                        $items->pluck('status')->filter()->values()->all()
                    )),
                ];
            })
            ->sortByDesc('count')
            ->values();

        return $grouped;
    }

    /**
     * @param  Collection<int, array{prompt:string,count:int,last_seen:string,statuses:list<string>}>  $rows
     */
    private function renderReport(int $days, Collection $rows): string
    {
        $lines = [
            '# Weekly Prompt Expansion',
            '',
            'Generated: '.Carbon::now()->toDateTimeString(),
            'Window: last '.$days.' day(s)',
            'Source: `ai_action_logs` where `tool = prompt_observer` and status indicates unknown/ambiguous/fallback.',
            '',
            '## Candidate prompts to add into regression matrix',
            '',
            '| # | Prompt | Count | Last seen | Statuses |',
            '|---:|---|---:|---|---|',
        ];

        if ($rows->isEmpty()) {
            $lines[] = '| 1 | _No misses captured in this window_ | 0 | - | - |';
        } else {
            foreach ($rows->values() as $idx => $row) {
                $statuses = implode(', ', $row['statuses']);
                $prompt = str_replace('|', '\|', $row['prompt']);
                $lines[] = '| '.($idx + 1).' | '.$prompt.' | '.$row['count'].' | '.$row['last_seen'].' | '.$statuses.' |';
            }
        }

        $lines[] = '';
        $lines[] = '## Suggested weekly actions';
        $lines[] = '';
        $lines[] = '1. Add top 10 prompts into `docs/PromptIntentCoverage.md`.';
        $lines[] = '2. Add corresponding assertions into `tests/Feature/V2/Ai/PromptIntentCoverageMatrixTest.php`.';
        $lines[] = '3. Run `php vendor/bin/phpunit tests/Feature/V2/Ai/PromptIntentCoverageMatrixTest.php`.';

        return implode("\n", $lines)."\n";
    }
}
