<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Robot-Game kompakt</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 20mm 15mm 15mm 15mm;
        }

        body {
            font-family: sans-serif;
            font-size: 11px;
            color: #333;
            line-height: 1.4;
        }

        .header {
            margin-bottom: 15px;
        }

        .header h1 {
            font-size: 16px;
            margin: 0 0 3px 0;
            font-weight: bold;
        }

        .header p {
            font-size: 10px;
            color: #555;
            margin: 0;
        }

        .section {
            margin-bottom: 20px;
        }

        .section-header {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 8px;
            padding-bottom: 3px;
            border-bottom: 1px solid #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        th, td {
            border: 1px solid #333;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }

        td {
            font-size: 10px;
        }

        .noshow {
            text-decoration: line-through;
        }

        .special-activity {
            margin-bottom: 15px;
        }

        .special-activity-header {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .special-activity-time {
            font-size: 10px;
            color: #555;
        }

        .two-columns {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        .two-columns td {
            width: 50%;
            vertical-align: top;
            padding-right: 10px;
            border: none;
        }

        .two-columns td:last-child {
            padding-right: 0;
            padding-left: 10px;
        }

        .column-header {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 8px;
            padding-bottom: 3px;
            border-bottom: 1px solid #333;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $eventName }} – {{ $eventDate }}</h1>
        <p>Letzte Änderung: {{ $lastUpdated }}</p>
    </div>

    {{-- Two-column layout for activities --}}
    @if(!empty($scheduleActivities) || !empty($parallelActivities))
        <table class="two-columns">
            <tr>
                {{-- Left column: Mit Moderation --}}
                <td>
                    <div class="column-header">Mit Moderation</div>
                    @if(!empty($scheduleActivities))
                        @foreach($scheduleActivities as $activity)
                            <div class="special-activity">
                                <div class="special-activity-header">{{ e($activity['name']) }}</div>
                                <div class="special-activity-time">{{ $activity['start_time'] }} – {{ $activity['end_time'] }}</div>
                            </div>
                        @endforeach
                    @endif
                </td>

                {{-- Right column: Parallele Aktivitäten --}}
                <td>
                    <div class="column-header">Parallele Aktivitäten</div>
                    @if(!empty($parallelActivities))
                        @foreach($parallelActivities as $activity)
                            <div class="special-activity">
                                <div class="special-activity-header">{{ e($activity['name']) }}</div>
                                <div class="special-activity-time">{{ $activity['start_time'] }} – {{ $activity['end_time'] }}</div>
                            </div>
                        @endforeach
                    @endif
                </td>
            </tr>
        </table>
    @endif

    {{-- Robot game rounds --}}
    @php
        $regularRounds = [0, 1, 2, 3];
        $finalRoundKeys = array_filter(array_keys($roundsData), fn($k) => !in_array($k, $regularRounds) && is_numeric($k) && $k >= 4);
        $allRoundKeys = array_merge($regularRounds, $finalRoundKeys);
    @endphp

    @foreach($allRoundKeys as $roundKey)
        @php
            $roundData = $roundsData[$roundKey] ?? null;
        @endphp

        @if($roundData && !empty($roundData['matches']))
            <div class="section">
                <div class="section-header">{{ $roundData['label'] }}</div>
                
                <table>
                    <tbody>
                        @foreach($roundData['matches'] as $match)
                            <tr>
                                <td style="width: 12%;">{{ $match['start_time'] }}</td>
                                <td style="width: 12%;">{{ $match['table_1'] }}</td>
                                <td style="width: 32%; font-weight: bold;">
                                    @if(empty($match['team_1']['name']))
                                        {{-- Empty for final rounds - moderator fills in --}}
                                        &nbsp;
                                    @elseif($match['team_1']['noshow'])
                                        <span class="noshow">{{ e($match['team_1']['name']) }}</span>
                                    @else
                                        {{ e($match['team_1']['name']) }}
                                    @endif
                                </td>
                                <td style="width: 12%;">{{ $match['table_2'] }}</td>
                                <td style="width: 32%; font-weight: bold;">
                                    @if(empty($match['team_2']['name']))
                                        {{-- Empty for final rounds - moderator fills in --}}
                                        &nbsp;
                                    @elseif($match['team_2']['noshow'])
                                        <span class="noshow">{{ e($match['team_2']['name']) }}</span>
                                    @else
                                        {{ e($match['team_2']['name']) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach
</body>
</html>
