{{-- resources/views/pdf/content/room_schedule.blade.php --}}

<h2 style="margin-bottom: 15px; font-size: 22px; font-weight: bold;">
    {{ $room }}
</h2>

<table style="width:100%; border-collapse:collapse;">
    <tr valign="top">
        {{-- Linke Spalte: Tabelle --}}
        <td style="width:66%; padding-right:20px;">

            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background-color:#f5f5f5;">
                        <th style="text-align:left; padding:6px 8px; width:10%;">Start</th>
                        <th style="text-align:left; padding:6px 8px; width:10%;">Ende</th>
                        <th style="text-align:left; padding:6px 8px; width:30%;">Aktivität</th>
                        <th style="text-align:left; padding:6px 8px; width:50%;">Team</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $i => $row)
                        <tr style="background-color:{{ $i % 2 === 0 ? '#ffffff' : '#f9f9f9' }};">
                            <td style="padding:5px 8px;">{{ $row['start'] }}</td>
                            <td style="padding:5px 8px;">{{ $row['end'] }}</td>
                            <td style="padding:5px 8px;">{{ $row['activity'] }}</td>
                            <td style="padding:5px 8px;">{{ $row['team'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

        </td>

        {{-- Rechte Spalte: QR-Code --}}
        @include('pdf.content.right_qr', ['event' => $event])
        
    </tr>
</table>