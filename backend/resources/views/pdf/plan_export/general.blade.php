<div style="margin-bottom: 20px;">
    @if ($showHeading ?? true)
        <h3 style="font-size:14px; margin:10px 0 5px 0;">
            Übersicht
        </h3>
    @endif

    <table style="width:100%; border-collapse: collapse; font-size: 11px;">
        <thead>
            <tr>
                <th>Start</th>
                <th>Ende</th>
                <th>Aktivität</th>
                <th>Team</th>
                <th>Jury/Tisch</th>
                <th>Raum</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($generalBlock['rows'] as $row)
                <tr>
                    <td>{{ $row['start_hm'] }}</td>
                    <td>{{ $row['end_hm'] }}</td>
                    <td>{{ $row['activity'] }}</td>
                    <td>{{ $row['teamLabel'] }}</td>
                    <td>{{ $row['assign'] }}</td>
                    <td>{{ $row['room'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>