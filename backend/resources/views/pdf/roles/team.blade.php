{{-- resources/views/pdf/partials/team.blade.php --}}
<div style="margin-bottom: 30px;">
    {{-- Überschrift für Rolle + Team --}}
    <h3 style="font-size: 14px; margin: 10px 0 5px 0;">
        {{ $roleTable['role'] }} – {{ $roleTable['teamLabel'] }}
    </h3>

    <table style="width:100%; border-collapse: collapse; font-size: 11px;">
        <thead>
            <tr>
                <th style="border:1px solid #ccc; padding:4px; text-align:left;">Start</th>
                <th style="border:1px solid #ccc; padding:4px; text-align:left;">Ende</th>
                <th style="border:1px solid #ccc; padding:4px; text-align:left;">Aktivität</th>
                <th style="border:1px solid #ccc; padding:4px; text-align:left;">Jury/Tisch</th>
                <th style="border:1px solid #ccc; padding:4px; text-align:left;">Raum</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($roleTable['rows'] as $row)
                <tr>
                    <td style="border:1px solid #ccc; padding:4px;">{{ $row['start_hm'] }}</td>
                    <td style="border:1px solid #ccc; padding:4px;">{{ $row['end_hm'] }}</td>
                    <td style="border:1px solid #ccc; padding:4px;">{{ $row['activity'] }}</td>
                    <td style="border:1px solid #ccc; padding:4px;">{{ $row['assign'] }}</td>
                    <td style="border:1px solid #ccc; padding:4px;">{{ $row['room'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>