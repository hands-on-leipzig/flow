<?php

namespace App\Services;

use App\Models\PdfDownload;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PdfDownloadRecorder
{
    public const KINDS = [
        'overview_sheet',
        'room_sheets',
        'role_sheets',
        'gesamtplan',
        'teamliste',
        'match_plan_score',
        'name_tags',
    ];

    public function record(int $eventId, string $kind, ?int $userId): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            return;
        }

        try {
            PdfDownload::create([
                'event' => $eventId,
                'user' => $userId,
                'kind' => $kind,
                'created' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to log PDF download', [
                'error' => $e->getMessage(),
                'event_id' => $eventId,
                'kind' => $kind,
            ]);
        }
    }
}
