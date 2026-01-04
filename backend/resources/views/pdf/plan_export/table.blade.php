<div style="margin-bottom: 20px;">
    <h3 style="font-size:14px; margin:10px 0 5px 0;">
        {{ $tableBlock['tableLabel'] }}
    </h3>

    <table style="width:100%; border-collapse: collapse; font-size: 11px;">
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
            @foreach ($tableBlock['rows'] as $row)
                <tr>
                    <td>{{ $row['start_hm'] }}</td>
                    <td>{{ $row['end_hm'] }}</td>
                    <td>{{ $row['activity'] }}</td>
                    <td>{!! \App\Helpers\PdfHelper::formatTeamNameWithNoshow($row['teamLabel'] ?? '–', $row['team_is_noshow'] ?? false) !!}</td>
                    <td>{{ $row['room'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>