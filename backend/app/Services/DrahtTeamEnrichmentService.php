<?php

namespace App\Services;

use App\Http\Controllers\Api\DrahtController;
use App\Models\Event;
use App\Support\DrahtScheduleData;
use Illuminate\Support\Facades\DB;

class DrahtTeamEnrichmentService
{
    public function __construct(
        private readonly DrahtController $draht,
    ) {}

    /**
     * Merge organization/location from DRAHT scheduledata into existing FLOW teams.
     *
     * @param  array<string, mixed>|null  $schedulePayload  fetchScheduleData()['data'] when already loaded
     */
    public function enrichEvent(Event $event, ?array $schedulePayload = null): int
    {
        if ($schedulePayload === null) {
            $fetched = $this->draht->fetchScheduleData($event);
            $schedulePayload = $fetched['data'] ?? null;
        }

        if (! is_array($schedulePayload)) {
            return 0;
        }

        $updated = 0;
        foreach ($schedulePayload['programs'] ?? [] as $program) {
            if (! is_array($program)) {
                continue;
            }

            $firstProgram = (int) ($program['first_program'] ?? 0);
            $teams = $program['teams'] ?? [];
            if ($firstProgram < 1 || ! is_array($teams)) {
                continue;
            }

            foreach (DrahtScheduleData::teamDetailRows($teams) as $row) {
                $existing = DB::table('team')
                    ->where('event', $event->id)
                    ->where('first_program', $firstProgram)
                    ->where('team_number_hot', $row['team_number_hot'])
                    ->first(['id', 'organization', 'location']);

                if ($existing === null) {
                    continue;
                }

                $changes = [];
                if ($row['organization'] !== null && (string) ($existing->organization ?? '') !== $row['organization']) {
                    $changes['organization'] = $row['organization'];
                }
                if ($row['location'] !== null && (string) ($existing->location ?? '') !== $row['location']) {
                    $changes['location'] = $row['location'];
                }

                if ($changes === []) {
                    continue;
                }

                DB::table('team')->where('id', $existing->id)->update($changes);
                $updated++;
            }
        }

        return $updated;
    }
}
