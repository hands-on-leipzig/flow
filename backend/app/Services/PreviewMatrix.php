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

    // Erlaubte RC-Spalten pro Programm (nur wenn es echte RC-Aktivitäten gibt)
    $rcAtdId = defined('ID_ATD_R_CHECK') ? (int) ID_ATD_R_CHECK : (int) config('atd.ids.robot_check', 0);

    $rcTablesByProg = ['E' => [], 'C' => []];

    foreach ($activities as $a) {
        if ((int)$a->activity_type_detail_id !== $rcAtdId) {
            continue; // kein Robot-Check
        }
        // Programm ableiten
        $p = strtoupper((string) $a->program_name) === 'EXPLORE' ? 'E' : 'C';

        // Über beide Tabellenspalten gehen
        foreach ([1, 2] as $ti) {
            $t = (int) ($a->{'table_'.$ti} ?? 0);
            if ($t > 0) {
                $rcTablesByProg[$p][$t] = true; // Merken: in diesem Programm gab es RC auf Tisch t
            }
        }
    }    

    // 3b) TABLE-Rollen: in 2er-Blöcken RC→RG pro Programm
    foreach (['E', 'C'] as $progLetter) {
        if (empty($tablesUsed)) continue;

        $rcRole = $tableRolesByProg[$progLetter]['RC'] ?? null;
        $rgRole = $tableRolesByProg[$progLetter]['RG'] ?? null;

        // andere table-basierte Rollen sammeln (ohne RC/RG) – unverändert
        $otherTableRoles = [];
        foreach ($roles as $r) {
            $pL = ((int)$r->first_program === 2) ? 'E' : 'C';
            if ($pL !== $progLetter) continue;
            if ($r->differentiation_parameter !== 'table') continue;
            $sk = strtoupper(substr((string)($r->name_short ?: $r->name), 0, 2));
            if ($sk === 'RC' || $sk === 'RG') continue;
            $otherTableRoles[] = [$r, $sk];
        }

        // Tische in 2er-Blöcke: [t1,t2], [t3,t4], ...
        $blocks = array_chunk(array_values($tablesUsed), 2);

        foreach ($blocks as $block) {
            // 1) RC-Spalten NUR, wenn es mindestens einen echten RC auf dem Tisch gab
            if ($rcRole) {
                $titleBase = (string)($rcRole->name_short ?: $rcRole->name);
                foreach ($block as $t) {
                    if (!empty($rcTablesByProg[$progLetter][$t])) {
                        $addHeader([
                            'key'   => strtolower($progLetter) . '_RC' . 't' . $t,
                            'title' => "{$titleBase}{$t}",
                        ]);
                    }
                }
            }

            // 2) RG-Spalten immer (so wie zuvor, nur blockweise)
            if ($rgRole) {
                $titleBase = (string)($rgRole->name_short ?: $rgRole->name);
                foreach ($block as $t) {
                    $addHeader([
                        'key'   => strtolower($progLetter) . '_RG' . 't' . $t,
                        'title' => "{$titleBase}{$t}",
                    ]);
                }
            }

            // 3) weitere table-basierte Rollen (falls vorhanden)
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
        $teamRoles = DB::table('m_role')
            ->whereNotNull('first_program')
            ->where('preview_matrix', 1)
            ->where('differentiation_parameter', 'team')
            ->select('first_program','name_short')
            ->get();

        if ($activities->isEmpty() || $teamRoles->isEmpty()) {
            return [
                'headers' => [['key' => 'time', 'title' => 'Zeit']],
                'rows'    => [['separator' => true]],
            ];
        }

        // Teams sammeln
        $collectTeams = function(string $program) use ($activities) {
            return $activities
                ->filter(fn($a) => strtoupper((string)$a->program_name) === $program)
                ->flatMap(fn($a) => [
                    (int)($a->team ?? 0),
                    (int)($a->table_1_team ?? 0),
                    (int)($a->table_2_team ?? 0),
                ])
                ->filter(fn($n) => $n > 0)
                ->unique()
                ->sort()
                ->values()
                ->all();
        };

        $exTeams = $collectTeams('EXPLORE');
        $chTeams = $collectTeams('CHALLENGE');

        // Header bauen
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

        // Schlüssel-Auflösung
        $resolveKey = function($a, string $base) use ($exTeams, $chTeams) {
            $prog = strtoupper((string)$a->program_name);
            if ($prog !== 'EXPLORE' && $prog !== 'CHALLENGE') return [];

            $colPrefix = $prog === 'EXPLORE' ? 'ex_t' : 'ch_t';
            $teamsAll  = $prog === 'EXPLORE' ? $exTeams : $chTeams;

            $teamsInActivity = collect([
                (int)($a->team ?? 0),
                (int)($a->table_1_team ?? 0),
                (int)($a->table_2_team ?? 0),
            ])->filter(fn($n)=>$n>0)->unique()->all();

            $keys = [];
            if (!empty($teamsInActivity)) {
                foreach ($teamsInActivity as $tn) {
                    if (in_array($tn, $teamsAll, true)) {
                        $keys[] = $colPrefix . str_pad((string)$tn, 2, '0', STR_PAD_LEFT);
                    }
                }
            } else {
                foreach ($teamsAll as $tn) {
                    $keys[] = $colPrefix . str_pad((string)$tn, 2, '0', STR_PAD_LEFT);
                }
            }
            return $keys;
        };

        return $this->bucketizeActivities($activities, $headers, $resolveKey);
    }



        
    public function buildRoomsMatrix(Collection $activities): array
    {
        if ($activities->isEmpty()) {
            return [
                'headers' => [['key' => 'time', 'title' => 'Zeit']],
                'rows'    => [['separator' => true]],
            ];
        }

        // Räume mit IDs
        $rooms = $activities
            ->filter(fn($a) => (int)($a->room_id ?? 0) > 0)
            ->groupBy('room_id')
            ->map(function ($grp) {
                $first = $grp->first();
                return [
                    'room_id'   => (int)$first->room_id,
                    'room_name' => (string)($first->room_name ?? ('Room '.$first->room_id)),
                    'rt_seq'    => (int)($first->room_type_sequence ?? 0),
                ];
            })
            ->values()
            ->sortBy([['rt_seq','asc'],['room_name','asc']])
            ->all();

        // Raumtypen ohne konkrete Räume
        $roomTypesNoRoom = $activities
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

        // Header
        $headers = [['key' => 'time', 'title' => 'Zeit']];
        foreach ($rooms as $r) {
            $headers[] = ['key' => 'room_'.$r['room_id'], 'title' => $r['room_name']];
        }
        foreach ($roomTypesNoRoom as $rt) {
            $headers[] = ['key' => 'roomtype_'.$rt['id'], 'title' => '['.$rt['name'].']'];
        }

        // Schlüssel-Auflösung
        $resolveKey = function($a, string $base) {
            $keys = [];
            if ((int)($a->room_id ?? 0) > 0) {
                $keys[] = 'room_'.$a->room_id;
            } elseif ((int)($a->room_type_id ?? 0) > 0) {
                $keys[] = 'roomtype_'.$a->room_type_id;
            }
            return $keys;
        };

        return $this->bucketizeActivities($activities, $headers, $resolveKey);
    }

    private function bucketizeActivities(Collection $activities, array $headers, callable $resolveKey): array
    {
        $bucket = [];
        $push = function(string $colKey, \Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $end, string $text) use (&$bucket) {
            $k = $start->toDateTimeString();
            $bucket[$colKey][$k] = $bucket[$colKey][$k] ?? [];
            $bucket[$colKey][$k][] = [
                'start' => $start->toDateTimeString(),
                'end'   => $end->toDateTimeString(),
                'text'  => $text,
            ];
        };

        foreach ($activities as $a) {
            $start = \Illuminate\Support\Carbon::parse($a->start_time)->startOfMinute();
            $end   = \Illuminate\Support\Carbon::parse($a->end_time)->startOfMinute();

            // Text mit Override
            $base = (string)$a->activity_name;
            if (stripos($base, 'mit team') !== false) {
                $prog = strtoupper((string)$a->program_name);
                $base = $prog === 'EXPLORE' ? 'Begutachtung' : ($prog === 'CHALLENGE' ? 'Jury' : $base);
            }

            // Spaltenkeys bestimmen und pushen
            foreach ($resolveKey($a, $base) as $colKey) {
                $push($colKey, $start, $end, $base);
            }
        }

        $rows = $this->buildRowsPerActiveDay($headers, $bucket);
        return ['headers' => $headers, 'rows' => $rows];
    }


}
