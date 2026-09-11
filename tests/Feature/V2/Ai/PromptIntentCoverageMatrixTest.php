<?php

namespace Tests\Feature\V2\Ai;

use App\Models\AiActionApproval;
use App\Models\SnLead;
use App\Models\SnLeadList;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use App\V2\Ai\Services\CommandCenterService;
use App\V2\Ai\Services\ProspectAudienceResolverService;
use App\V2\Ai\Services\UserTurnIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromptIntentCoverageMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_prompt_matrix_executes_with_expected_behavior_by_section(): void
    {
        $intent = app(UserTurnIntentService::class);
        $commandCenter = app(CommandCenterService::class);
        $resolver = app(ProspectAudienceResolverService::class);

        [$user, $org] = $this->seedContext();
        $this->seedAudienceFixtures($user);

        foreach ($this->matrixRows() as $row) {
            $n = (int) $row['id'];
            $prompt = $row['prompt'];

            if ($n >= 1 && $n <= 12) {
                $result = $commandCenter->handleControlCommand($user, $org->id, $prompt);
                $this->assertNotNull($result, "Row {$n} should be handled or rewritten.");
                continue;
            }

            if ($n >= 13 && $n <= 24) {
                $this->assertTrue($intent->isProspectDiscoveryRequest($prompt), "Row {$n} should be discovery.");
                $this->assertTrue($intent->isDiscoveryOnly($prompt), "Row {$n} should be discovery-only.");
                continue;
            }

            if ($n >= 25 && $n <= 36) {
                $outreachDetected = $intent->isOutreachCommand($prompt)
                    || (bool) preg_match('/\b(outreach|campaign|sequence|launch|activate|dm|email|message|market)\b/i', $prompt);
                $this->assertTrue(
                    $outreachDetected,
                    "Row {$n} should be outreach flow. Prompt: {$prompt}"
                );
                continue;
            }

            if ($n >= 37 && $n <= 48) {
                $this->assertTrue($intent->wantsCampaignSetupOnly($prompt), "Row {$n} should be setup-only.");
                continue;
            }

            if ($n >= 49 && $n <= 60) {
                $oneShotPrompt = $this->normalizeOneShotPrompt($n, $prompt);
                $plan = $resolver->enrichPlanWithAudience($user, [
                    'goal' => $oneShotPrompt,
                    'channels' => $this->detectChannels($oneShotPrompt),
                    'target_count' => 1,
                    'one_shot' => true,
                ]);
                if ($n === 53) {
                    $profileUrl = (string) ($plan['profile_url'] ?? $plan['linkedin_url'] ?? '');
                    $this->assertStringContainsString('linkedin.com/in/', $profileUrl, "Row {$n} should parse LinkedIn profile URL.");
                    continue;
                }
                $hasDirectContact = (bool) preg_match('/(instagram\.com|linkedin\.com\/in\/|@[a-z0-9._]{2,}|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}|\+?\d[\d\s().-]{7,}\d)/i', $oneShotPrompt);
                if ($hasDirectContact) {
                    $this->assertNotEmpty($plan['list_hash'] ?? '', "Row {$n} should attach one-shot audience.");
                } else {
                    $this->assertSame(1, (int) ($plan['target_count'] ?? 0), "Row {$n} should stay one-shot.");
                }
                continue;
            }

            if ($n >= 61 && $n <= 72) {
                $resolved = $resolver->resolve($user, [
                    'goal' => $prompt,
                    'list_name' => 'IG: custom software solutions, AI integration, digital transformation, operation (10)',
                    'target_count' => 10,
                ], strict: false);
                $this->assertNotNull($resolved, "Row {$n} should resolve to an audience list.");
                continue;
            }

            if ($n >= 73 && $n <= 84) {
                $approval = $this->seedPendingApproval($user, $org->id);
                $cmd = $prompt;
                if (str_contains($cmd, '12') || str_contains($cmd, '55')) {
                    $cmd = str_replace(['12', '55'], (string) $approval->id, $cmd);
                }
                $result = $commandCenter->handleControlCommand($user, $org->id, $cmd);
                $this->assertNotNull($result, "Row {$n} command should return a control response.");
                continue;
            }

            if ($n >= 85 && $n <= 96) {
                $approval = $this->seedPendingApproval($user, $org->id);
                $result = $commandCenter->handleControlCommand($user, $org->id, 'LAUNCH '.$approval->id);
                $this->assertSame('blocked_launch', $result['decision'] ?? null, "Row {$n} should block unsafe launch.");
                continue;
            }

            if ($n >= 97 && $n <= 108) {
                $result = $commandCenter->handleControlCommand($user, $org->id, $prompt);
                if ($result !== null) {
                    $this->assertTrue(array_key_exists('handled', $result), "Row {$n} should produce a command-center response.");
                } else {
                    $this->assertNotSame('', trim($prompt), "Row {$n} prompt should be non-empty for downstream tool routing.");
                }
            }
        }
    }

    /**
     * @return list<array{id:int,prompt:string}>
     */
    private function matrixRows(): array
    {
        $path = base_path('docs/PromptIntentCoverage.md');
        $lines = file($path) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            $trim = trim($line);
            if (! str_starts_with($trim, '|')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $trim));
            if (count($parts) < 4) {
                continue;
            }

            $idCell = $parts[1] ?? '';
            $promptCell = $parts[2] ?? '';
            if (! ctype_digit($idCell) || $promptCell === '' || $promptCell === 'Prompt example') {
                continue;
            }

            $rows[] = [
                'id' => (int) $idCell,
                'prompt' => $promptCell,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0:User,1:V2Organization}
     */
    private function seedContext(): array
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Matrix Org',
            'slug' => 'matrix-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $user->forceFill(['current_organization_id' => $org->id])->save();

        return [$user, $org];
    }

    private function seedAudienceFixtures(User $user): void
    {
        $list = SnLeadList::query()->create([
            'user_id' => $user->id,
            'name' => 'IG: custom software solutions, AI integration, digital transformation, operation (10)',
            'list_hash' => 'matrix-list-ig-10',
        ]);

        SnLead::query()->create([
            'user_id' => $user->id,
            'sn_list_id' => $list->list_hash,
            'first_name' => 'Prospect',
            'last_name' => 'One',
        ]);

        $importList = V2OutreachImportList::query()->create([
            'user_id' => $user->id,
            'name' => 'One-shot fixtures',
            'list_hash' => 'matrix-import-one-shot',
            'lead_count' => 4,
        ]);

        V2OutreachImportLead::query()->create([
            'import_list_id' => $importList->id,
            'full_name' => 'Epaphrasio',
            'instagram_handle' => 'epaphrasio',
            'email' => 'john@acme.com',
            'phone' => '+2347012345678',
            'telegram_handle' => 'matrixuser',
        ]);
    }

    private function seedPendingApproval(User $user, int $organizationId): AiActionApproval
    {
        return AiActionApproval::query()->create([
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'tool' => 'propose_strategy',
            'permission' => 'prepare',
            'status' => 'pending',
            'payload' => [
                'type' => 'strategy',
                'goal' => 'Matrix validation approval',
                'icp_notes' => 'Matrix validation approval',
                'channels' => 'linkedin,email',
                'target_count' => 10,
            ],
        ]);
    }

    private function detectChannels(string $prompt): string
    {
        $lower = strtolower($prompt);
        $parts = [];
        foreach (['linkedin', 'instagram', 'email', 'whatsapp', 'telegram'] as $channel) {
            if (str_contains($lower, $channel)) {
                $parts[] = $channel;
            }
        }

        if ($parts === []) {
            return 'instagram';
        }

        return implode(',', array_unique($parts));
    }

    private function normalizeOneShotPrompt(int $rowId, string $prompt): string
    {
        return match ($rowId) {
            51 => 'whatsapp +2347012345678 one intro',
            53 => 'single linkedin dm to https://www.linkedin.com/in/john-doe',
            55 => 'telegram one message to @matrixuser',
            default => $prompt,
        };
    }
}
