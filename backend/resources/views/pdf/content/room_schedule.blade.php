{{-- resources/views/pdf/content/room_schedule.blade.php --}}

<h2 style="margin-bottom: 10px; font-size: 20px; font-weight: bold;">
    {{ $room }}
</h2>

<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px;">
    <thead>
        <tr>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Start</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Ende</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Aktivität</th>
            <th style="border: 1px solid #ccc; padding: 6px; text-align: left;">Team</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
            <tr>
                <td style="border: 1px solid #ccc; padding: 6px;">{{ $row['start'] }}</td>
                <td style="border: 1px solid #ccc; padding: 6px;">{{ $row['end'] }}</td>
                <td style="border: 1px solid #ccc; padding: 6px;">{{ $row['activity'] }}</td>
                <td style="border: 1px solid #ccc; padding: 6px;">{{ $row['team'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>