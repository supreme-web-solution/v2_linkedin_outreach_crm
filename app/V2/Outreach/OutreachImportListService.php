<?php

namespace App\V2\Outreach;

use App\Models\User;
use App\Models\V2OutreachImportLead;
use App\Models\V2OutreachImportList;
use App\V2\Integrations\Unipile\UnipileProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OutreachImportListService
{
    public function __construct(
        private readonly OutreachLeadContactResolver $resolver,
        private readonly UnipileProvider $unipile,
        private readonly OutreachSpreadsheetReader $spreadsheetReader,
    ) {}

    public function csvTemplate(): string
    {
        return implode("\n", array_map(
            fn (array $row) => $this->csvLine($row),
            $this->templateRows(),
        ))."\n";
    }

    public function xlsxTemplateResponse(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $this->templateRows();
        $sheet->fromArray($rows);

        $headers = $rows[0] ?? [];
        $phoneColIndex = array_search('phone', $headers, true);
        if ($phoneColIndex !== false) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($phoneColIndex + 1);
            foreach (range(1, count($rows)) as $rowIndex) {
                $sheet->getCell($colLetter.$rowIndex)->getStyle()->getNumberFormat()->setFormatCode('@');
            }
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'outreach-contacts-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return array{list: array<string, mixed>, imported: int, skipped: int}
     */
    public function createFromUploadedFile(User $user, string $listName, UploadedFile $file): array
    {
        $parsed = $this->spreadsheetReader->read($file);

        return $this->createFromRows($user, $listName, $parsed['headers'], $parsed['rows']);
    }

    /**
     * @return array{list: array<string, mixed>, imported: int, skipped: int}
     */
    public function createFromCsv(User $user, string $listName, string $csvContent): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];

        if ($lines === []) {
            throw new \InvalidArgumentException('CSV file is empty.');
        }

        $headers = str_getcsv(array_shift($lines) ?: '');
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = str_getcsv($line);
        }

        return $this->createFromRows($user, $listName, $headers, $rows);
    }

    /**
     * Create an import list from chat-provided contacts (phone, email, handles, LinkedIn URL).
     *
     * @param  list<array<string, mixed>>  $contacts
     * @return array{list: array<string, mixed>, imported: int, skipped: int, suggested_channel: string|null}
     */
    public function createFromContactMaps(User $user, string $listName, array $contacts): array
    {
        $headers = $this->templateHeaders();
        $rows = [];
        $channelHints = [];

        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }

            $map = $this->normalizeContactMap($contact);
            if ($map === null) {
                continue;
            }

            $row = [];
            foreach ($headers as $header) {
                $row[] = $map[$header] ?? '';
            }
            $rows[] = $row;

            foreach (['phone' => 'whatsapp', 'email' => 'email', 'instagram' => 'instagram', 'telegram' => 'telegram', 'twitter' => 'twitter', 'linkedin_url' => 'linkedin'] as $field => $channel) {
                if (trim((string) ($map[$field] ?? '')) !== '') {
                    $channelHints[$channel] = ($channelHints[$channel] ?? 0) + 1;
                }
            }
        }

        if ($rows === []) {
            throw new \InvalidArgumentException(
                'No valid contacts. Provide at least one of: phone, email, linkedin_url, instagram, telegram, twitter.'
            );
        }

        $result = $this->createFromRows($user, $listName, $headers, $rows);
        arsort($channelHints);
        $result['suggested_channel'] = $channelHints !== [] ? array_key_first($channelHints) : null;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, string>|null
     */
    public function normalizeContactMap(array $contact): ?array
    {
        $name = trim((string) (
            $contact['full_name']
            ?? $contact['name']
            ?? $contact['first_name']
            ?? ''
        ));
        if ($name === '' && isset($contact['first_name'], $contact['last_name'])) {
            $name = trim($contact['first_name'].' '.$contact['last_name']);
        }

        $email = trim((string) ($contact['email'] ?? ''));
        $phone = trim((string) ($contact['phone'] ?? $contact['whatsapp'] ?? $contact['mobile'] ?? ''));
        $linkedin = trim((string) ($contact['linkedin_url'] ?? $contact['profile_url'] ?? $contact['linkedin'] ?? ''));
        $instagram = trim((string) ($contact['instagram'] ?? $contact['instagram_handle'] ?? $contact['ig'] ?? ''));
        $telegram = trim((string) ($contact['telegram'] ?? $contact['telegram_handle'] ?? $contact['tg'] ?? ''));
        $twitter = trim((string) ($contact['twitter'] ?? $contact['twitter_handle'] ?? $contact['x'] ?? ''));

        // Allow a single "identifier" field: phone / email / @handle / URL
        $identifier = trim((string) ($contact['identifier'] ?? $contact['value'] ?? $contact['contact'] ?? ''));
        if ($identifier !== '') {
            if (preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $identifier) && $email === '') {
                $email = $identifier;
            } elseif (preg_match('#linkedin\.com/in/#i', $identifier) && $linkedin === '') {
                $linkedin = $identifier;
            } elseif (preg_match('#instagram\.com/([\w.]+)#i', $identifier, $m) && $instagram === '') {
                $instagram = $m[1];
            } elseif (preg_match('/^\+?[\d\s\-().]{7,}$/', $identifier) && $phone === '') {
                $phone = preg_replace('/[^\d+]/', '', $identifier) ?? $identifier;
            } elseif (str_starts_with($identifier, '@') || preg_match('/^[\w.]{2,30}$/', $identifier)) {
                $handle = ltrim($identifier, '@');
                $hint = Str::lower((string) ($contact['platform'] ?? $contact['channel'] ?? ''));
                if (str_contains($hint, 'telegram') && $telegram === '') {
                    $telegram = $handle;
                } elseif ((str_contains($hint, 'twitter') || str_contains($hint, 'x.com') || $hint === 'x') && $twitter === '') {
                    $twitter = $handle;
                } elseif ($instagram === '' && $telegram === '' && $twitter === '') {
                    $instagram = $handle;
                }
            }
        }

        $instagram = ltrim($instagram, '@');
        $telegram = ltrim($telegram, '@');
        $twitter = ltrim($twitter, '@');

        if ($name === '' && $email === '' && $phone === '' && $linkedin === '' && $instagram === '' && $telegram === '' && $twitter === '') {
            return null;
        }

        if ($name === '') {
            $name = $email !== '' ? Str::before($email, '@')
                : ($instagram !== '' ? $instagram
                    : ($telegram !== '' ? $telegram
                        : ($twitter !== '' ? $twitter
                            : ($phone !== '' ? 'Phone contact' : 'Contact'))));
        }

        return [
            'full_name' => $name,
            'email' => $email,
            'phone' => $phone,
            'linkedin_url' => $linkedin,
            'instagram' => $instagram,
            'telegram' => $telegram,
            'twitter' => $twitter,
        ];
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{list: array<string, mixed>, imported: int, skipped: int}
     */
    public function createFromRows(User $user, string $listName, array $headers, array $rows): array
    {
        $listName = trim($listName) !== '' ? trim($listName) : 'Imported contacts';
        $headerMap = $this->mapHeaders($headers);

        if ($headerMap === []) {
            throw new \InvalidArgumentException('Spreadsheet must include a header row with column names.');
        }

        if ($rows === []) {
            throw new \InvalidArgumentException('Spreadsheet has no data rows.');
        }

        $importList = V2OutreachImportList::create([
            'user_id' => $user->id,
            'organization_id' => $user->current_organization_id,
            'list_hash' => 'imp-'.Str::lower(Str::random(16)),
            'name' => $listName,
            'lead_count' => 0,
        ]);

        $imported = 0;
        $skipped = 0;

        foreach ($rows as $cols) {
            $parsed = $this->parseRow($cols, $headerMap);
            if (! $this->rowHasContactData($parsed)) {
                $skipped++;
                continue;
            }

            $linkedinId = $this->resolver->normalizeLinkedinKey(
                $parsed['linkedin_url'] ?? $parsed['linkedin_id'] ?? $parsed['linkedin'] ?? ''
            );

            V2OutreachImportLead::create([
                'import_list_id' => $importList->id,
                'full_name' => $parsed['full_name'] ?? $parsed['name'] ?? null,
                'email' => OutreachChannelRegistry::isEnabled('email')
                    ? ($parsed['email'] ?? null)
                    : null,
                'phone' => OutreachChannelRegistry::isEnabled('whatsapp') && isset($parsed['phone'])
                    ? $this->unipile->normalizePhone($parsed['phone'])
                    : null,
                'linkedin_id' => OutreachChannelRegistry::isEnabled('linkedin') && $linkedinId !== ''
                    ? $linkedinId
                    : null,
                'profile_url' => OutreachChannelRegistry::isEnabled('linkedin') && $linkedinId !== ''
                    ? 'https://www.linkedin.com/in/'.$linkedinId
                    : (OutreachChannelRegistry::isEnabled('linkedin') ? ($parsed['linkedin_url'] ?? null) : null),
                'instagram_handle' => OutreachChannelRegistry::isEnabled('instagram')
                    ? $this->cleanHandle($parsed['instagram'] ?? $parsed['instagram_handle'] ?? null)
                    : null,
                'telegram_handle' => OutreachChannelRegistry::isEnabled('telegram')
                    ? $this->cleanHandle($parsed['telegram'] ?? $parsed['telegram_handle'] ?? null)
                    : null,
                'twitter_handle' => OutreachChannelRegistry::isEnabled('twitter')
                    ? $this->cleanHandle($parsed['twitter'] ?? $parsed['twitter_handle'] ?? $parsed['x'] ?? null)
                    : null,
            ]);

            $imported++;
        }

        if ($imported === 0) {
            $importList->delete();
            throw new \InvalidArgumentException(
                'No valid rows found. Each row needs at least one of: '.$this->requiredContactColumnsLabel().'.'
            );
        }

        $importList->update(['lead_count' => $imported]);

        return [
            'list' => $this->toListOption($importList->fresh()),
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function templateRows(): array
    {
        $headers = $this->templateHeaders();
        $samples = [
            [
                'full_name' => 'John Doe',
                'email' => 'john@example.com',
                'phone' => '33612345678',
                'linkedin_url' => 'https://www.linkedin.com/in/johndoe',
                'instagram' => 'johndoe',
                'telegram' => 'johndoe_tg',
                'twitter' => 'johndoe_x',
            ],
            [
                'full_name' => 'Jane Smith',
                'email' => 'jane@company.com',
                'phone' => '33698765432',
                'linkedin_url' => '',
                'instagram' => 'janesmith',
                'telegram' => '',
                'twitter' => '',
            ],
            [
                'full_name' => 'WhatsApp Lead',
                'email' => '',
                'phone' => '33611112222',
                'linkedin_url' => '',
                'instagram' => '',
                'telegram' => '',
                'twitter' => '',
            ],
            [
                'full_name' => 'Email Only',
                'email' => 'prospect@email.com',
                'phone' => '',
                'linkedin_url' => '',
                'instagram' => '',
                'telegram' => '',
                'twitter' => '',
            ],
        ];

        $rows = [$headers];
        foreach ($samples as $sample) {
            $rows[] = array_map(
                fn (string $header) => (string) ($sample[$header] ?? ''),
                $headers,
            );
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    public function templateHeaders(): array
    {
        $headers = ['full_name'];

        if (OutreachChannelRegistry::isEnabled('email')) {
            $headers[] = 'email';
        }
        if (OutreachChannelRegistry::isEnabled('whatsapp')) {
            $headers[] = 'phone';
        }
        if (OutreachChannelRegistry::isEnabled('linkedin')) {
            $headers[] = 'linkedin_url';
        }
        if (OutreachChannelRegistry::isEnabled('instagram')) {
            $headers[] = 'instagram';
        }
        if (OutreachChannelRegistry::isEnabled('telegram')) {
            $headers[] = 'telegram';
        }
        if (OutreachChannelRegistry::isEnabled('twitter')) {
            $headers[] = 'twitter';
        }

        return $headers;
    }

    /**
     * @return array<int, string>
     */
    private function enabledContactColumnKeys(): array
    {
        $keys = [];

        if (OutreachChannelRegistry::isEnabled('email')) {
            $keys[] = 'email';
        }
        if (OutreachChannelRegistry::isEnabled('whatsapp')) {
            $keys[] = 'phone';
        }
        if (OutreachChannelRegistry::isEnabled('linkedin')) {
            array_push($keys, 'linkedin_url', 'linkedin_id', 'linkedin');
        }
        if (OutreachChannelRegistry::isEnabled('instagram')) {
            array_push($keys, 'instagram', 'instagram_handle');
        }
        if (OutreachChannelRegistry::isEnabled('telegram')) {
            array_push($keys, 'telegram', 'telegram_handle');
        }
        if (OutreachChannelRegistry::isEnabled('twitter')) {
            array_push($keys, 'twitter', 'twitter_handle', 'x');
        }

        return $keys;
    }

    private function requiredContactColumnsLabel(): string
    {
        $labels = array_values(array_filter([
            OutreachChannelRegistry::isEnabled('email') ? 'email' : null,
            OutreachChannelRegistry::isEnabled('whatsapp') ? 'phone' : null,
            OutreachChannelRegistry::isEnabled('linkedin') ? 'linkedin_url' : null,
            OutreachChannelRegistry::isEnabled('instagram') ? 'instagram' : null,
            OutreachChannelRegistry::isEnabled('telegram') ? 'telegram' : null,
            OutreachChannelRegistry::isEnabled('twitter') ? 'twitter' : null,
        ]));

        if ($labels === []) {
            return 'a contact field';
        }

        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).', or '.$last;
    }

    /**
     * @param  array<int, string>  $cols
     */
    private function csvLine(array $cols): string
    {
        return implode(',', array_map(function (string $value): string {
            if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
                return '"'.str_replace('"', '""', $value).'"';
            }

            return $value;
        }, $cols));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listsForUser(int $userId): array
    {
        return V2OutreachImportList::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (V2OutreachImportList $list) => $this->toListOption($list))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toListOption(V2OutreachImportList $list): array
    {
        return [
            'id' => $list->id,
            'list_name' => $list->name,
            'list_hash' => $list->list_hash,
            'total_leads' => (int) $list->lead_count,
            'source' => 'Spreadsheet import',
            'src' => 'csv',
            'type' => $list->list_hash.'-csv',
            'created_at' => optional($list->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $parsed
     */
    private function rowHasContactData(array $parsed): bool
    {
        foreach ($this->enabledContactColumnKeys() as $field) {
            if (trim((string) ($parsed[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int>
     */
    private function mapHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $key = strtolower(trim(preg_replace('/[^a-z0-9_]+/i', '_', (string) $header) ?? '', '_'));
            if ($key !== '') {
                $map[$key] = $index;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, mixed>  $cols
     * @param  array<string, int>  $headerMap
     * @return array<string, string>
     */
    private function parseRow(array $cols, array $headerMap): array
    {
        $row = [];
        foreach ($headerMap as $key => $index) {
            $value = $cols[$index] ?? '';
            $row[$key] = is_scalar($value) || $value === null ? trim((string) $value) : '';
        }

        return $row;
    }

    private function cleanHandle(?string $value): ?string
    {
        $value = ltrim(trim((string) $value), '@');

        return $value !== '' ? $value : null;
    }
}
