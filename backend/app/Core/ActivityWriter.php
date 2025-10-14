<?php

namespace App\Core;

use App\Models\Activity;
use App\Models\ActivityGroup;
use App\Models\MActivityTypeDetail;
use App\Models\MRoomType;
use App\Models\ExtraBlock;
use App\Models\MInsertPoint;

use App\Core\TimeCursor;
use Illuminate\Support\Facades\Log;

class ActivityWriter
{
    private int $planId;
    private ?ActivityGroup $currentGroup = null;

    /** @var array<string,int> */
    private array $activityTypeDetailMap = [];

    /** @var array<string,int> */
    private array $roomTypeMap = [];

    public function __construct(int $planId)
    {
        $this->planId = $planId;

        $this->activityTypeDetailMap = MActivityTypeDetail::all()
            ->pluck('id', 'code')
            ->mapWithKeys(fn($id, $code) => [strtolower($code) => (int) $id])
            ->toArray();

        $this->roomTypeMap = MRoomType::all()
            ->pluck('id', 'code')
            ->mapWithKeys(fn($id, $code) => [strtolower($code) => (int) $id])
            ->toArray();
    }


    public function insertActivityGroup(string $activityTypeDetailCode): int
    {
        $activityTypeDetailId = $this->activityTypeDetailIdFromCode($activityTypeDetailCode);

        if (!$activityTypeDetailId) {
            throw new \RuntimeException("Unknown activity_type_detail code: {$activityTypeDetailCode}");
        }

        $group = ActivityGroup::create([
            'plan' => $this->planId,
            'activity_type_detail' => $activityTypeDetailId,
        ]);

        $this->currentGroup = $group;
        return $group->id;
    }

    public function insertActivity(
        string $activityTypeCode,
        TimeCursor $time,
        int $duration,
        ?int $juryLane = null, ?int $juryTeam = null,
        ?int $table1 = null, ?int $table1Team = null,
        ?int $table2 = null, ?int $table2Team = null
    ): int {
        if (!$this->currentGroup) {
            throw new \RuntimeException("No activity group set before inserting activity: {$activityTypeCode}");
        }

        $start = $time->current()->format('Y-m-d H:i:s');

        $endCursor = $time->copy();
        $endCursor->addMinutes($duration);
        $end = $endCursor->current()->format('Y-m-d H:i:s');

        $activityTypeDetailId = $this->activityTypeDetailIdFromCode($activityTypeCode);
        $roomType = $this->resolveRoomType($activityTypeDetailId, $juryLane);

        $activity = Activity::create([
            'activity_group'       => $this->currentGroup->id,
            'activity_type_detail' => $activityTypeDetailId,
            'start'                => $start,
            'end'                  => $end,
            'room_type'            => $roomType,
            'jury_lane'            => $juryLane,
            'jury_team'            => $juryTeam,
            'table_1'              => $table1,
            'table_1_team'         => $table1Team,
            'table_2'              => $table2,
            'table_2_team'         => $table2Team,
        ]);

        return $activity->id;
    }

    public function withGroup(string $activityTypeDetailCode, \Closure $callback): void
    {
        $this->insertActivityGroup($activityTypeDetailCode);
        $callback();
        $this->currentGroup = null;
    }

    private function activityTypeDetailIdFromCode(string $code): ?int
    {
        static $cache = [];

        $key = strtolower($code);
        if (!isset($cache[$key])) {
            $cache[$key] = $this->activityTypeDetailMap[$key] ?? null;
        }

        return $cache[$key];
    }

    private function resolveRoomType(int $activityTypeDetailId, ?int $juryLane): ?int
    {
        $code = array_search($activityTypeDetailId, $this->activityTypeDetailMap, true);
        if (!$code) {
            return null;
        }

        $code = strtolower($code);

        if ($juryLane !== null && $juryLane > 0) {
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



    public function insertPoint(string $insertPointCode, int $duration, TimeCursor $time): void
    {
        // Query insert point by code from database
        $insertPoint = MInsertPoint::where('code', $insertPointCode)->first();
        
        if (!$insertPoint) {
            throw new \RuntimeException("Insert point code '{$insertPointCode}' not found in database.");
        }

        // passenden ExtraBlock suchen
        $extraBlock = ExtraBlock::where('plan', $this->planId)
            ->where('insert_point', $insertPoint->id)
            ->where('active', true)
            ->first();

        if ($extraBlock) {
            // Determine activity type code based on first_program
            $activityCode = match ($insertPoint->first_program) {
                2 => 'e_inserted_block',  // Explore
                3 => 'c_inserted_block',  // Challenge
                default => 'g_inserted_block',  // Joint/Other
            };

            $this->withGroup($activityCode, function () use ($extraBlock, $activityCode, $time) {
                $time->addMinutes((int) $extraBlock->buffer_before);
                $this->insertActivity($activityCode, $time, (int) $extraBlock->duration);
                $time->addMinutes((int) $extraBlock->duration + (int) $extraBlock->buffer_after);
            });

            Log::debug("Block inserted at '{$insertPointCode}' using activity type '{$activityCode}'.");
        } else {
            $time->addMinutes($duration);
        }    
    }

}