<?php

namespace App\Services;

use App\Models\Event;
use App\Models\VolunteerPerson;
use App\Support\GermanMobileNumber;
use Illuminate\Support\Facades\Validator;

class VolunteerPersonImportService
{
    public const MAX_ROWS = 500;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     created: int,
     *     skipped: int,
     *     errors: list<array{row: int, email: ?string, message: string}>,
     *     results: list<array{row: int, email: ?string, action: string, message?: string}>
     * }
     */
    public function import(Event $event, array $rows, bool $dryRun = true): array
    {
        if (count($rows) > self::MAX_ROWS) {
            return [
                'created' => 0,
                'skipped' => 0,
                'errors' => [[
                    'row' => 0,
                    'email' => null,
                    'message' => 'Maximal '.self::MAX_ROWS.' Zeilen pro Import.',
                ]],
                'results' => [],
            ];
        }

        $results = [];
        $errors = [];
        $created = 0;
        $toInsert = [];

        foreach ($rows as $index => $rawRow) {
            $rowNumber = $index + 1;
            $normalized = $this->normalizeRow($rawRow);
            $validation = $this->validateRow($normalized);

            if ($validation['error'] !== null) {
                $errors[] = [
                    'row' => $rowNumber,
                    'email' => $normalized['email'],
                    'message' => $validation['error'],
                ];
                $results[] = [
                    'row' => $rowNumber,
                    'email' => $normalized['email'],
                    'action' => 'error',
                    'message' => $validation['error'],
                ];
                continue;
            }

            $created++;
            $results[] = [
                'row' => $rowNumber,
                'email' => $normalized['email'],
                'action' => 'create',
            ];

            $toInsert[] = [
                'regional_partner' => $event->regional_partner,
                'first_name' => $normalized['first_name'],
                'last_name' => $normalized['last_name'],
                'email' => $normalized['email'],
                'mobile' => $validation['mobile'],
                'organization' => $normalized['organization'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! $dryRun && $toInsert !== []) {
            VolunteerPerson::query()->insert($toInsert);
        }

        return [
            'created' => $created,
            'skipped' => 0,
            'errors' => $errors,
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{first_name: string, last_name: string, email: ?string, mobile: ?string, organization: ?string}
     */
    private function normalizeRow(array $row): array
    {
        $email = $this->nullableTrim($row['email'] ?? null);

        return [
            'first_name' => trim((string) ($row['first_name'] ?? '')),
            'last_name' => trim((string) ($row['last_name'] ?? '')),
            'email' => $email !== null ? strtolower($email) : null,
            'mobile' => $this->nullableTrim($row['mobile'] ?? null),
            'organization' => $this->nullableTrim($row['organization'] ?? null),
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array{first_name: string, last_name: string, email: ?string, mobile: ?string, organization: ?string}  $row
     * @return array{error: ?string, mobile: ?string}
     */
    private function validateRow(array $row): array
    {
        $validator = Validator::make($row, [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => [
                'nullable',
                'email',
                'max:255',
            ],
            'mobile' => 'nullable|string|max:50',
            'organization' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return [
                'error' => $validator->errors()->first() ?? 'Ungültige Zeile',
                'mobile' => null,
            ];
        }

        $mobileResult = GermanMobileNumber::validateAndNormalize($row['mobile']);
        if (! $mobileResult['ok']) {
            return [
                'error' => $mobileResult['error'],
                'mobile' => null,
            ];
        }

        return [
            'error' => null,
            'mobile' => $mobileResult['normalized'],
        ];
    }
}
