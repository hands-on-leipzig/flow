<?php
namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScheduleMatrixDb
{
    /**
     * Build roles matrix based on DB (m_role + m_visibility).
     */
    public function buildRolesMatrix(Collection $activities): array
    {
        // --- 1. Load roles and visibility map
        $roles = DB::table('m_role')
            ->whereNotNull('first_program')
            ->where('schedule_matrix', 1)
            ->orderBy('first_program')
            ->orderBy('sequence')
            ->get();

        $visibility = DB::table('m_visibility')->get(); // role_id + atd_id

        if ($activities->isEmpty() || $roles->isEmpty()) {
            return [
                'headers' => [['key' => 'time', 'title' => 'Zeit']],
                'rows'    => [['separator' => true]],
            ];
        }

        // --- 2. Determine lane/table usage from activities
        $exLaneMax = (int)($activities->where('program_name', 'EXPLORE')->pluck('lane')->filter()->max() ?? 0);
        $chLaneMax = (int)($activities->where('program_name', 'CHALLENGE')->pluck('lane')->filter()->max() ?? 0);

        $tablesUsed = collect([1,2,3,4])->filter(function ($t) use ($activities) {
            return $activities->contains(fn($a) => ((int)$a->table_1 === $t) || ((int)$a->table_2 === $t));
        })->values()->all();

        

        // --- 3. Build headers dynamically
        $headers = [['key'=>'time','title'=>'Zeit']];

        foreach ($roles as $role) {
            $progLetter = ((int)$role->first_program === 2) ? 'E' : 'C';
            $shortName  = strtoupper(substr($role->name, 0, 2)); 
            $titleBase  = (string)$role->name_short;             

            if ($role->differentiation_parameter === 'lane') {
                $laneMax = ($progLetter === 'E') ? $exLaneMax : $chLaneMax;
                for ($i = 1; $i <= $laneMax; $i++) {
                    $headers[] = [
                        'key'   => strtolower($progLetter).'_'.$shortName.$i, 
                        'title' => "{$titleBase}{$i}",                        
                    ];
                }
            } elseif ($role->differentiation_parameter === 'table') {
                foreach ($tablesUsed as $t) {
                    $headers[] = [
                        'key'   => strtolower($progLetter).'_'.$shortName.'t'.$t, 
                        'title' => "{$titleBase}{$t}",                          
                    ];
                }
            } else {
                $headers[] = [
                    'key'   => strtolower($progLetter).'_'.$shortName, 
                    'title' => $titleBase,                             
                ];
            }
        }

        $headerKeys = array_map(fn($h) => $h['key'], $headers);

        // --- 4. Bucket activities per visibility
        $bucket = [];
        foreach ($activities as $a) {
            $atd = (int)$a->activity_type_detail_id;
            $visibleRoles = $visibility->where('activity_type_detail', $atd);

            foreach ($visibleRoles as $vr) {
                $role = $roles->firstWhere('id', $vr->role);
                if (!$role) continue;

                $progLetter = ((int)$role->first_program === 2) ? 'E' : 'C';
                $shortName  = strtoupper(substr($role->name, 0, 2));

                $start = Carbon::parse($a->start_time)->startOfMinute();
                $end   = Carbon::parse($a->end_time)->startOfMinute();
                $text  = (string)$a->activity_name;

                if ($role->differentiation_parameter === 'lane') {
                    $lane = (int)($a->lane ?? 0);
                    $key  = strtolower($progLetter).'_'.$shortName.$lane;
                    if ($lane > 0) {
                        $this->push($bucket, $key, $start, $end, $text);
                    } else {
                        for ($i = 1; $i <= (($progLetter==='E')?$exLaneMax:$chLaneMax); $i++) {
                            $this->push($bucket, strtolower($progLetter).'_'.$shortName.$i, $start, $end, $text);
                        }
                    }
                } elseif ($role->differentiation_parameter === 'table') {
                    foreach ([1,2] as $ti) {
                        $tNo = (int)($a->{'table_'.$ti} ?? 0);
                        if ($tNo > 0) {
                            $key = strtolower($progLetter).'_'.$shortName.'t'.$tNo;
                            $this->push($bucket, $key, $start, $end, $text);
                        }
                    }
                } else {
                    $key = strtolower($progLetter).'_'.$shortName;
                    $this->push($bucket, $key, $start, $end, $text);
                }
            }
        }

        // --- 5. Generate raster rows (5-min steps)
        $minStart = Carbon::parse($activities->min('start_time'))->startOfMinute();
        $maxEnd   = Carbon::parse($activities->max('end_time'))->startOfMinute();

        $rows = [];
        $activeUntil = [];
        $t = $minStart->copy();
        $lastDay = null;

        while ($t < $maxEnd) {
            $day = $t->toDateString();
            $row = [
                'timeIso'   => $t->copy()->utc()->toIso8601String(),
                'timeLabel' => $t->copy()->format('d.m. H:i'),
                'cells'     => [],
            ];

            foreach ($headerKeys as $key) {
                if ($key === 'time') continue;
                if (isset($activeUntil[$key]) && $t < $activeUntil[$key]) {
                    $row['cells'][$key] = ['render'=>false];
                    continue;
                }
                $iso = $t->toDateTimeString();
                $items = $bucket[$key][$iso] ?? [];
                if (!empty($items)) {
                    $it = $items[0];
                    $start = Carbon::parse($it['start']);
                    $end   = Carbon::parse($it['end']);
                    $span  = max(1, (int)($start->diffInMinutes($end) / 5));
                    $activeUntil[$key] = $end;

                    $row['cells'][$key] = [
                        'render'=>true,'rowspan'=>$span,'colspan'=>1,'text'=>$it['text'],
                    ];
                } else {
                    $row['cells'][$key] = ['render'=>true,'rowspan'=>1,'colspan'=>1,'text'=>''];
                }
            }

            $rows[] = $row;
            $next = $t->copy()->addMinutes(5);
            if ($lastDay !== null && $next->toDateString() !== $day) {
                $rows[] = ['separator'=>true];
                $rows[] = $this->emptyRow($next, $headerKeys);
            }
            $lastDay = $day;
            $t = $next;
        }
        $rows[] = $this->emptyRow($maxEnd, $headerKeys);

        return ['headers'=>$headers, 'rows'=>$rows];
    }

    private function emptyRow(Carbon $t, array $headerKeys): array
    {
        $cells = [];
        foreach ($headerKeys as $key) {
            if ($key === 'time') continue;
            $cells[$key] = ['render'=>true,'rowspan'=>1,'colspan'=>1,'text'=>''];
        }
        return [
            'timeIso'   => $t->copy()->utc()->toIso8601String(),
            'timeLabel' => $t->copy()->format('d.m. H:i'),
            'cells'     => $cells,
        ];
    }

    private function push(array &$bucket, string $colKey, Carbon $start, Carbon $end, string $text): void
    {
        $k = $start->toDateTimeString();
        $bucket[$colKey][$k] = $bucket[$colKey][$k] ?? [];
        $bucket[$colKey][$k][] = [
            'start' => $start->toDateTimeString(),
            'end'   => $end->toDateTimeString(),
            'text'  => $text,
        ];
    }







    public function __construct(private readonly ScheduleMatrix $v1)
    {
    }


    /** Build "Teams" view. Step 1: delegate to V1. */
    public function buildTeamsMatrix(Collection $activities): array
    {
        return $this->v1->buildTeamsMatrix($activities);
    }

    /** Build "Rooms" view. Step 1: delegate to V1. */
    public function buildRoomsMatrix(Collection $activities): array
    {
        return $this->v1->buildRoomsMatrix($activities);
    }

    /** Default headers for roles. */
    public function defaultRolesHeaders(): array
    {
        return $this->v1->defaultRolesHeaders();
    }

    /** Default headers for teams. */
    public function defaultTeamsHeaders(): array
    {
        return $this->v1->defaultTeamsHeaders();
    }

    /** Default headers for rooms. */
    public function defaultRoomsHeaders(): array
    {
        return $this->v1->defaultRoomsHeaders();
    }

}
