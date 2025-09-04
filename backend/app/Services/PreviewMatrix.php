<?php
namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PreviewMatrix
{
    /**
     * Build roles matrix based on DB (m_role + m_visibility).
     */
public function buildRolesMatrix(Collection $activities): array
{
    // 1) Rollen & Visibility
    $roles = DB::table('m_role')
        ->whereNotNull('first_program')
        ->where('preview_matrix', 1)
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

    // 2) Ableitungen aus echten Activities
    $exLaneMax = (int)($activities->where('program_name', 'EXPLORE')->pluck('lane')->filter()->max() ?? 0);
    $chLaneMax = (int)($activities->where('program_name', 'CHALLENGE')->pluck('lane')->filter()->max() ?? 0);

    $tablesUsed = collect([1,2,3,4])->filter(function ($t) use ($activities) {
        return $activities->contains(fn($a) => ((int)$a->table_1 === $t) || ((int)$a->table_2 === $t));
    })->values()->all();

    // NEU: „Teams vorhanden?“ je Programm (für programmunabhängige Blöcke)
    $exTeamMax = (int) max(
        (int)($activities->where('program_name','EXPLORE')->pluck('team')->filter()->max() ?? 0),
        (int)($activities->where('program_name','EXPLORE')->pluck('table_1_team')->filter()->max() ?? 0),
        (int)($activities->where('program_name','EXPLORE')->pluck('table_2_team')->filter()->max() ?? 0)
    );
    $chTeamMax = (int) max(
        (int)($activities->where('program_name','CHALLENGE')->pluck('team')->filter()->max() ?? 0),
        (int)($activities->where('program_name','CHALLENGE')->pluck('table_1_team')->filter()->max() ?? 0),
        (int)($activities->where('program_name','CHALLENGE')->pluck('table_2_team')->filter()->max() ?? 0)
    );

    // 3) Bucketing (mit usedKeys + headerMeta)
    $bucket     = [];
    $usedKeys   = [];   // key => true
    $headerMeta = [];   // key => "Titel"

    $pushWithMeta = function(string $key, string $title, \Carbon\Carbon $start, \Carbon\Carbon $end, string $text) use (&$bucket, &$usedKeys, &$headerMeta) {
        $this->push($bucket, $key, $start, $end, $text);
        $usedKeys[$key]   = true;
        $headerMeta[$key] = $title;
    };

    foreach ($activities as $a) {
        $atd = (int)$a->activity_type_detail_id;
        $visibleRoles = $visibility->where('activity_type_detail', $atd);

        foreach ($visibleRoles as $vr) {
            $role = $roles->firstWhere('id', $vr->role);
            if (!$role) continue;

            $progLetter = ((int)$role->first_program === 2) ? 'E' : 'C';
            $shortName  = strtoupper(substr($role->name, 0, 2));
            $titleBase  = (string)$role->name_short;

            $start = Carbon::parse($a->start_time)->startOfMinute();
            $end   = Carbon::parse($a->end_time)->startOfMinute();

            $baseText = (string)$a->activity_name;

            if ($role->differentiation_parameter === 'lane') {
                $lane = (int)($a->lane ?? 0);
                $teamNo = (int)($a->team ?? 0);
                $text = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');

                if ($lane > 0) {
                    $key = strtolower($progLetter).'_'.$shortName.$lane;
                    $pushWithMeta($key, "{$titleBase}{$lane}", $start, $end, $text);
                } else {
                    $laneMax = ($progLetter === 'E') ? $exLaneMax : $chLaneMax;
                    for ($i = 1; $i <= $laneMax; $i++) {
                        $key = strtolower($progLetter).'_'.$shortName.$i;
                        $pushWithMeta($key, "{$titleBase}{$i}", $start, $end, $text);
                    }
                }

            } elseif ($role->differentiation_parameter === 'table') {
                $pushed = false;
                foreach ([1, 2] as $ti) {
                    $tNo = (int)($a->{'table_'.$ti} ?? 0);
                    if ($tNo > 0) {
                        $teamNo = (int)($a->{'table_'.$ti.'_team'} ?? 0);
                        $text   = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');
                        $key    = strtolower($progLetter).'_'.$shortName.'t'.$tNo;
                        $pushWithMeta($key, "{$titleBase}{$tNo}", $start, $end, $text);
                        $pushed = true;
                    }
                }
                if (!$pushed) {
                    $teamNo = (int)($a->team ?? 0);
                    $text   = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');
                    foreach ($tablesUsed as $t) {
                        $key = strtolower($progLetter).'_'.$shortName.'t'.$t;
                        $pushWithMeta($key, "{$titleBase}{$t}", $start, $end, $text);
                    }
                }

            } elseif ($role->differentiation_parameter === 'team') {
                // nur Activities ohne Teamzuordnung
                $hasTeam =
                    (int)($a->team ?? 0) > 0
                    || (int)($a->table_1_team ?? 0) > 0
                    || (int)($a->table_2_team ?? 0) > 0;

                if (!$hasTeam) {
                    // Programmunabhängige Blocks: nur anzeigen, wenn dieses Programm Teams hat
                    if ($progLetter === 'E' && $exTeamMax === 0) continue;
                    if ($progLetter === 'C' && $chTeamMax === 0) continue;

                    $key = strtolower($progLetter).'_'.$shortName;
                    $pushWithMeta($key, $titleBase, $start, $end, $baseText);
                }

            } else {
                // undifferenziert (z.B. Eröffnung/Briefing/Preisverleihung)
                // Nur anzeigen, wenn das jeweilige Programm überhaupt Teams hat
                if ($progLetter === 'E' && $exTeamMax === 0) continue;
                if ($progLetter === 'C' && $chTeamMax === 0) continue;

                $key = strtolower($progLetter).'_'.$shortName;
                $pushWithMeta($key, $titleBase, $start, $end, $baseText);
            }
        }
    }

    // 4) Headers NACH dem Bucketing – nur benutzte Keys
    $headers = [['key' => 'time', 'title' => 'Zeit']];

    foreach ($roles as $role) {
        $progLetter = ((int)$role->first_program === 2) ? 'E' : 'C';
        $shortName  = strtoupper(substr($role->name, 0, 2));
        $titleBase  = (string)$role->name_short;
        $prefix     = strtolower($progLetter).'_'.$shortName;

        // Finde alle benutzten Keys mit diesem Prefix
        $keysForRole = array_values(array_filter(array_keys($usedKeys), function ($k) use ($prefix) {
            return str_starts_with($k, $prefix);
        }));

        if (empty($keysForRole)) {
            continue; // für diese Rolle wurde nichts befüllt -> keine Spalte
        }

        // Sortierung je nach Differenzierung
        if ($role->differentiation_parameter === 'lane') {
            usort($keysForRole, function ($a, $b) {
                return (int)preg_replace('/^\D+/', '', $a) <=> (int)preg_replace('/^\D+/', '', $b);
            });
        } elseif ($role->differentiation_parameter === 'table') {
            usort($keysForRole, function ($a, $b) {
                // …tN → N extrahieren
                return (int)preg_replace('/^.*t/', '', $a) <=> (int)preg_replace('/^.*t/', '', $b);
            });
        } // sonst keine Sortierung nötig

        foreach ($keysForRole as $k) {
            $headers[] = [
                'key'   => $k,
                'title' => $headerMeta[$k] ?? $titleBase,
            ];
        }
    }

    // 5) Rows bauen
    $rows = $this->buildRowsPerActiveDay($headers, $bucket);
    return ['headers' => $headers, 'rows' => $rows];
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

    /**
     * Rasterize only on active days found in $bucket.
     * After each day: add a visible separator + one empty row, then stop.
     * Empty days are completely skipped.
     */
    private function buildRowsPerActiveDay(array $headers, array $bucket): array
    {
        $headerKeys = array_map(fn($h) => $h['key'], $headers);

        // Collect active dates from bucket timestamps (YYYY-MM-DD)
        $dateSet = [];
        foreach ($bucket as $col => $byTime) {
            foreach ($byTime as $iso => $_) {
                $dateSet[substr($iso, 0, 10)] = true;
            }
        }
        $dates = array_keys($dateSet);
        sort($dates);

        $rows = [];
        foreach ($dates as $date) {
            // Find day's min start and max end across all columns
            $dayMin = null; $dayMax = null;
            foreach ($bucket as $byTime) {
                foreach ($byTime as $iso => $items) {
                    if (substr($iso, 0, 10) !== $date) continue;
                    foreach ($items as $it) {
                        $s = \Illuminate\Support\Carbon::parse($it['start'])->startOfMinute();
                        $e = \Illuminate\Support\Carbon::parse($it['end'])->startOfMinute();
                        if ($dayMin === null || $s->lt($dayMin)) $dayMin = $s->copy();
                        if ($dayMax === null || $e->gt($dayMax)) $dayMax = $e->copy();
                    }
                }
            }
            if (!$dayMin || !$dayMax) continue; // safety

            // Generate 5-min slots for THIS day only
            $activeUntil = [];
            $t = $dayMin->copy();
            while ($t < $dayMax) {
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
                        $it    = $items[0];
                        $start = \Illuminate\Support\Carbon::parse($it['start']);
                        $end   = \Illuminate\Support\Carbon::parse($it['end']);
                        $span  = max(1, (int)($start->diffInMinutes($end) / 5));
                        $activeUntil[$key] = $end;

                        $row['cells'][$key] = ['render'=>true,'rowspan'=>$span,'colspan'=>1,'text'=>$it['text']];
                    } else {
                        $row['cells'][$key] = ['render'=>true,'rowspan'=>1,'colspan'=>1,'text'=>''];
                    }
                }

                $rows[] = $row;
                $t->addMinutes(5);
            }

            // Close the day: visible separator + one empty raster row at day end
            // NEU: erst leere Zeile, dann dunkler Tages-Trenner
            $rows[] = $this->emptyRow($dayMax, $headerKeys);
            $rows[] = ['separator' => true, 'variant' => 'day']; // style-hint für "dunkel"
        }

        return $rows;
    }






    public function buildTeamsMatrix(Collection $activities): array
    {
        // 1) Load ONLY the team-roles per program (for visibility) 
        $teamRoles = DB::table('m_role')
            ->whereNotNull('first_program')
            ->where('preview_matrix', 1)
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
        $rows = $this->buildRowsPerActiveDay($headers, $bucket);
        return ['headers'=>$headers, 'rows'=>$rows];
        
    }



    
    public function buildRoomsMatrix(Collection $activities): array
    {
        // 1) Load roles (only used in schedule matrix) and filter out table-differentiated roles
        $roles = DB::table('m_role')
            ->whereNotNull('first_program')
            ->where('preview_matrix', 1)
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
        $minStart = \Illuminate\Support\Carbon::parse($acts->min('start_time'))->startOfMinute();
        $maxEnd   = \Illuminate\Support\Carbon::parse($acts->max('end_time'))->startOfMinute();

        // 3) **NEU**: Räume & Room-Types bestimmen
        // 3a) Räume, die tatsächlich vorkommen (room_id > 0), sortiert nach room_type_sequence, dann room_name
        $rooms = $acts
            ->filter(fn($a) => (int)($a->room_id ?? 0) > 0)
            ->groupBy('room_id')
            ->map(function ($grp) {
                $first = $grp->first();
                return [
                    'room_id'   => (int)$first->room_id,
                    'room_name' => (string)($first->room_name ?? ('Room '.$first->room_id)),
                    'rt_id'     => (int)($first->room_type_id ?? 0),
                    'rt_seq'    => (int)($first->room_type_sequence ?? 0),
                ];
            })
            ->values()
            ->sortBy([['rt_seq','asc'],['room_name','asc']])
            ->all();

        // 3b) Room-Types ohne zugeordneten Raum (room_id leer), die vorkommen
        $roomTypesNoRoom = $acts
            ->filter(fn($a) => (int)($a->room_id ?? 0) === 0 && (int)($a->room_type_id ?? 0) > 0)
            ->groupBy('room_type_id')
            ->map(function ($grp) {
                $first = $grp->first();
                return [
                    'id'       => (int)$first->room_type_id,
                    'name'     => (string)($first->room_type_name ?? ('RoomType '.$first->room_type_id)),
                    'sequence' => (int)($first->room_type_sequence ?? 0),
                ];
            })
            ->values()
            ->sortBy('sequence')
            ->all();

        // 4) **NEU** Headers: Zeit + Räume + Room-Types (ohne Raum)
        $headers = [['key'=>'time','title'=>'Zeit']];
        foreach ($rooms as $r) {
            $headers[] = ['key' => 'room_'.$r['room_id'], 'title' => $r['room_name']];
        }
        foreach ($roomTypesNoRoom as $rt) {
            $headers[] = [
                'key'   => 'roomtype_'.$rt['id'],
                'title' => '['.$rt['name'].']',   // <--- NEU: Klammern
            ];
        }
        $headerKeys = array_map(fn($h) => $h['key'], $headers);

        // 5) Buckets per column + start minute
        $bucket = [];
        $push = function(string $colKey, \Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $end, string $text) use (&$bucket) {
            $k = $start->toDateTimeString();
            $bucket[$colKey][$k] = $bucket[$colKey][$k] ?? [];
            $bucket[$colKey][$k][] = ['start'=>$start->toDateTimeString(), 'end'=>$end->toDateTimeString(), 'text'=>$text];
        };

        foreach ($acts as $a) {
            $start = \Illuminate\Support\Carbon::parse($a->start_time)->startOfMinute();
            $end   = \Illuminate\Support\Carbon::parse($a->end_time)->startOfMinute();

            // Base text (hard override for "Mit Team")
            $base = (string)$a->activity_name;
            if (trim($base) === 'Mit Team') {
                $prog = strtoupper((string)$a->program_name);
                if ($prog === 'EXPLORE')   { $base = 'Begutachtung'; }
                elseif ($prog === 'CHALLENGE') { $base = 'Jury'; }
            }

            // **NEU**: Key je nach room_id / room_type_id
            $rid = (int)($a->room_id ?? 0);
            if ($rid > 0) {
                $push('room_'.$rid, $start, $end, $base);
            } else {
                $rtId = (int)($a->room_type_id ?? 0);
                if ($rtId > 0) {
                    $push('roomtype_'.$rtId, $start, $end, $base);
                }
            }
        }

        // 6) Raster rows (5-min) + day separators + extra empty row after day & at end
        $rows = $this->buildRowsPerActiveDay($headers, $bucket);
        return ['headers'=>$headers, 'rows'=>$rows];
    }




}
