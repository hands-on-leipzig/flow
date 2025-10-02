<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Plan Export</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
        }
        h1, h2, h3 {
            margin: 8px 0 4px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #444;
            padding: 4px 6px;
            text-align: left;
        }
        th {
            background-color: #eee;
        }
    </style>
</head>
<body>

<h1>Rollen</h1>

@foreach($programGroups as $programName => $roleTables)
    <h2>{{ $programName ?? 'Alles' }}</h2>

    @foreach($roleTables as $roleTable)
        <h3>{{ $roleTable['role'] }}</h3>

        {{-- Team-Differenzierung --}}
        @if(!empty($roleTable['teamLabel']))
            <h3>{{ $roleTable['teamLabel'] }}</h3>
        @elseif(!empty($roleTable['suffix']))
            <h3>{{ $roleTable['suffix'] }}</h3>
        @endif

        <table>
            <thead>
                <tr>
                    <th>Start</th>
                    <th>Ende</th>
                    <th>Aktivität</th>
                    <th>Team</th>
                    <th>Raum</th>
                </tr>
            </thead>
            <tbody>
                @foreach($roleTable['activities'] as $act)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($act->start_time)->format('H:i') }}</td>
                        <td>{{ \Carbon\Carbon::parse($act->end_time)->format('H:i') }}</td>
                        <td>{{ $act->activity_name }}</td>
                        <td>
                            {{ $act->jury_team_name 
                                ?? $act->table_1_team_name 
                                ?? $act->table_2_team_name 
                                ?? '' }}
                        </td>
                        <td>{{ $act->room_name ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
@endforeach

</body>
</html>