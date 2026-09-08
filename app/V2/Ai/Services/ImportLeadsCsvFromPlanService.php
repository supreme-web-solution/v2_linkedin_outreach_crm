<?php

namespace App\V2\Ai\Services;

use App\Models\AiActionApproval;
use App\Models\User;
use App\V2\Ai\Support\PlanLeadList;
use App\V2\Outreach\OutreachImportListService;

class ImportLeadsCsvFromPlanService
{
    /**
     * @return array{message:string, list:array<string,mixed>, imported:int, skipped:int, suggested_channel?:string|null}
     */
    public function importFromApproval(AiActionApproval $approval, User $user): array
    {
        $existingList = data_get($approval->result, 'list.list_hash');
        if (is_string($existingList) && $existingList !== '') {
            return [
                'message' => 'Contact import already completed for this plan.',
                'list' => (array) data_get($approval->result, 'list', []),
                'imported' => (int) data_get($approval->result, 'imported', 0),
                'skipped' => (int) data_get($approval->result, 'skipped', 0),
                'suggested_channel' => data_get($approval->result, 'suggested_channel'),
            ];
        }

        $payload = $approval->payload ?? [];
        $listName = trim((string) ($payload['list_name'] ?? ''));
        $csvContent = (string) ($payload['csv_content'] ?? '');
        $contacts = $payload['contacts'] ?? null;

        if ($listName === '') {
            throw new \InvalidArgumentException('Missing list name.');
        }

        if (is_array($contacts) && $contacts !== []) {
            $result = $this->importContactsNow($user, $listName, $contacts);
        } elseif (trim($csvContent) !== '') {
            $imported = app(OutreachImportListService::class)->createFromCsv($user, $listName, $csvContent);
            $result = [
                'list' => $imported['list'],
                'imported' => $imported['imported'],
                'skipped' => $imported['skipped'],
                'suggested_channel' => null,
            ];
        } else {
            throw new \InvalidArgumentException('Missing CSV content or contacts.');
        }

        $list = $result['list'];

        $approval->update([
            'result' => [
                'list' => $list,
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'suggested_channel' => $result['suggested_channel'] ?? null,
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
            'suggested_channel' => $result['suggested_channel'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $contacts
     * @return array{list:array<string,mixed>, imported:int, skipped:int, suggested_channel:string|null}
     */
    public function importContactsNow(User $user, string $listName, array $contacts): array
    {
        $result = app(OutreachImportListService::class)->createFromContactMaps($user, $listName, $contacts);

        return [
            'list' => $result['list'],
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
            'suggested_channel' => $result['suggested_channel'] ?? null,
        ];
    }
}
