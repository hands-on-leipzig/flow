<?php

namespace App\Core;

use Illuminate\Support\Facades\DB;
use DateTime;

class ActivityWriter
{
    private int $planId;
    private ?int $currentGroupId = null;

    /** @var array<string,int> */
    private array $activityTypeDetailMap = [];

    /** @var array<string,int> */
    private array $roomTypeMap = [];

    public function __construct(int $planId)
    {
        $this->planId = $planId;

        // ActivityTypeDetail: code → id
        $this->activityTypeDetailMap = DB::table('m_activity_type_detail')
            ->pluck('id', 'code')
            ->mapWithKeys(fn($id, $code) => [strtolower($code) => (int)$id])
            ->toArray();

        // RoomType: code → id
        $this->roomTypeMap = DB::table('m_room_type')
            ->pluck('id', 'code')
            ->mapWithKeys(fn($id, $code) => [strtolower($code) => (int)$id])
            ->toArray();
    }

    public function insertActivityGroup(string $activityTypeDetailCode): int
    {
        $activityTypeDetailId = $this->activityTypeDetailIdFromCode($activityTypeDetailCode);

        if (!$activityTypeDetailId) {
            throw new \RuntimeException("Unknown activity_type_detail code: {$activityTypeDetailCode}");
        }

        $id = DB::table('activity_group')->insertGetId([
            'plan' => $this->planId,
            'activity_type_detail' => $activityTypeDetailId,
        ]);

        $this->currentGroupId = $id;
        return $id;
    }

    public function insertActivity(
        string $activityTypeCode,
        DateTime $timeStart,
        int $duration,
        ?int $juryLane = null, ?int $juryTeam = null,
        ?int $table1 = null, ?int $table1Team = null,
        ?int $table2 = null, ?int $table2Team = null
    ): int {
        // Ende berechnen
        $timeEnd = clone $timeStart;
        $timeEnd->modify("+{$duration} minutes");

        $start = $timeStart->format('Y-m-d H:i:s');
        $end   = $timeEnd->format('Y-m-d H:i:s');

        // activity_type_detail-ID anhand des Codes holen (aus Cache, nicht jedes Mal aus DB)
        $activityTypeDetailId = $this->activityTypeDetailIdFromCode($activityTypeCode);

        // RoomType ermitteln (auch via Codes)
        $roomType = $this->resolveRoomType($activityTypeCode, $juryLane);

        return DB::table('activity')->insertGetId([
            'activity_group'         => $this->currentGroupId,
            'activity_type_detail'   => $activityTypeDetailId,
            'start'                  => $start,
            'end'                    => $end,
            'room_type'              => $roomType,
            'jury_lane'              => $juryLane,
            'jury_team'              => $juryTeam,
            'table_1'                => $table1,
            'table_1_team'           => $table1Team,
            'table_2'                => $table2,
            'table_2_team'           => $table2Team,
        ]);
    }

    private function activityTypeDetailIdFromCode(string $code): ?int
    {
        static $cache = [];

        if (!isset($cache[$code])) {
            $cache[$code] = DB::table('m_activity_type_detail')
                ->where('code', $code)
                ->value('id');
        }

        return $cache[$code];
    }

    private function resolveRoomType(int $activityTypeDetailId, ?int $juryLane): ?int
    {
        // Code des ActivityTypeDetails holen
        $code = array_search($activityTypeDetailId, $this->activityTypeDetailMap, true);
        if (!$code) {
            return null;
        }

        // normalize
        $code = strtolower($code);

        if ($juryLane > 0) {
            if (in_array($code, ['c_with_team', 'c_scoring'])) {
                return $this->roomTypeMap['c_lane_' . $juryLane] ?? null;
            }
            if (in_array($code, ['e_with_team', 'e_scoring'])) {
                return $this->roomTypeMap['e_lane_' . $juryLane] ?? null;
            }
            if (in_array($code, ['lc_with_team', 'lc_scoring'])) {
                return $this->roomTypeMap['lc_lane_' . $juryLane] ?? null;
            }
        }

        // Standard-Mapping
        $map = [
            'c_awards'         => 'c_awards',
            'c_briefing'       => 'c_briefing',
            'c_lunch'          => 'c_lunch',
            'c_opening'        => 'c_opening',
            'c_opening_day_1'  => 'c_opening_day_1',
            'c_preparations'   => 'c_preparations',

            'e_awards'         => 'e_awards',
            'e_briefing_coach' => 'e_briefing_coach',
            'e_briefing_judge' => 'e_briefing_judge',
            'e_deliberations'  => 'e_deliberations',
            'e_opening'        => 'e_opening',

            'g_awards'         => 'g_awards',
            'g_opening'        => 'g_opening',

            'j_briefing'       => 'j_briefing',
            'j_briefing_day_1' => 'j_briefing_day_1',
            'j_deliberations'  => 'j_deliberations',

            'lc_briefing'      => 'lc_briefing',
            'lc_deliberations' => 'lc_deliberations',

            'r_briefing'       => 'r_match',
            'r_check'          => 'r_check',
            'r_debriefing'     => 'r_match',
            'r_match'          => 'r_match',
        ];

        if (isset($map[$code])) {
            return $this->roomTypeMap[$map[$code]] ?? null;
        }

        return null;
    }


    public function insertPoint(int $insertPointId, int $duration, \DateTime &$time): void
    {
        // Extra-Block für den Insert Point suchen
        $row = DB::table('extra_block')
            ->select('id', 'buffer_before', 'duration', 'buffer_after')
            ->where('plan', $this->planId)
            ->where('insert_point', $insertPointId)
            ->first();

        if ($row) {
            // Neue ActivityGroup für den Block anlegen
            $groupId = $this->insertActivityGroup(
                $this->activityTypeDetailIdFromCode('c_inserted')  // currently only availabe for Challenge
            );

            // Raumtyp aus m_insert_point ermitteln
            $roomType = DB::table('m_insert_point')
                ->where('id', $insertPointId)
                ->value('room_type');

            // Buffer vor Block berücksichtigen
            $time->modify('+' . (int) $row->buffer_before . ' minutes');

            $timeStart = clone $time;
            $timeEnd   = clone $time;
            $timeEnd->modify('+' . (int) $row->duration . ' minutes');

            DB::table('activity')->insertGetId([
                'activity_group'        => $groupId,
                'activity_type_detail'  => $this->activityTypeDetailIdFromCode('c_inserted'),
                'start'                 => $timeStart->format('Y-m-d H:i:s'),
                'end'                   => $timeEnd->format('Y-m-d H:i:s'),
                'extra_block'           => (int) $row->id,
                'room_type'             => $roomType,
            ]);


            $time->modify('+' . ((int) $row->duration + (int) $row->buffer_after) . ' minutes');
        } else {
            // No extra block defined, just the respective normal shift
            $time->modify('+' . $duration . ' minutes');
        }
    }

    public function insertFreeActivities(): void
    {
        // Alle Extra-Blöcke mit festen Zeiten für diesen Plan laden
        $rows = DB::table('extra_block')
            ->select('id', 'first_program', 'start', 'end')
            ->where('plan', $this->planId)
            ->whereNotNull('start')
            ->get();

        foreach ($rows as $row) {
            // activity_type_detail anhand von first_program bestimmen
            switch ((int) $row->first_program) {
                case 3: // CHALLENGE
                    $atdId = $this->activityTypeDetailIdFromCode('c_free');
                    break;
                case 2: // EXPLORE
                    $atdId = $this->activityTypeDetailIdFromCode('e_free');
                    break;
                default: // gemeinsam
                    $atdId = $this->activityTypeDetailIdFromCode('free');
            }

            // Neue Activity Group anlegen
            $gid = $this->insertActivityGroup($atdId);

            // Activity mit festen Start-/Endzeiten eintragen
            DB::table('activity')->insert([
                'activity_group'        => $gid,
                'activity_type_detail'  => $atdId,
                'start'                 => $row->start,
                'end'                   => $row->end,
                'extra_block'           => (int) $row->id,
            ]);
        }
    }











}