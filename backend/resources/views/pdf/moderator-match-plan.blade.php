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

        .day-header {
            background-color: #34495e;
            color: #fff;
            padding: 8px 12px;
            margin: 0 0 10px 0;
            font-size: 13px;
            border-radius: 3px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $eventName }} – {{ ($isTwoDayEvent ?? false) ? (($day1Date ?? $eventDate) . ' / ' . ($day2Date ?? $eventDate)) : $eventDate }}</h1>
        <p>Letzte Änderung: {{ $lastUpdated }}</p>
    </div>

    @if(!empty($isTwoDayEvent))
        <div class="day-header">Tag 1 ({{ $day1Date ?? $eventDate }})</div>

        @if(!empty($scheduleActivitiesDay1) || !empty($parallelActivitiesDay1))
            <table class="two-columns">
                <tr>
                    <td>
                        <div class="column-header">Mit Moderation</div>
                        @foreach(($scheduleActivitiesDay1 ?? []) as $activity)
                            <div class="special-activity">
                                <div class="special-activity-header">{{ e($activity['name']) }}</div>
                                <div class="special-activity-time">{{ $activity['start_time'] }} – {{ $activity['end_time'] }} | {{ e($activity['room'] ?? '–') }}</div>
                            </div>
                        @endforeach
                    </td>
                    <td>
                        <div class="column-header">Parallele Aktivitäten</div>
                        @foreach(($parallelActivitiesDay1 ?? []) as $activity)
                            <div class="special-activity">
                                <div class="special-activity-header">{{ e($activity['name']) }}</div>
                                <div class="special-activity-time">{{ $activity['start_time'] }} – {{ $activity['end_time'] }} | {{ e($activity['room'] ?? '–') }}</div>
                            </div>
                        @endforeach
                    </td>
                </tr>
            </table>
        @endif

        @php
            $regularRounds = [0, 1, 2, 3];
            $finalRoundKeysDay1 = array_filter(array_keys($roundsDataDay1 ?? []), fn($k) => !in_array($k, $regularRounds) && is_numeric($k) && $k >= 4);
            $allRoundKeysDay1 = array_merge($regularRounds, $finalRoundKeysDay1);
        @endphp
        @foreach($allRoundKeysDay1 as $roundKey)
            @php $roundData = $roundsDataDay1[$roundKey] ?? null; @endphp
            @if($roundData && !empty($roundData['matches']))
                <div class="section">
                    <div class="section-header">{{ $roundData['label'] }}</div>
                    <table><tbody>
                        @foreach($roundData['matches'] as $match)
                            <tr>
                                <td style="width: 12%;">{{ $match['start_time'] }}</td>
                                <td style="width: 12%;">{{ $match['table_1'] }}</td>
                                <td style="width: 32%; font-weight: bold;">
                                    @if(empty($match['team_1']['name']))&nbsp;@elseif($match['team_1']['noshow'])<span class="noshow">{{ e($match['team_1']['name']) }}</span>@else{{ e($match['team_1']['name']) }}@endif
                                </td>
                                <td style="width: 12%;">{{ $match['table_2'] }}</td>
                                <td style="width: 32%; font-weight: bold;">
                                    @if(empty($match['team_2']['name']))&nbsp;@elseif($match['team_2']['noshow'])<span class="noshow">{{ e($match['team_2']['name']) }}</span>@else{{ e($match['team_2']['name']) }}@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody></table>
                </div>
            @endif
        @endforeach

        <div style="page-break-before: always;"></div>
        <div class="header">
            <h1>{{ $eventName }} – {{ ($isTwoDayEvent ?? false) ? (($day1Date ?? $eventDate) . ' / ' . ($day2Date ?? $eventDate)) : $eventDate }}</h1>
            <p>Letzte Änderung: {{ $lastUpdated }}</p>
        </div>
        <div class="day-header">Tag 2 ({{ $day2Date ?? $eventDate }})</div>

        @if(!empty($scheduleActivitiesDay2) || !empty($parallelActivitiesDay2))
            <table class="two-columns">
                <tr>
                    <td>
                        <div class="column-header">Mit Moderation</div>
                        @foreach(($scheduleActivitiesDay2 ?? []) as $activity)
                            <div class="special-activity">
                                <div class="special-activity-header">{{ e($activity['name']) }}</div>
                                <div class="special-activity-time">{{ $activity['start_time'] }} – {{ $activity['end_time'] }} | {{ e($activity['room'] ?? '–') }}</div>
                            </div>
                        @endforeach
                    </td>
                    <td>
                        <div class="column-header">Parallele Aktivitäten</div>
                        @foreach(($parallelActivitiesDay2 ?? []) as $activity)
                            <div class="special-activity">
                                <div class="special-activity-header">{{ e($activity['name']) }}</div>
                                <div class="special-activity-time">{{ $activity['start_time'] }} – {{ $activity['end_time'] }} | {{ e($activity['room'] ?? '–') }}</div>
                            </div>
                        @endforeach
                    </td>
                </tr>
            </table>
        @endif

        @php
            $finalRoundKeysDay2 = array_filter(array_keys($roundsDataDay2 ?? []), fn($k) => !in_array($k, $regularRounds) && is_numeric($k) && $k >= 4);
            $allRoundKeysDay2 = array_merge($regularRounds, $finalRoundKeysDay2);
        @endphp
        @foreach($allRoundKeysDay2 as $roundKey)
            @php $roundData = $roundsDataDay2[$roundKey] ?? null; @endphp
            @if($roundData && !empty($roundData['matches']))
                <div class="section">
                    <div class="section-header">{{ $roundData['label'] }}</div>
                    <table><tbody>
                        @foreach($roundData['matches'] as $match)
                            <tr>
                                <td style="width: 12%;">{{ $match['start_time'] }}</td>
                                <td style="width: 12%;">{{ $match['table_1'] }}</td>
                                <td style="width: 32%; font-weight: bold;">
                                    @if(empty($match['team_1']['name']))&nbsp;@elseif($match['team_1']['noshow'])<span class="noshow">{{ e($match['team_1']['name']) }}</span>@else{{ e($match['team_1']['name']) }}@endif
                                </td>
                                <td style="width: 12%;">{{ $match['table_2'] }}</td>
                                <td style="width: 32%; font-weight: bold;">
                                    @if(empty($match['team_2']['name']))&nbsp;@elseif($match['team_2']['noshow'])<span class="noshow">{{ e($match['team_2']['name']) }}</span>@else{{ e($match['team_2']['name']) }}@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody></table>
                </div>
            @endif
        @endforeach
    @else
        {{-- Existing one-day output --}}
        @if(!empty($scheduleActivities) || !empty($parallelActivities))
            <table class="two-columns">
                <tr>
                    <td>
                        <div class="column-header">Mit Moderation</div>
                        @if(!empty($scheduleActivities))
                            @foreach($scheduleActivities as $activity)
                                <div class="special-activity">
                                    <div class="special-activity-header">{{ e($activity['name']) }}</div>
                                    <div class="special-activity-time">{{ $activity['start_time'] }} – {{ $activity['end_time'] }} | {{ e($activity['room'] ?? '–') }}</div>
                                </div>
                            @endforeach
                        @endif
                    </td>
                    <td>
                        <div class="column-header">Parallele Aktivitäten</div>
                        @if(!empty($parallelActivities))
                            @foreach($parallelActivities as $activity)
                                <div class="special-activity">
                                    <div class="special-activity-header">{{ e($activity['name']) }}</div>
                                    <div class="special-activity-time">{{ $activity['start_time'] }} – {{ $activity['end_time'] }} | {{ e($activity['room'] ?? '–') }}</div>
                                </div>
                            @endforeach
                        @endif
                    </td>
                </tr>
            </table>
        @endif

        @php
            $regularRounds = [0, 1, 2, 3];
            $finalRoundKeys = array_filter(array_keys($roundsData), fn($k) => !in_array($k, $regularRounds) && is_numeric($k) && $k >= 4);
            $allRoundKeys = array_merge($regularRounds, $finalRoundKeys);
        @endphp
        @foreach($allRoundKeys as $roundKey)
            @php $roundData = $roundsData[$roundKey] ?? null; @endphp
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
    @endif
</body>
</html>
