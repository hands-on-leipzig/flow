<div style="margin-bottom: 30px;">
    {{-- Überschrift für Rolle + Team --}}
    <h3 style="font-size: 14px; margin: 10px 0 5px 0;">
        {{ $roleTable['role'] }} – {{ $roleTable['teamLabel'] }}
    </h3>

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
            @foreach ($roleTable['rows'] as $row)
                <tr>
                    <td>{{ $row['start_hm'] }}</td>
                    <td>{{ $row['end_hm'] }}</td>
                    <td>{{ $row['activity'] }}</td>
                    <td>{{ $row['assign'] }}</td>
                    <td>{{ $row['room'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>