<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\V2\Outreach\OutreachImportListService;
use App\V2\Ai\Support\PlanLeadList;

class ImportLeadsCsvFromPlanService
{
    /**
     * @return array{message:string, list:array<string,mixed>, imported:int, skipped:int}
     */
    public function importFromApproval(AiActionApproval $approval, User $user): array
    {
        $existingList = data_get($approval->result, 'list.list_hash');
        if (is_string($existingList) && $existingList !== '') {
            return [
                'message' => 'CSV import already completed for this plan.',
                'list' => (array) data_get($approval->result, 'list', []),
                'imported' => (int) data_get($approval->result, 'imported', 0),
                'skipped' => (int) data_get($approval->result, 'skipped', 0),
            ];
        }

        $payload = $approval->payload ?? [];
        $listName = trim((string) ($payload['list_name'] ?? ''));
        $csvContent = (string) ($payload['csv_content'] ?? '');

        if ($listName === '' || trim($csvContent) === '') {
            throw new \InvalidArgumentException('Missing CSV content or list name.');
        }

        $result = app(OutreachImportListService::class)->createFromCsv($user, $listName, $csvContent);
        $list = $result['list'];

        $approval->update([
            'result' => [
                'list' => $list,
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'status' => 'imported',
            ],
            'payload' => PlanLeadList::merge($payload, $list['list_hash'], 'csv', $listName),
            'status' => 'executed',
        ]);

        return [
            'message' => "Imported {$result['imported']} contact(s) into \"{$listName}\".",
            'list' => $list,
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
        ];
    }
}
