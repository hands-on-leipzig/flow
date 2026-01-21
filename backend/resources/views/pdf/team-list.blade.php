<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Teamliste</title>
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
            margin-bottom: 25px;
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

        th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: left;
        }

        td {
            font-size: 10px;
        }

        .noshow {
            text-decoration: line-through;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $eventName }} – {{ $eventDate }}</h1>
        <p>Letzte Änderung: {{ $lastUpdated }}</p>
    </div>

    @if(!empty($exploreTeams))
        <div class="section">
            <div class="section-header">FIRST LEGO League Explore</div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 60%;">Team</th>
                        <th style="width: 40%;">Teambereich</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($exploreTeams as $team)
                        <tr>
                            <td>
                                @php
                                    $teamLabel = $team['name'];
                                    if ($team['hot_number']) {
                                        $teamLabel .= ' (' . $team['hot_number'] . ')';
                                    }
                                @endphp
                                @if($team['noshow'])
                                    <span class="noshow">{{ e($teamLabel) }}</span>
                                @else
                                    {{ e($teamLabel) }}
                                @endif
                            </td>
                            <td>{{ e($team['room_name']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if(!empty($challengeTeams))
        <div class="section">
            <div class="section-header">FIRST LEGO League Challenge</div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 60%;">Team</th>
                        <th style="width: 40%;">Teambereich</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($challengeTeams as $team)
                        <tr>
                            <td>
                                @php
                                    $teamLabel = $team['name'];
                                    if ($team['hot_number']) {
                                        $teamLabel .= ' (' . $team['hot_number'] . ')';
                                    }
                                @endphp
                                @if($team['noshow'])
                                    <span class="noshow">{{ e($teamLabel) }}</span>
                                @else
                                    {{ e($teamLabel) }}
                                @endif
                            </td>
                            <td>{{ e($team['room_name']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</body>
</html>
