{{-- resources/views/pdf/partials/team.blade.php --}}

<h3>{{ $roleTable['teamLabel'] }}</h3>

<table>
    <thead>
        <tr>
            <th>Start</th>
            <th>Ende</th>
            <th>Aktivität</th>
            <th>Jury/Tisch</th>
            <th>Raum</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($roleTable['activities'] as $act)
            <tr>
                <td>{{ \Carbon\Carbon::parse($act->start_time)->format('H:i') }}</td>
                <td>{{ \Carbon\Carbon::parse($act->end_time)->format('H:i') }}</td>
                <td>{{ $act->activity_atd_name ?? $act->activity_name }}</td>
                <td>
                    @if($act->jury_team_name)
                        Jury {{ $act->jury_team_name }}
                    @elseif($act->table_1_name)
                        {{ $act->table_1_name }} {{ $act->table_1_team_name }}
                    @elseif($act->table_2_name)
                        {{ $act->table_2_name }} {{ $act->table_2_team_name }}
                    @else
                        –
                    @endif
                </td>
                <td>{{ $act->room_name ?? '–' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>