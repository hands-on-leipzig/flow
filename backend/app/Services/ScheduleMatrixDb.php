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

                $baseText = (string)$a->activity_name;

                if ($role->differentiation_parameter === 'lane') {
                    $lane = (int)($a->lane ?? 0);
                    $teamNo = (int)($a->team ?? 0);
                    $text = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');
                    $key  = strtolower($progLetter).'_'.$shortName.$lane;

                    if ($lane > 0) {
                        $this->push($bucket, $key, $start, $end, $text);
                    } else {
                        for ($i = 1; $i <= (($progLetter==='E')?$exLaneMax:$chLaneMax); $i++) {
                            $this->push($bucket, strtolower($progLetter).'_'.$shortName.$i, $start, $end, $text);
                        }
                    }
                } elseif ($role->differentiation_parameter === 'table') {
                    // 1) Place on the explicitly assigned tables (if any)
                    $pushed = false;
                    foreach ([1, 2] as $ti) {
                        $tNo = (int)($a->{'table_'.$ti} ?? 0);
                        if ($tNo > 0) {
                            $teamNo = (int)($a->{'table_'.$ti.'_team'} ?? 0);
                            $text   = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');
                            $key    = strtolower($progLetter).'_'.$shortName.'t'.$tNo;
                            $this->push($bucket, $key, $start, $end, $text);
                            $pushed = true;
                        }
                    }

                    // 2) If no table is set → replicate across all used tables
                    if (!$pushed) {
                        $teamNo = (int)($a->team ?? 0); // generic fallback team number
                        $text   = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');
                        foreach ($tablesUsed as $t) {
                            $key = strtolower($progLetter).'_'.$shortName.'t'.$t;
                            $this->push($bucket, $key, $start, $end, $text);
                        }
                    }
                } elseif ($role->differentiation_parameter === 'team') {
                    // show only activities WITHOUT any team assignment
                    $text = $baseText;
                    $hasTeam =
                        (int)($a->team ?? 0) > 0
                        || (int)($a->table_1_team ?? 0) > 0
                        || (int)($a->table_2_team ?? 0) > 0;

                    if (!$hasTeam) {
                        $key = strtolower($progLetter).'_'.$shortName; // same key as simple column
                        $this->push($bucket, $key, $start, $end, $text);
                    }
                } else {
                    $text = $baseText;
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








    public function buildTeamsMatrix(Collection $activities): array
    {
        // 1) Load ONLY the team-roles per program (for visibility) 
        $teamRoles = DB::table('m_role')
            ->whereNotNull('first_program')
            ->where('schedule_matrix', 1)
            ->where('differentiation_parameter', 'team')
            ->select('id','first_program','name_short')
            ->get();

        if ($activities->isEmpty() || $teamRoles->isEmpty()) {
            return [
                'headers' => [['key' => 'time', 'title' => 'Zeit']],
                'rows'    => [['separator' => true]],
            ];
        }

        // 2) Determine team numbers from activities per program
        $collectTeams = function(string $program) use ($activities) {
            return $activities
                ->filter(fn($a) => strtoupper((string)$a->program_name) === $program)
                ->flatMap(function($a){
                    return [
                        (int)($a->team ?? 0),
                        (int)($a->table_1_team ?? 0),
                        (int)($a->table_2_team ?? 0),
                    ];
                })
                ->filter(fn($n) => $n > 0)
                ->unique()
                ->sort()
                ->values()
                ->all();
        };

        $exTeams = $collectTeams('EXPLORE');
        $chTeams = $collectTeams('CHALLENGE');

        // 3) Build headers: Zeit | <name_short(E)>01.. | <name_short(C)>01..
        $nameShortE = (string)($teamRoles->firstWhere('first_program', 2)->name_short ?? 'Team E');
        $nameShortC = (string)($teamRoles->firstWhere('first_program', 3)->name_short ?? 'Team C');

        $headers = [['key' => 'time', 'title' => 'Zeit']];
        foreach ($exTeams as $n) {
            $headers[] = [
                'key'   => 'ex_t'.str_pad((string)$n, 2, '0', STR_PAD_LEFT),
                'title' => $nameShortE . str_pad((string)$n, 2, '0', STR_PAD_LEFT),
            ];
        }
        foreach ($chTeams as $n) {
            $headers[] = [
                'key'   => 'ch_t'.str_pad((string)$n, 2, '0', STR_PAD_LEFT),
                'title' => $nameShortC . str_pad((string)$n, 2, '0', STR_PAD_LEFT),
            ];
        }

        $headerKeys = array_map(fn($h) => $h['key'], $headers);

        // 4) Visibility sets per program (union of all team roles per program)
        $vis = DB::table('m_visibility')->get();
        $teamRoleIdsE = $teamRoles->where('first_program', 2)->pluck('id')->all();
        $teamRoleIdsC = $teamRoles->where('first_program', 3)->pluck('id')->all();

        $allowedAtdsE = $vis->whereIn('role', $teamRoleIdsE)->pluck('activity_type_detail')->unique()->values()->all();
        $allowedAtdsC = $vis->whereIn('role', $teamRoleIdsC)->pluck('activity_type_detail')->unique()->values()->all();

        $isAllowedForProgram = function (int $atd, string $prog) use ($allowedAtdsE, $allowedAtdsC) {
            if ($prog === 'EXPLORE')  return in_array($atd, $allowedAtdsE, true);
            if ($prog === 'CHALLENGE') return in_array($atd, $allowedAtdsC, true);
            return false;
        };

        // 5) Bucket activities
        $bucket = [];
        foreach ($activities as $a) {
            $prog = strtoupper((string)$a->program_name);
            if ($prog !== 'EXPLORE' && $prog !== 'CHALLENGE') continue;

            $atdId = (int)$a->activity_type_detail_id;
            if (!$isAllowedForProgram($atdId, $prog)) continue;

            $start = Carbon::parse($a->start_time)->startOfMinute();
            $end   = Carbon::parse($a->end_time)->startOfMinute();
            $text  = (string)$a->activity_name;

            // --- Hard override for "Mit Team"
            if (stripos($text, 'mit team') !== false) {
                if ($prog === 'EXPLORE') {
                    $text = 'Begutachtung';
                } elseif ($prog === 'CHALLENGE') {
                    $text = 'Jury';
                }
            }

            // Which team columns belong to this program?
            $colPrefix = $prog === 'EXPLORE' ? 'ex_t' : 'ch_t';
            $teamsAll  = $prog === 'EXPLORE' ? $exTeams : $chTeams;

            // Team numbers present on the activity
            $teamsInActivity = collect([
                (int)($a->team ?? 0),
                (int)($a->table_1_team ?? 0),
                (int)($a->table_2_team ?? 0),
            ])->filter(fn($n)=>$n>0)->unique()->all();

            if (!empty($teamsInActivity)) {
                foreach ($teamsInActivity as $tn) {
                    // only drop into existing columns
                    if (in_array($tn, $teamsAll, true)) {
                        $key = $colPrefix . str_pad((string)$tn, 2, '0', STR_PAD_LEFT);
                        $this->push($bucket, $key, $start, $end, $text);
                    }
                }
            } else {
                // No team on activity → replicate across all team columns of the program
                foreach ($teamsAll as $tn) {
                    $key = $colPrefix . str_pad((string)$tn, 2, '0', STR_PAD_LEFT);
                    $this->push($bucket, $key, $start, $end, $text);
                }
            }
        }

        // 6) Raster rows (5-min) + day separators + extra empty row at end
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



    /** Build "Rooms" view. Step 1: delegate to V1. */
    public function buildRoomsMatrix(Collection $activities): array
    {
        // 1) Load roles (only used in schedule matrix) and filter out table-differentiated roles
        $roles = DB::table('m_role')
            ->whereNotNull('first_program')
            ->where('schedule_matrix', 1)
            ->where(function($q){
                $q->whereNull('differentiation_parameter')
                ->orWhere('differentiation_parameter', '<>', 'table');
            })
            ->orderBy('first_program')
            ->orderBy('sequence')
            ->get();

        // Map of ATDs that are visible for at least one of these roles
        $visibleAtdIds = DB::table('m_visibility')
            ->whereIn('role', $roles->pluck('id')->all())
            ->pluck('activity_type_detail')
            ->unique()
            ->values()
            ->all();

        // Filter activities to those that belong to the above-visible ATDs
        $acts = $activities->filter(function ($a) use ($visibleAtdIds) {
            return in_array((int)$a->activity_type_detail_id, $visibleAtdIds, true);
        })->values();

        if ($acts->isEmpty()) {
            return [
                'headers' => [['key' => 'time', 'title' => 'Zeit']],
                'rows'    => [['separator' => true]],
            ];
        }

        // 2) Time window from filtered activities
        $minStart = Carbon::parse($acts->min('start_time'))->startOfMinute();
        $maxEnd   = Carbon::parse($acts->max('end_time'))->startOfMinute();

        // 3) Room types actually used by filtered activities (sorted by sequence)
        $roomTypes = $acts
            ->filter(fn($a) => (int)($a->room_type_id ?? 0) > 0)
            ->groupBy('room_type_id')
            ->map(function ($grp) {
                $first = $grp->first();
                return [
                    'id'       => (int)$first->room_type_id,
                    'name'     => (string)($first->room_type_name ?? ('Room '.$first->room_type_id)),
                    'sequence' => (int)($first->room_type_sequence ?? 0),
                ];
            })
            ->values()
            ->sortBy('sequence')
            ->all();

        // 4) Headers: Zeit + one column per used room type
        $headers = [['key'=>'time','title'=>'Zeit']];
        foreach ($roomTypes as $rt) {
            $headers[] = ['key' => 'room_'.$rt['id'], 'title' => $rt['name']];
        }
        $headerKeys = array_map(fn($h) => $h['key'], $headers);

        // 5) Buckets per room + start minute
        $bucket = [];
        $push = function(string $colKey, Carbon $start, Carbon $end, string $text) use (&$bucket) {
            $k = $start->toDateTimeString();
            $bucket[$colKey][$k] = $bucket[$colKey][$k] ?? [];
            $bucket[$colKey][$k][] = ['start'=>$start->toDateTimeString(), 'end'=>$end->toDateTimeString(), 'text'=>$text];
        };

        foreach ($acts as $a) {
            $rtId = (int)($a->room_type_id ?? 0);
            if ($rtId <= 0) continue; // no room -> nowhere to place

            $start = Carbon::parse($a->start_time)->startOfMinute();
            $end   = Carbon::parse($a->end_time)->startOfMinute();

            // Base text (hard override for "Mit Team")
            $base = (string)$a->activity_name;
            if (trim($base) === 'Mit Team') {
                $prog = strtoupper((string)$a->program_name);
                if ($prog === 'EXPLORE')   { $base = 'Begutachtung'; }
                elseif ($prog === 'CHALLENGE') { $base = 'Jury'; }
            }

            $push('room_'.$rtId, $start, $end, $base);
        }

        // 6) Raster rows (5-min) + day separators + extra empty row after day & at end
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

            foreach ($headers as $h) {
                $key = $h['key'];
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

                    $row['cells'][$key] = ['render'=>true,'rowspan'=>$span,'colspan'=>1,'text'=>$it['text']];
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

        // Extra empty row at the end
        $rows[] = $this->emptyRow($maxEnd, $headerKeys);

        return ['headers'=>$headers, 'rows'=>$rows];
    }

}
