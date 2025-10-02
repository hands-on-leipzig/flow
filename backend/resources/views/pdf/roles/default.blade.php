<h3>
    {{ $role ?? 'Rolle' }}
    @if(!empty($suffix)) – {{ $suffix }} @endif
</h3>

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
    @foreach($activities as $act)
        <tr>
            <td>{{ $fmt($act->start_time) }}</td>
            <td>{{ $fmt($act->end_time) }}</td>
            <td>{{ $act->activity_name }}</td>
            <td>{{ $act->jury_team_name ?? $act->table_1_team_name ?? $act->table_2_team_name ?? '' }}</td>
            <td>{{ $act->room_name ?? '' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>