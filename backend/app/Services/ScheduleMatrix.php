<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ScheduleMatrix
{
    /**
     * Rollen-Matrix (4a) als JSON-Struktur.
     */
    public function buildRolesMatrix(Collection $activities): array
    {
        $cfgAll = $this->resolveConstants(config('atd'));
        $cfg = array_merge([
            'ex_be'   => [], 'ex_te'   => [], 'ex_gu'   => [], 'ex_gn'   => [],
            'ch_be'   => [], 'ch_te'   => [], 'ch_ju'   => [], 'ch_jn'   => [],
            'rg_sr'   => [], 'rg_match'=> [], 'rc'      => [],
        ], $cfgAll);

        if ($activities->isEmpty()) {
            return [
                'headers' => [
                    ['key'=>'time','title'=>'Zeit'],
                    ['key'=>'ex_be','title'=>'Ex Be'],
                    ['key'=>'ex_te','title'=>'Ex Te'],
                    ['key'=>'ex_gu','title'=>'Ex Gu'],
                    ['key'=>'ch_be','title'=>'Ch Be'],
                    ['key'=>'ch_te','title'=>'Ch Te'],
                    ['key'=>'ch_ju','title'=>'Ch Ju'],
                    ['key'=>'rg_sr','title'=>'RG SR'],
                    ['key'=>'rg_t1','title'=>'RG T1'],
                    ['key'=>'rg_t2','title'=>'RG T2'],
                ],
                'rows' => [
                    ['separator' => true], // sichtbarer Trenner
                    // zusätzliche leere Raster-Zeile (5-Minuten)
                    $this->emptyRow(Carbon::now()->startOfMinute(), [
                        'time','ex_be','ex_te','ex_gu','ch_be','ch_te','ch_ju','rg_sr','rg_t1','rg_t2'
                    ]),
                ],
            ];
        }

        // 1) Zeitfenster
        $minStart = Carbon::parse($activities->min('start_time'))->startOfMinute();
        $maxEnd   = Carbon::parse($activities->max('end_time'))->startOfMinute();

        // 2) Lanes & Tische
        $exLaneMax = (int)($activities->whereIn('activity_type_detail_id', $cfg['ex_gn'])->pluck('lane')->filter()->max() ?? 0);
        $chLaneMax = (int)($activities->whereIn('activity_type_detail_id', $cfg['ch_jn'])->pluck('lane')->filter()->max() ?? 0);

        $tablesUsed = collect([1,2,3,4])->filter(function ($t) use ($activities) {
            return $activities->contains(fn($a) => ((int)$a->table_1 === $t) || ((int)$a->table_2 === $t));
        })->values()->all();

        $hasRC = !empty($cfg['rc']) && $activities->contains(fn($a) => in_array($a->activity_type_detail_id, $cfg['rc'], true));

        // 3) Header
        $headers = [
            ['key'=>'time','title'=>'Zeit'],
            ['key'=>'ex_be','title'=>'Ex Be'],
            ['key'=>'ex_te','title'=>'Ex Te'],
            ['key'=>'ex_gu','title'=>'Ex Gu'],
        ];
        for ($i=1; $i <= $exLaneMax; $i++) $headers[] = ['key'=>"ex_g{$i}", 'title'=>"Ex G{$i}"];
        $headers = array_merge($headers, [
            ['key'=>'ch_be','title'=>'Ch Be'],
            ['key'=>'ch_te','title'=>'Ch Te'],
            ['key'=>'ch_ju','title'=>'Ch Ju'],
        ]);
        for ($i=1; $i <= $chLaneMax; $i++) $headers[] = ['key'=>"ch_j{$i}", 'title'=>"Ch J{$i}"];
        $headers[] = ['key'=>'rg_sr','title'=>'RG SR'];

        if ($hasRC) { foreach ([1,2] as $t) $headers[] = ['key'=>"rc_t{$t}", 'title'=>"RC T{$t}"]; }
        foreach ([1,2] as $t) $headers[] = ['key'=>"rg_t{$t}", 'title'=>"RG T{$t}"];
        if ($hasRC) { foreach ([3,4] as $t) if (in_array($t,$tablesUsed,true)) $headers[] = ['key'=>"rc_t{$t}", 'title'=>"RC T{$t}"]; }
        foreach ([3,4] as $t) if (in_array($t,$tablesUsed,true)) $headers[] = ['key'=>"rg_t{$t}", 'title'=>"RG T{$t}"];

        // 4) Buckets
        $bucket = [];
        foreach ($activities as $a) {
            $name  = preg_replace('/-/', '- ', (string)$a->activity_name);
            $start = Carbon::parse($a->start_time)->startOfMinute();
            $end   = Carbon::parse($a->end_time)->startOfMinute();

            $fmtT = static function ($n) { $n=(int)$n; return $n>0 ? ' T'.str_pad((string)$n,2,'0',STR_PAD_LEFT) : ''; };

            // Explore
            if (in_array($a->activity_type_detail_id, $cfg['ex_be'], true)) $this->push($bucket, 'ex_be', $start, $end, $name);
            if (in_array($a->activity_type_detail_id, $cfg['ex_te'], true)) $this->push($bucket, 'ex_te', $start, $end, $name);
            if (in_array($a->activity_type_detail_id, $cfg['ex_gu'], true)) $this->push($bucket, 'ex_gu', $start, $end, $name);
            if (in_array($a->activity_type_detail_id, $cfg['ex_gn'], true) && $a->lane) {
                $text = trim($name . ($a->team ? $fmtT($a->team) : ''));   // KEIN "Mit Team"
                $this->push($bucket, 'ex_g'.(int)$a->lane, $start, $end, $text);
            }

            // Challenge
            if (in_array($a->activity_type_detail_id, $cfg['ch_be'], true)) $this->push($bucket, 'ch_be', $start, $end, $name);
            if (in_array($a->activity_type_detail_id, $cfg['ch_te'], true)) $this->push($bucket, 'ch_te', $start, $end, $name);
            if (in_array($a->activity_type_detail_id, $cfg['ch_ju'], true)) $this->push($bucket, 'ch_ju', $start, $end, $name);
            if (in_array($a->activity_type_detail_id, $cfg['ch_jn'], true) && $a->lane) {
                $text = trim($name . ($a->team ? $fmtT($a->team) : ''));   // KEIN "Mit Team"
                $this->push($bucket, 'ch_j'.(int)$a->lane, $start, $end, $text);
            }

            // RG SR
            if (in_array($a->activity_type_detail_id, $cfg['rg_sr'], true)) $this->push($bucket, 'rg_sr', $start, $end, $name);

            // Tische: Match vs. Check
            foreach ([1,2] as $idx) {
                $tNo = (int)($a->{'table_'.$idx}); if (!$tNo) continue;
                if (in_array($a->activity_type_detail_id, $cfg['rg_match'], true)) {
                    $text = trim('Match'.$fmtT($a->{'table_'.$idx.'_team'}));
                    $this->push($bucket, "rg_t{$tNo}", $start, $end, $text);
                } elseif (!empty($cfg['rc']) && in_array($a->activity_type_detail_id, $cfg['rc'], true)) {
                    $text = trim('Check'.$fmtT($a->{'table_'.$idx.'_team'}));
                    $this->push($bucket, "rc_t{$tNo}", $start, $end, $text);
                }
            }
        }

        // 5) Zeilen erzeugen – inkl. extra leerer Raster-Zeile nach Tagesende
        $rows = [];
        $activeUntil = [];
        $t = $minStart->copy();
        $lastDay = null;

        // Helper: Header-Key-Liste einmalig
        $headerKeys = array_map(fn($h) => $h['key'], $headers);

        while ($t < $maxEnd) {
            $day = $t->toDateString();

            // Normale Raster-Zeile
            $row = [
                'timeIso'   => $t->copy()->utc()->toIso8601String(),
                'timeLabel' => $t->copy()->format('d.m. H:i'),
                'cells'     => [],
            ];

            foreach ($headers as $h) {
                $key = $h['key'];
                if ($key === 'time') continue;

                // laufender Merge?
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

            // Steht als nächster Slot ein Tageswechsel an? → Separator + zusätzliche leere Raster-Zeile
            $next = $t->copy()->addMinutes(5);
            if ($lastDay !== null && $next->toDateString() !== $day) {
                // sichtbarer Trenner (wie bisher)
                $rows[] = ['separator' => true];

                // zusätzliche leere Raster-Zeile mit Zeitstempel des nächsten Slots
                $rows[] = $this->emptyRow($next, $headerKeys);
            }
            $lastDay = $day;

            $t = $next; // weiter
        }

        // 6) Extra leere Raster-Zeile am Ende des Plans (Zeit = $maxEnd)
        $rows[] = $this->emptyRow($maxEnd, $headerKeys);

        return ['headers'=>$headers, 'rows'=>$rows];
    }


    public function buildTeamsMatrix(Collection $activities): array
    {
        // Zeitfenster
        if ($activities->isEmpty()) {
            return [
                'headers' => [['key'=>'time','title'=>'Zeit']],
                'rows'    => [['separator'=>true]],
            ];
        }
        $minStart = Carbon::parse($activities->min('start_time'))->startOfMinute();
        $maxEnd   = Carbon::parse($activities->max('end_time'))->startOfMinute();

        // Konfig & Konstanten (defensiv)
        $cfgAll   = $this->resolveConstants(config('atd'));
        $rgMatch  = $cfgAll['rg_match'] ?? [];
        $rc       = $cfgAll['rc'] ?? [];
        $excludeTeamAtds = $cfgAll['exclude_team_atds'] ?? [ 'ID_ATD_C_SCORING', 'ID_ATD_E_SCORING' ];
        $excludeTeamAtds = array_map(fn($x)=>is_string($x)&&defined($x)?constant($x):$x, $excludeTeamAtds);

        // Global pro Team – Explore/Challenge
        $exAllTeamsAtds = $cfgAll['ex_allteams'] ?? [
            'ID_ATD_OPENING','ID_ATD_AWARDS','ID_ATD_E_OPENING','ID_ATD_E_AWARDS','ID_ATD_E_COACH_BRIEFING',
            'ID_ATD_INSERTED','ID_ATD_E_INSERTED',
        ];
        $chAllTeamsAtds = $cfgAll['ch_allteams'] ?? [
            'ID_ATD_OPENING','ID_ATD_AWARDS','ID_ATD_C_OPENING','ID_ATD_C_AWARDS','ID_ATD_C_COACH_BRIEFING',
            'ID_ATD_C_PRESENTATIONS','ID_ATD_INSERTED','ID_ATD_C_INSERTED',
        ];
        $exAllTeamsAtds = array_map(fn($x)=>is_string($x)&&defined($x)?constant($x):$x, $exAllTeamsAtds);
        $chAllTeamsAtds = array_map(fn($x)=>is_string($x)&&defined($x)?constant($x):$x, $chAllTeamsAtds);

        // Teamlisten (nur Teams, die im Plan vorkommen) – getrennt nach Program
        $teamNumbersFor = function(string $program) use ($activities) {
            $nums = collect();
            $filtered = $activities->filter(fn($a) => strtoupper((string)$a->program_name) === strtoupper($program));
            foreach ($filtered as $a) {
                $nums->push((int)($a->team ?? 0));
                $nums->push((int)($a->table_1_team ?? 0));
                $nums->push((int)($a->table_2_team ?? 0));
            }
            return $nums->filter(fn($n) => $n > 0)->unique()->sort()->values()->all();
        };

        $exTeams = $teamNumbersFor('EXPLORE');
        $chTeams = $teamNumbersFor('CHALLENGE');

        // Header: Zeit | Ex T.. | Ch T..
        $headers = [['key'=>'time','title'=>'Zeit']];
        foreach ($exTeams as $n) { $headers[] = ['key'=>"ex_t{$n}", 'title'=>"Ex T".str_pad((string)$n,2,'0',STR_PAD_LEFT)]; }
        foreach ($chTeams as $n) { $headers[] = ['key'=>"ch_t{$n}", 'title'=>"Ch T".str_pad((string)$n,2,'0',STR_PAD_LEFT)]; }

        // Buckets
        $bucket = [];
        $pushForTeam = function(string $colKey, Carbon $start, Carbon $end, string $text) use (&$bucket) {
            $k = $start->toDateTimeString();
            $bucket[$colKey][$k] = $bucket[$colKey][$k] ?? [];
            $bucket[$colKey][$k][] = ['start'=>$start->toDateTimeString(), 'end'=>$end->toDateTimeString(), 'text'=>$text];
        };

        foreach ($activities as $a) {
            $prog  = strtoupper((string)$a->program_name);
            if ($prog !== 'EXPLORE' && $prog !== 'CHALLENGE') continue;

            $start = Carbon::parse($a->start_time)->startOfMinute();
            $end   = Carbon::parse($a->end_time)->startOfMinute();
            $name  = preg_replace('/-/', '- ', (string)$a->activity_name);
            $atdId = (int)$a->activity_type_detail_id;

            // 1) Harte Text-Überschreibungen für spezielle ATDs
            if ($atdId === (defined('ID_ATD_C_WITH_TEAM') ? constant('ID_ATD_C_WITH_TEAM') : -1)) {
                $name = "Jury";
            }
            if ($atdId === (defined('ID_ATD_E_WITH_TEAM') ? constant('ID_ATD_E_WITH_TEAM') : -1)) {
                $name = "Begutachtung";
            }

            // 2) Ausschlüsse trotz Team-IDs (Scoring etc.)
            if (in_array($atdId, $excludeTeamAtds, true)) {
                continue;
            }

            // 2) Global pro Team hinzufügen (ohne Teamzusatz)
            if ($prog === 'EXPLORE' && in_array($atdId, $exAllTeamsAtds, true)) {
                foreach ($exTeams as $tn) {
                    $pushForTeam("ex_t{$tn}", $start, $end, $name);
                }
                continue; // fertig verarbeitet
            }
            if ($prog === 'CHALLENGE' && in_array($atdId, $chAllTeamsAtds, true)) {
                foreach ($chTeams as $tn) {
                    $pushForTeam("ch_t{$tn}", $start, $end, $name);
                }
                continue; // fertig verarbeitet
            }

            // 3) Normale Team-Zuordnung (nur wenn Teamnummer vorhanden)
            $teamsInActivity = collect([
                (int)($a->team ?? 0),
                (int)($a->table_1_team ?? 0),
                (int)($a->table_2_team ?? 0),
            ])->filter(fn($n)=>$n>0)->unique()->all();

            if (empty($teamsInActivity)) {
                continue;
            }

            foreach ($teamsInActivity as $tn) {
                $col = ($prog === 'EXPLORE' ? "ex_t{$tn}" : "ch_t{$tn}");

                // Text: Matches/Checks labeln, sonst Name (ohne Teamzusatz)
                $text = $name;
                if (!empty($rgMatch) && in_array($atdId, $rgMatch, true)) {
                    $text = 'Match';
                } elseif (!empty($rc) && in_array($atdId, $rc, true)) {
                    $text = 'Check';
                }

                $pushForTeam($col, $start, $end, $text);
            }
        }

        // Zeilen generieren (Raster + Separator + extra leere Zeile)
        $rows = [];
        $activeUntil = [];
        $t = $minStart->copy();
        $headerKeys = array_map(fn($h) => $h['key'], $headers);
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

        // Extra leere Raster-Zeile am Ende
        $rows[] = $this->emptyRow($maxEnd, $headerKeys);

        return ['headers'=>$headers,'rows'=>$rows];
    }

    public function buildRoomsMatrix(Collection $activities): array
    {
        if ($activities->isEmpty()) {
            return [
                'headers' => [['key'=>'time','title'=>'Zeit']],
                'rows'    => [['separator'=>true]],
            ];
        }

        // Zeitfenster
        $minStart = Carbon::parse($activities->min('start_time'))->startOfMinute();
        $maxEnd   = Carbon::parse($activities->max('end_time'))->startOfMinute();

        // Raumtypen (ohne Robot-Game id=1), nur die im Plan vorkommen
        $roomTypes = $activities
            ->filter(fn($a) => (int)($a->room_type_id ?? 0) > 0 && (int)$a->room_type_id !== 1)
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

        // Header: Zeit + je Raumtyp
        $headers = [['key'=>'time','title'=>'Zeit']];
        foreach ($roomTypes as $rt) {
            $headers[] = ['key' => 'room_'.$rt['id'], 'title' => $rt['name']];
        }
        $headerKeys = array_map(fn($h) => $h['key'], $headers);

        // Buckets nach Raumtyp & Startminute
        $bucket = [];
        $push = function(string $colKey, Carbon $start, Carbon $end, string $text) use (&$bucket) {
            $k = $start->toDateTimeString();
            $bucket[$colKey][$k] = $bucket[$colKey][$k] ?? [];
            $bucket[$colKey][$k][] = ['start'=>$start->toDateTimeString(), 'end'=>$end->toDateTimeString(), 'text'=>$text];
        };

        foreach ($activities as $a) {
            $rtId = (int)($a->room_type_id ?? 0);
            if ($rtId <= 0 || $rtId === 1) {
                continue; // Robot-Game oder kein Raumtyp → ausblenden
            }

            $start = Carbon::parse($a->start_time)->startOfMinute();
            $end   = Carbon::parse($a->end_time)->startOfMinute();

            // Name + optional Teamnummer (ohne "Mit Team")
            $name = preg_replace('/-/', '- ', (string)$a->activity_name);

            $teamNo = 0;
            // Lane/Table ignorieren – Team nur, wenn vorhanden:
            // Bevorzugt 'team', sonst table_1_team / table_2_team (falls in Daten gesetzt)
            if ((int)($a->team ?? 0) > 0) {
                $teamNo = (int)$a->team;
            } elseif ((int)($a->table_1_team ?? 0) > 0) {
                $teamNo = (int)$a->table_1_team;
            } elseif ((int)($a->table_2_team ?? 0) > 0) {
                $teamNo = (int)$a->table_2_team;
            }

            $text = $name . ($teamNo > 0 ? ' T'.str_pad((string)$teamNo, 2, '0', STR_PAD_LEFT) : '');

            $push('room_'.$rtId, $start, $end, $text);
        }

        // Zeilen generieren (5-Min Raster) + Tages-Separator + Leerzeile nach jedem Tag + extra leer am Ende
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

        // extra leere Zeile am Ende
        $rows[] = $this->emptyRow($maxEnd, $headerKeys);

        return ['headers'=>$headers, 'rows'=>$rows];
    }


    /**
     * Default headers for the "roles" view when there are no activities.
     */
    public function defaultRolesHeaders(): array
    {
        // Keep a minimal but useful skeleton so the frontend can render the table
        return [
            ['key' => 'time',  'title' => 'Zeit'],
            ['key' => 'ex_be', 'title' => 'Ex Be'],
            ['key' => 'ex_te', 'title' => 'Ex Te'],
            ['key' => 'ex_gu', 'title' => 'Ex Gu'],
            ['key' => 'ch_be', 'title' => 'Ch Be'],
            ['key' => 'ch_te', 'title' => 'Ch Te'],
            ['key' => 'ch_ju', 'title' => 'Ch Ju'],
            ['key' => 'rg_sr', 'title' => 'RG SR'],
            ['key' => 'rc_t1', 'title' => 'RC T1'],
            ['key' => 'rc_t2', 'title' => 'RC T2'],
            ['key' => 'rg_t1', 'title' => 'RG T1'],
            ['key' => 'rg_t2', 'title' => 'RG T2'],
        ];
    }

    /**
     * Default headers for the "teams" view when there are no activities.
     * We cannot infer team count without data, so keep only "Zeit".
     */
    public function defaultTeamsHeaders(): array
    {
        return [
            ['key' => 'time', 'title' => 'Zeit'],
        ];
    }

    /**
     * Default headers for the "rooms" view when there are no activities.
     * We cannot infer room types without data, so keep only "Zeit".
     */
    public function defaultRoomsHeaders(): array
    {
        return [
            ['key' => 'time', 'title' => 'Zeit'],
        ];
    }


    /**
     * Baut eine leere Raster-Zeile mit Zeitstempel $t und leeren Zellen.
     * $headerKeys ist die Liste aller Header-Keys (inkl. 'time').
     */
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

    /**
     * Wandelt Konstanten-Namen (Strings) aus config('atd') in Zahlen-IDs.
     * Akzeptiert auch null und liefert dann [].
     */
    private function resolveConstants($cfg): array
    {
        if (!is_array($cfg)) {
            return [];
        }
        $out = [];
        foreach ($cfg as $k => $v) {
            if (is_array($v)) {
                $out[$k] = array_map(function ($c) {
                    return (is_string($c) && defined($c)) ? constant($c) : $c;
                }, $v);
            } else {
                $out[$k] = (is_string($v) && defined($v)) ? constant($v) : $v;
            }
        }
        return $out;
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
}