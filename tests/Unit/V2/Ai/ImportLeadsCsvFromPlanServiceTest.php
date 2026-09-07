<?php

namespace Tests\Unit\V2\Ai;

use App\Models\AiActionApproval;
use App\Models\User;
use App\Models\V2Organization;
use App\Models\V2OrganizationUser;
use App\V2\Ai\Services\ImportLeadsCsvFromPlanService;
use App\V2\Outreach\OutreachImportListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportLeadsCsvFromPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_csv_on_launch(): void
    {
        $user = User::factory()->create();
        $org = V2Organization::query()->create([
            'name' => 'Import Org',
            'slug' => 'import-org-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        $user->forceFill(['current_organization_id' => $org->id])->save();
        V2OrganizationUser::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $headers = app(OutreachImportListService::class)->templateHeaders();
        $row = array_fill(0, count($headers), '');
        $row[array_search('full_name', $headers, true)] = 'Jane Doe';
        if (($emailIndex = array_search('email', $headers, true)) !== false) {
            $row[$emailIndex] = 'jane@example.com';
        }
        $csv = implode(',', $headers)."\n".implode(',', $row)."\n";

        $approval = AiActionApproval::query()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'tool' => 'import_leads_csv',
            'permission' => 'prepare',
            'status' => 'pending',
            'payload' => [
                'type' => 'csv_import',
                'list_name' => 'Test import',
                'csv_content' => $csv,
            ],
        ]);

        $result = app(ImportLeadsCsvFromPlanService::class)->importFromApproval($approval, $user);

        $this->assertSame(1, $result['imported']);
        $this->assertNotEmpty($result['list']['list_hash']);
        $this->assertSame('executed', $approval->fresh()->status);
        $this->assertSame('csv', $approval->fresh()->payload['list_src']);
    }
}
