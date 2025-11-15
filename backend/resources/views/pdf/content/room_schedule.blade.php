{{-- resources/views/pdf/content/room_schedule.blade.php --}}

<h2 style="margin-bottom: 15px; font-size: 22px; font-weight: bold;">
    {{ $room }}
</h2>

@php
// Group rows by day
$activitiesByDay = [];
foreach($rows as $row) {
    $dayKey = $row['start_date']->format('Y-m-d');
    if (!isset($activitiesByDay[$dayKey])) {
        $activitiesByDay[$dayKey] = [
            'date' => $row['start_date'],
            'rows' => []
        ];
    }
    $activitiesByDay[$dayKey]['rows'][] = $row;
}

$isMultiDay = count($activitiesByDay) > 1;
@endphp

<table style="width:100%; border-collapse:collapse;">
    <tr valign="top">
        {{-- Linke Spalte: Tabelle --}}
        <td style="width:66%; padding-right:20px;">

            @foreach($activitiesByDay as $dayKey => $dayData)
                {{-- Day header for multi-day events --}}
                @if($isMultiDay)
                    <div style="background-color: #34495e; color: white; padding: 8px 12px; margin: 0 0 10px 0; font-size: 16px; border-radius: 3px;">
                        {{ $dayData['date']->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}
                    </div>
                @endif

                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background-color:#f5f5f5;">
                        <th style="text-align:center; padding:6px 4px; width:6%;"></th>
                        <th style="text-align:left; padding:6px 8px; width:9%;">Start</th>
                        <th style="text-align:left; padding:6px 8px; width:9%;">Ende</th>
                        <th style="text-align:center; padding:6px 4px; width:5%;"></th>
                        <th style="text-align:center; padding:6px 4px; width:5%;"></th>
                        <th style="text-align:left; padding:6px 8px; width:25%;">Aktivität</th>
                        <th style="text-align:left; padding:6px 8px; width:40%;">Team</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($dayData['rows'] as $i => $row)
                        <tr style="background-color:{{ $i % 2 === 0 ? '#ffffff' : '#f9f9f9' }};">
                            <td style="text-align:center; padding:4px;">
                                @if(!empty($row['is_free']))
                                    <span style="font-size:12px;">!</span>
                                @endif
                            </td>
                            <td style="padding:5px 8px;">{{ $row['start'] }}</td>
                            <td style="padding:5px 8px;">{{ $row['end'] }}</td>
                            {{-- Explore Icon --}}
                            <td style="text-align:center; padding:4px;">
                                @if(!empty($row['is_explore']))
                                    <img src="{{ public_path('flow/fll_explore_v.png') }}" alt="Explore" style="height:16px;">
                                @endif
                            </td>
                            {{-- Challenge Icon --}}
                            <td style="text-align:center; padding:4px;">
                                @if(!empty($row['is_challenge']))
                                    <img src="{{ public_path('flow/fll_challenge_v.png') }}" alt="Challenge" style="height:16px;">
                                @endif
                            </td>
                            <td style="padding:5px 8px;">{{ $row['activity'] }}</td>
                            <td style="padding:5px 8px;">{{ $row['team'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @endforeach

        </td>

        {{-- Rechte Spalte: QR-Code --}}
        @include('pdf.content.right_qr', ['event' => $event])
        
    </tr>
</table>