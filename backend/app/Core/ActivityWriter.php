<?php

namespace App\Core;

use App\Models\Activity;
use App\Models\ActivityGroup;
use App\Models\MActivityTypeDetail;
use App\Models\MRoomType;
use App\Enums\FirstProgram;
use App\Enums\ExploreMode;
use App\Support\PlanParameter;
use App\Support\UsesPlanParameter;

class ActivityWriter
{
    use UsesPlanParameter;

    private int $planId;
    private ?ActivityGroup $currentGroup = null;

    /** @var array<string,int> */
    private array $activityTypeDetailMap = [];

    /** @var array<string,int> */
    private array $roomTypeMap = [];

    /** @var array<int,?int> */
    private array $activityTypeDetailFirstProgramMap = [];

    public function __construct(int $planId, PlanParameter $params)
    {
        $this->planId = $planId;
        $this->params = $params;

        $details = MActivityTypeDetail::all();

        $this->activityTypeDetailMap = $details
            ->pluck('id', 'code')
            ->mapWithKeys(fn($id, $code) => [strtolower($code) => (int) $id])
            ->toArray();

        $this->activityTypeDetailFirstProgramMap = $details
            ->pluck('first_program', 'id')
            ->map(fn($fp) => $fp ? (int) $fp : null)
            ->toArray();

        $this->roomTypeMap = MRoomType::all()
            ->pluck('id', 'code')
            ->mapWithKeys(fn($id, $code) => [strtolower($code) => (int) $id])
            ->toArray();
    }


    public function insertActivityGroup(string $activityTypeDetailCode, ?int $exploreGroup = null): int
    {
        $activityTypeDetailId = $this->activityTypeDetailIdFromCode($activityTypeDetailCode);

        if (!$activityTypeDetailId) {
            throw new \RuntimeException("Unbekannter activity_type_detail-Code: '{$activityTypeDetailCode}'. Dieser Code existiert nicht in der Datenbank.");
        }

        $group = ActivityGroup::create([
            'plan' => $this->planId,
            'activity_type_detail' => $activityTypeDetailId,
            'explore_group' => $exploreGroup,
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
        ?int $table2 = null, ?int $table2Team = null,
        ?int $extraBlockId = null,
        ?int $exploreGroup = null
    ): int {
        if (!$this->currentGroup) {
            throw new \RuntimeException("Keine Aktivitätsgruppe gesetzt vor dem Einfügen der Aktivität '{$activityTypeCode}'. Bitte setze zunächst eine Aktivitätsgruppe mit withGroup().");
        }

        $start = $time->current()->format('Y-m-d H:i:s');

        $endCursor = $time->copy();
        $endCursor->addMinutes($duration);
        $end = $endCursor->current()->format('Y-m-d H:i:s');

        $activityTypeDetailId = $this->activityTypeDetailIdFromCode($activityTypeCode);
        
        // Inherit explore_group from current group if not explicitly provided
        $exploreGroupValue = $exploreGroup ?? $this->currentGroup->explore_group;
        
        $roomType = $this->resolveRoomType($activityTypeDetailId, $juryLane, $exploreGroupValue);

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
            'extra_block'          => $extraBlockId,
            'explore_group'        => $exploreGroupValue,
        ]);

        return $activity->id;
    }

    /**
     * Bulk insert multiple activities at once.
     * More efficient than calling insertActivity() in a loop.
     *
     * @param array<array{
     *   activityTypeCode: string,
     *   start: string,
     *   end: string,
     *   juryLane?: ?int,
     *   juryTeam?: ?int,
     *   table1?: ?int,
     *   table1Team?: ?int,
     *   table2?: ?int,
     *   table2Team?: ?int,
     *   extraBlockId?: ?int,
     *   exploreGroup?: ?int
     * }> $activities Array of activity data
     * @return void
     */
    public function insertActivitiesBulk(array $activities): void
    {
        if (!$this->currentGroup) {
            throw new \RuntimeException("Keine Aktivitätsgruppe gesetzt vor dem Bulk-Einfügen von Aktivitäten. Bitte setze zunächst eine Aktivitätsgruppe mit withGroup().");
        }

        if (empty($activities)) {
            return;
        }

        $data = [];
        foreach ($activities as $act) {
            $activityTypeDetailId = $this->activityTypeDetailIdFromCode($act['activityTypeCode']);
            
            // Inherit explore_group from current group if not explicitly provided
            $exploreGroupValue = $act['exploreGroup'] ?? $this->currentGroup->explore_group;
            
            $roomType = $this->resolveRoomType($activityTypeDetailId, $act['juryLane'] ?? null, $exploreGroupValue);

            $data[] = [
                'activity_group'       => $this->currentGroup->id,
                'activity_type_detail' => $activityTypeDetailId,
                'start'                => $act['start'],
                'end'                  => $act['end'],
                'room_type'            => $roomType,
                'jury_lane'            => $act['juryLane'] ?? null,
                'jury_team'            => $act['juryTeam'] ?? null,
                'table_1'              => $act['table1'] ?? null,
                'table_1_team'         => $act['table1Team'] ?? null,
                'table_2'              => $act['table2'] ?? null,
                'table_2_team'         => $act['table2Team'] ?? null,
                'extra_block'          => $act['extraBlockId'] ?? null,
                'explore_group'        => $exploreGroupValue,
            ];
        }

        Activity::insert($data);
    }

    public function withGroup(string $activityTypeDetailCode, \Closure $callback, ?int $exploreGroup = null): void
    {
        $this->insertActivityGroup($activityTypeDetailCode, $exploreGroup);
        $callback();
        $this->currentGroup = null;
    }

    private function activityTypeDetailIdFromCode(string $code): ?int
    {
        return $this->activityTypeDetailMap[strtolower($code)] ?? null;
    }

    private function resolveRoomType(int $activityTypeDetailId, ?int $juryLane, ?int $exploreGroup = null): ?int
    {
        $code = array_search($activityTypeDetailId, $this->activityTypeDetailMap, true);
        if (!$code) {
            return null;
        }

        $code = strtolower($code);

        if ($juryLane !== null && $juryLane > 0) {
            if (in_array($code, ['j_with_team', 'j_scoring'])) {
                return $this->roomTypeMap['j_lane_' . $juryLane] ?? null;
            }
            if (in_array($code, ['f8_j_with_team', 'f8_j_scoring'])) {
                return $this->roomTypeMap['f8_j_lane_' . $juryLane] ?? null;
            }
            if (in_array($code, ['e_with_team', 'e_scoring'])) {
                return $this->roomTypeMap['e_lane_' . $juryLane] ?? null;
            }
            if (in_array($code, ['lc_with_team', 'lc_scoring'])) {
                return $this->roomTypeMap['lc_lane_' . $juryLane] ?? null;
            }
        }

        // Check if this is an Explore activity and if there are two Explore groups
        $firstProgram = $this->activityTypeDetailFirstProgramMap[$activityTypeDetailId] ?? null;
        $isExploreActivity = ($firstProgram === FirstProgram::EXPLORE->value);
        $hasTwoExploreGroups = $this->hasTwoExploreGroups();

        // For Explore activities without lanes, insert group number after "e" if there are two Explore groups
        // Example: e_opening -> e1_opening or e2_opening
        if ($isExploreActivity && $hasTwoExploreGroups && $exploreGroup !== null && ($juryLane === null || $juryLane === 0)) {
            // Replace "e_" with "e{group}_"
            $code = str_replace('e_', 'e' . $exploreGroup . '_', $code);
        }

        $map = [
            // Exceptional cases where code != room type
            'g_party_teams'    => 'g_party_teams',
            'g_party_volunteers' => 'g_party_volunteers',
        ];

        // Default 1:1 mapping - use the code directly if not in exceptions
        $roomTypeCode = $map[$code] ?? $code;
        
        return $this->roomTypeMap[$roomTypeCode] ?? null;
    }

    /**
     * Check if this plan has two Explore groups (HYBRID_BOTH or DECOUPLED_BOTH mode)
     */
    private function hasTwoExploreGroups(): bool
    {
        $eMode = $this->exploreMode();

        // Check if e_mode indicates two Explore groups
        return ($eMode === ExploreMode::HYBRID_BOTH->value || 
                $eMode === ExploreMode::DECOUPLED_BOTH->value);
    }

}