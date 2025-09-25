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
public function buildRolesMatrix(\Illuminate\Support\Collection $activities, \Illuminate\Support\Collection $roles): array
{
    // Safety
    if ($activities->isEmpty() || $roles->isEmpty()) {
        return [
            'headers' => [['key' => 'time', 'title' => 'Zeit']],
            'rows'    => [['separator' => true]],
        ];
    }

    // --- Ableitungen aus echten Activities
    $exLaneMax = (int)($activities->where('program_name', 'EXPLORE')->pluck('lane')->filter()->max() ?? 0);
    $chLaneMax = (int)($activities->where('program_name', 'CHALLENGE')->pluck('lane')->filter()->max() ?? 0);

    // verwendete Tische (1..4), nur die, die tatsächlich vorkommen
    $tablesUsed = collect([1,2,3,4])->filter(function ($t) use ($activities) {
        return $activities->contains(fn($a) => ((int)$a->table_1 === $t) || ((int)$a->table_2 === $t));
    })->values()->all();
    sort($tablesUsed);

    // --- Sichtbarkeiten (welche Rollen sehen welche ATDs)
    $visibility = DB::table('m_visibility')->get(); // Spalten: role, activity_type_detail

    // --- Header vorbereiten
    $headers  = [['key' => 'time', 'title' => 'Zeit']];
    $usedKeys = [];
    $addHeader = function (array $h) use (&$headers, &$usedKeys) {
        if (!isset($h['key'], $h['title'])) return;
        if (!isset($usedKeys[$h['key']])) {
            $headers[] = $h;
            $usedKeys[$h['key']] = true;
        }
    };

    // --- Rollen vorsortieren
    // Wir erwarten hier NUR lane/table-Rollen (Controller filtert bereits so),
    // bauen aber robust dennoch nach differentiation_parameter.
    $laneRoles        = [];              // [[$progLetter, $role, $shortKey], ...]
    $tableRolesByProg = ['E' => [], 'C' => []]; // z.B. ['RC' => $roleObj, 'RG' => $roleObj, ...]

    foreach ($roles as $role) {
        $progLetter = ((int)$role->first_program === 2) ? 'E' : 'C';
        $baseShort  = (string)($role->name_short ?: $role->name);
        $shortKey   = strtoupper(substr($baseShort, 0, 2));

        if ($role->differentiation_parameter === 'lane') {
            $laneRoles[] = [$progLetter, $role, $shortKey];
        } elseif ($role->differentiation_parameter === 'table') {
            $tableRolesByProg[$progLetter][$shortKey] = $role;
        }
    }

    // --- RC-Erkennung nur bei echten Robot-Check-Activities
    $rcAtdId = defined('ID_ATD_R_CHECK') ? (int) ID_ATD_R_CHECK : (int) config('atd.ids.robot_check', 0);
    $rcTablesByProg = ['E' => [], 'C' => []];

    foreach ($activities as $a) {
        if ((int)$a->activity_type_detail_id !== $rcAtdId) continue;
        $p = strtoupper((string)$a->program_name) === 'EXPLORE' ? 'E' : 'C';
        foreach ([1, 2] as $ti) {
            $t = (int) ($a->{'table_'.$ti} ?? 0);
            if ($t > 0) $rcTablesByProg[$p][$t] = true;
        }
    }

    // --- 1) Lane/Judging-Spalten (E links, C rechts), je Programm 1..laneMax
    foreach ($laneRoles as [$progLetter, $role, $shortKey]) {
        $titleBase = (string)($role->name_short ?: $role->name);
        $laneMax   = ($progLetter === 'E') ? $exLaneMax : $chLaneMax;

        for ($i = 1; $i <= $laneMax; $i++) {
            $addHeader([
                'key'   => strtolower($progLetter) . '_' . $shortKey . $i, // e.g. e_JU1
                'title' => "{$titleBase}{$i}",
            ]);
        }
    }

    // --- 2) Table-Spalten in 2er-Blöcken: RC (nur wenn vorhanden) → RG (immer)
    foreach (['E', 'C'] as $progLetter) {
        if (empty($tablesUsed)) continue;

        $rcRole = $tableRolesByProg[$progLetter]['RC'] ?? null;
        $rgRole = $tableRolesByProg[$progLetter]['RG'] ?? null;

        // weitere table-basierte Rollen (ohne RC/RG) nachziehen
        $otherTableRoles = [];
        foreach ($tableRolesByProg[$progLetter] as $sk => $r) {
            if ($sk === 'RC' || $sk === 'RG') continue;
            $otherTableRoles[] = [$r, $sk];
        }

        $blocks = array_chunk($tablesUsed, 2); // [t1,t2], [t3,t4], ...

        foreach ($blocks as $block) {
            // RC nur mit realen Activities pro Tisch
            if ($rcRole) {
                $titleBase = (string)($rcRole->name_short ?: $rcRole->name);
                foreach ($block as $t) {
                    if (!empty($rcTablesByProg[$progLetter][$t])) {
                        $addHeader([
                            'key'   => strtolower($progLetter) . '_RC' . 't' . $t, // e.g. e_RCt1
                            'title' => "{$titleBase}{$t}",
                        ]);
                    }
                }
            }

            // RG immer
            if ($rgRole) {
                $titleBase = (string)($rgRole->name_short ?: $rgRole->name);
                foreach ($block as $t) {
                    $addHeader([
                        'key'   => strtolower($progLetter) . '_RG' . 't' . $t, // e.g. e_RGt1
                        'title' => "{$titleBase}{$t}",
                    ]);
                }
            }

            // andere table-basierte Rollen
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

    // --- 3) Activities in Buckets einsortieren (sichtbarkeitsgesteuert!)
    $bucket = [];

    foreach ($activities as $a) {
        $atdId   = (int) $a->activity_type_detail_id;
        $visible = $visibility->where('activity_type_detail', $atdId);

        $start   = \Illuminate\Support\Carbon::parse($a->start_time)->startOfMinute();
        $end     = \Illuminate\Support\Carbon::parse($a->end_time)->startOfMinute();

        // Basistext inkl. Spezialfall "Mit Team"
        $baseText = (string) $a->activity_name;
        if (stripos($baseText, 'mit team') !== false) {
            $progName = strtoupper((string)$a->program_name);
            $baseText = $progName === 'EXPLORE' ? 'Begutachtung' : ($progName === 'CHALLENGE' ? 'Jury' : $baseText);
        }

        foreach ($visible as $vr) {
            /** @var object|null $role */
            $role = $roles->firstWhere('id', $vr->role);
            if (!$role) continue;

            $progLetter = ((int)$role->first_program === 2) ? 'E' : 'C';
            $baseShort  = (string)($role->name_short ?: $role->name);
            $shortKey   = strtoupper(substr($baseShort, 0, 2));

            if ($role->differentiation_parameter === 'lane') {
                $lane   = (int)($a->lane ?? 0);
                $teamNo = (int)($a->team ?? 0);
                $text   = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');

                if ($lane > 0) {
                    $key = strtolower($progLetter) . '_' . $shortKey . $lane;
                    $this->push($bucket, $key, $start, $end, $text);
                } else {
                    // Kein Lane gesetzt → in alle vorhandenen Lanes des Programms duplizieren
                    $laneMax = ($progLetter === 'E') ? $exLaneMax : $chLaneMax;
                    for ($i = 1; $i <= $laneMax; $i++) {
                        $key = strtolower($progLetter) . '_' . $shortKey . $i;
                        $this->push($bucket, $key, $start, $end, $text);
                    }
                }

            } elseif ($role->differentiation_parameter === 'table') {
                $pushed = false;
                foreach ([1, 2] as $ti) {
                    $tNo = (int)($a->{'table_'.$ti} ?? 0);
                    if ($tNo > 0) {
                        $teamNo = (int)($a->{'table_'.$ti.'_team'} ?? 0);
                        $text   = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');
                        $key    = strtolower($progLetter) . '_' . $shortKey . 't' . $tNo; // z.B. e_RGt2 oder e_RCt1
                        $this->push($bucket, $key, $start, $end, $text);
                        $pushed = true;
                    }
                }
                if (!$pushed) {
                    // kein konkreter Tisch → in alle vorhandenen Tische duplizieren
                    $teamNo = (int)($a->team ?? 0);
                    $text   = $baseText . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');
                    foreach ($tablesUsed as $t) {
                        $key = strtolower($progLetter) . '_' . $shortKey . 't' . $t;
                        $this->push($bucket, $key, $start, $end, $text);
                    }
                }
            }
            // (andere differentiation_parameter werden ignoriert; Controller liefert lane/table)
        }
    }

    // --- 4) Leere Spalten entfernen, Reihenfolge beibehalten
    $activeKeys   = array_keys($bucket);
    $finalHeaders = [];
    foreach ($headers as $h) {
        if ($h['key'] === 'time' || in_array($h['key'], $activeKeys, true)) {
            $finalHeaders[] = $h;
        }
    }

    // --- 5) Rows bauen
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

        // Schlüssel-Auflösung inkl. Suffixe (Jxx, Txx)
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

            $baseText = (string)$a->activity_name;
            $suffix   = '';

            if (stripos($baseText, 'jury') !== false) {
                $juryNo = (int)($a->lane ?? 0);
                if ($juryNo > 0) {
                    $suffix = ' J'.str_pad((string)$juryNo, 2, '0', STR_PAD_LEFT);
                }
            } elseif (
                stripos($baseText, 'check') !== false ||
                stripos($baseText, 'match') !== false
            ) {
                $tableNos = collect([
                    (int)($a->table_1 ?? 0),
                    (int)($a->table_2 ?? 0),
                ])->filter(fn($n)=>$n>0)->unique()->all();

                if (!empty($tableNos)) {
                    $suffix = ' ' . implode(' ', array_map(fn($t) => 'T'.str_pad((string)$t, 2, '0', STR_PAD_LEFT), $tableNos));
                }
            }

            $result = [];
            if (!empty($teamsInActivity)) {
                foreach ($teamsInActivity as $tn) {
                    if (in_array($tn, $teamsAll, true)) {
                        $result[] = [
                            'key'  => $colPrefix . str_pad((string)$tn, 2, '0', STR_PAD_LEFT),
                            'text' => $baseText . $suffix,
                        ];
                    }
                }
            } else {
                foreach ($teamsAll as $tn) {
                    $result[] = [
                        'key'  => $colPrefix . str_pad((string)$tn, 2, '0', STR_PAD_LEFT),
                        'text' => $baseText . $suffix,
                    ];
                }
            }
            return $result;
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

        // Schlüssel-Auflösung mit Text
        $resolveKey = function($a, string $base) {
            $baseText = (string)$a->activity_name;
            $keys = [];

            if ((int)($a->room_id ?? 0) > 0) {
                $keys[] = [
                    'key'  => 'room_'.$a->room_id,
                    'text' => $baseText,
                ];
            } elseif ((int)($a->room_type_id ?? 0) > 0) {
                $keys[] = [
                    'key'  => 'roomtype_'.$a->room_type_id,
                    'text' => $baseText,
                ];
            }
            return $keys;
        };

        return $this->bucketizeActivities($activities, $headers, $resolveKey);
    }

    private function bucketizeActivities(Collection $activities, array $headers, callable $resolveKey): array
    {
        $bucket = [];

        foreach ($activities as $a) {
            $start = \Illuminate\Support\Carbon::parse($a->start_time)->startOfMinute();
            $end   = \Illuminate\Support\Carbon::parse($a->end_time)->startOfMinute();

            $resolved = $resolveKey($a, (string)$a->activity_name);

            foreach ($resolved as $r) {
                $colKey = $r['key'];
                $text   = $r['text'];

                $k = $start->toDateTimeString();
                $bucket[$colKey][$k] = $bucket[$colKey][$k] ?? [];
                $bucket[$colKey][$k][] = [
                    'start' => $start->toDateTimeString(),
                    'end'   => $end->toDateTimeString(),
                    'text'  => $text,
                ];
            }
        }

        $rows = $this->buildRowsPerActiveDay($headers, $bucket);
        return ['headers'=>$headers, 'rows'=>$rows];
    }


}
