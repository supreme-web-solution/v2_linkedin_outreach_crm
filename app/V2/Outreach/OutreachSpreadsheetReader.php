<?php

namespace App\V2\Outreach;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class OutreachSpreadsheetReader
{
    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function read(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            throw new \InvalidArgumentException('Could not read the uploaded file.');
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, false);

        if ($allRows === []) {
            throw new \InvalidArgumentException('Spreadsheet is empty.');
        }

        $headers = array_map(fn (mixed $cell) => $this->cellToString($cell), array_shift($allRows) ?: []);
        $rows = [];

        foreach ($allRows as $row) {
            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $rows[] = array_map(fn (mixed $cell) => $this->cellToString($cell), $row);
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if ($this->cellToString($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cellToString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (floor($value) == $value) {
                return sprintf('%.0f', $value);
            }

            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return trim((string) $value);
    }
}
