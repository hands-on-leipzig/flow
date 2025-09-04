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

    // --- 3. Build headers dynamically (interleaved RC/RG per table)
    $headers  = [['key' => 'time', 'title' => 'Zeit']];
    $usedKeys = []; // zur Sicherheit: Duplikate verhindern
    $headerMeta = []; // <-- ergänzen: Titel je Key mitführen
    $addHeader = function (array $h) use (&$headers, &$usedKeys) {
        if (!isset($h['key']) || !isset($h['title'])) {
            return;
        }
        if (!isset($usedKeys[$h['key']])) {
            $headers[] = $h;
            $usedKeys[$h['key']] = true;
        }
    };

    // tablesUsed sortiert/unique (falls nicht schon vorher geschehen)
    $tablesUsed = array_values(array_unique($tablesUsed));
    sort($tablesUsed);

    // Rollen vorsortieren in Eimer
    $tableRolesByProg = [ 'E' => [], 'C' => [] ]; // z.B. ['RC' => $roleObj, 'RG' => $roleObj, ...]
    $laneRoles        = [];                       // [[$progLetter, $role, $shortKey], ...]
    $simpleRoles      = [];                       // [[$progLetter, $role, $shortKey], ...]

    foreach ($roles as $role) {
        $progLetter = ((int)$role->first_program === 2) ? 'E' : 'C';
        // shortKey aus name_short (wenn vorhanden), sonst aus name
        $baseShort  = (string)($role->name_short ?: $role->name);
        $shortKey   = strtoupper(substr($baseShort, 0, 2));

        if ($role->differentiation_parameter === 'lane') {
            $laneRoles[] = [$progLetter, $role, $shortKey];
        } elseif ($role->differentiation_parameter === 'table') {
            $tableRolesByProg[$progLetter][$shortKey] = $role;
        } else {
            $simpleRoles[] = [$progLetter, $role, $shortKey];
        }
    }

    // 3a) LANE-Rollen (E/C separat, Anzahl je Programm)
    foreach ($laneRoles as [$progLetter, $role, $shortKey]) {
        $titleBase = (string)($role->name_short ?: $role->name);
        $laneMax   = ($progLetter === 'E') ? $exLaneMax : $chLaneMax;

        for ($i = 1; $i <= $laneMax; $i++) {
            $addHeader([
                'key'   => strtolower($progLetter) . '_' . $shortKey . $i, // e.g. c_RC1
                'title' => "{$titleBase}{$i}",
            ]);
        }
    }

    // 3b) TABLE-Rollen: in 2er-Blöcken RC→…→RG pro Programm
    foreach (['E', 'C'] as $progLetter) {
        if (empty($tablesUsed)) {
            continue;
        }

        $rcRole = $tableRolesByProg[$progLetter]['RC'] ?? null;
        $rgRole = $tableRolesByProg[$progLetter]['RG'] ?? null;

        // „Andere“ table-basierte Rollen stabil in Originalreihenfolge (ohne RC/RG)
        $otherTableRoles = [];
        foreach ($roles as $r) {
            $pL = ((int)$r->first_program === 2) ? 'E' : 'C';
            if ($pL !== $progLetter) continue;
            if ($r->differentiation_parameter !== 'table') continue;

            $sk = strtoupper(substr((string)($r->name_short ?: $r->name), 0, 2));
            if ($sk === 'RC' || $sk === 'RG') continue;
            $otherTableRoles[] = [$r, $sk];
        }

        // In 2er-Blöcke schneiden: [t1,t2], [t3,t4], ...
        $blocks = array_chunk($tablesUsed, 2);

        foreach ($blocks as $block) {
            // 1) RC für alle Tische im Block
            if ($rcRole) {
                $titleBase = (string)($rcRole->name_short ?: $rcRole->name);
                foreach ($block as $t) {
                    $addHeader([
                        'key'   => strtolower($progLetter) . '_RC' . 't' . $t, // z. B. c_RCt1
                        'title' => "{$titleBase}{$t}",
                    ]);
                }
            }

            // 2) RG für alle Tische im Block
            if ($rgRole) {
                $titleBase = (string)($rgRole->name_short ?: $rgRole->name);
                foreach ($block as $t) {
                    $addHeader([
                        'key'   => strtolower($progLetter) . '_RG' . 't' . $t, // z. B. c_RGt1
                        'title' => "{$titleBase}{$t}",
                    ]);
                }
            }

            // 3) Andere table-basierte Rollen (falls vorhanden) für die Tische im Block
            foreach ($otherTableRoles as [$r, $sk]) {
                $titleBase = (string)($r->name_short ?: $r->name);
                foreach ($block as $t) {
                    $addHeader([
                        'key'   => strtolower($progLetter) . '_' . $sk . 't' . $t,
                        'title' => "{$titleBase}{$t}",
                    ]);
                }
            }
        }
    }

    // 3c) SIMPLE-Rollen (ohne lane/table)
    foreach ($simpleRoles as [$progLetter, $role, $shortKey]) {
        $titleBase = (string)($role->name_short ?: $role->name);
        $addHeader([
            'key'   => strtolower($progLetter) . '_' . $shortKey,
            'title' => $titleBase,
        ]);
    }

    // am Ende wie gehabt:
    $headerKeys = array_map(fn($h) => $h['key'], $headers);

    // ---- 4) Activities in Buckets einsortieren (NICHT $headers/$bucket resetten!)
    $bucket = [];  // nur hier initialisieren, nicht später überschreiben!

    foreach ($activities as $a) {
        $atd = (int)$a->activity_type_detail_id;
        $visibleRoles = $visibility->where('activity_type_detail', $atd);

        foreach ($visibleRoles as $vr) {
            $role = $roles->firstWhere('id', $vr->role);
            if (!$role) continue;

            $progLetter = ((int)$role->first_program === 2) ? 'E' : 'C';
            $baseShort  = (string)($role->name_short ?: $role->name);
            $shortKey   = strtoupper(substr($baseShort, 0, 2));

            $start = \Illuminate\Support\Carbon::parse($a->start_time)->startOfMinute();
            $end   = \Illuminate\Support\Carbon::parse($a->end_time)->startOfMinute();
            $baseText = (string)$a->activity_name;

            if ($role->differentiation_parameter === 'lane') {
                $lane = (int)($a->lane ?? 0);
                $teamNo = (int)($a->team ?? 0);
                $text = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');
                if ($lane > 0) {
                    $key = strtolower($progLetter).'_'.$shortKey.$lane;
                    $this->push($bucket, $key, $start, $end, $text);
                } else {
                    $laneMax = ($progLetter === 'E') ? $exLaneMax : $chLaneMax;
                    for ($i = 1; $i <= $laneMax; $i++) {
                        $this->push($bucket, strtolower($progLetter).'_'.$shortKey.$i, $start, $end, $text);
                    }
                }

            } elseif ($role->differentiation_parameter === 'table') {
                $pushed = false;
                foreach ([1,2] as $ti) {
                    $tNo = (int)($a->{'table_'.$ti} ?? 0);
                    if ($tNo > 0) {
                        $teamNo = (int)($a->{'table_'.$ti.'_team'} ?? 0);
                        $text   = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');
                        $key    = strtolower($progLetter).'_'.$shortKey.'t'.$tNo;
                        $this->push($bucket, $key, $start, $end, $text);
                        $pushed = true;
                    }
                }
                if (!$pushed) {
                    $teamNo = (int)($a->team ?? 0);
                    $text   = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');
                    foreach ($tablesUsed as $t) {
                        $key = strtolower($progLetter).'_'.$shortKey.'t'.$t;
                        $this->push($bucket, $key, $start, $end, $text);
                    }
                }

            } elseif ($role->differentiation_parameter === 'team') {
                $hasTeam =
                    (int)($a->team ?? 0) > 0 ||
                    (int)($a->table_1_team ?? 0) > 0 ||
                    (int)($a->table_2_team ?? 0) > 0;
                if (!$hasTeam) {
                    $key = strtolower($progLetter).'_'.$shortKey;
                    $this->push($bucket, $key, $start, $end, $baseText);
                }

            } else {
                $key = strtolower($progLetter).'_'.$shortKey;
                $this->push($bucket, $key, $start, $end, $baseText);
            }
        }
    }

    // ---- 5) Leere Spalten entfernen – Reihenfolge der bereits interleaveten $headers beibehalten
    $activeKeys = array_keys($bucket);
    $finalHeaders = [];
    foreach ($headers as $h) {
        if ($h['key'] === 'time' || in_array($h['key'], $activeKeys, true)) {
            // Titel aus headerMeta absichern (falls gewünscht)
            $h['title'] = $headerMeta[$h['key']] ?? $h['title'];
            $finalHeaders[] = $h;
        }
    }

    // ---- 6) Rows bauen
    $rows = $this->buildRowsPerActiveDay($finalHeaders, $bucket);
    return ['headers' => $finalHeaders, 'rows' => $rows];
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
