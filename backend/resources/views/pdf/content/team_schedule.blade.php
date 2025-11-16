@php
    $icon = null;
    $cleanTitle = $team;

    if (str_contains($team, 'FLL Explore')) {
        $icon = public_path('flow/fll_explore_h.png');
        $cleanTitle = trim(str_replace('FLL Explore', '', $team));
    } elseif (str_contains($team, 'FLL Challenge')) {
        $icon = public_path('flow/fll_challenge_h.png');
        $cleanTitle = trim(str_replace('FLL Challenge', '', $team));
    }

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
    
    // For single-day teams, just use the rows directly without day grouping
    if (!$isMultiDay) {
        $activitiesByDay = [
            'single' => [
                'date' => $rows[0]['start_date'] ?? \Carbon\Carbon::now(),
                'rows' => $rows
            ]
        ];
    }
@endphp

<h2 style="margin-bottom:15px; font-size:22px; font-weight:bold; font-family:sans-serif; display:flex; align-items:center; gap:10px;">
    @if($icon)
        <img src="file://{{ $icon }}" alt="Program Icon" style="height:28px; width:auto; vertical-align:middle;">
    @endif
    {{ $cleanTitle }}
</h2>

@if($isMultiDay)
    @foreach($activitiesByDay as $dayKey => $dayData)
        @if(!$loop->first)
            <div style="page-break-before: always;"></div>
            
            {{-- Repeat team header on new page --}}
            <h2 style="margin-bottom:15px; font-size:22px; font-weight:bold; font-family:sans-serif; display:flex; align-items:center; gap:10px;">
                @if($icon)
                    <img src="file://{{ $icon }}" alt="Program Icon" style="height:28px; width:auto; vertical-align:middle;">
                @endif
                {{ $cleanTitle }}
            </h2>
        @endif
        
        <div style="background-color: #34495e; color: white; padding: 8px 12px; margin: 0 0 10px 0; font-size: 16px; border-radius: 3px;">
            {{ $dayData['date']->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}
        </div>
        
        <table style="width:100%; border-collapse:collapse; margin-bottom: {{ !$loop->last ? '20px' : '0' }};">
            <tr valign="top">
                {{-- Linke Spalte: Tabelle --}}
                <td style="width:83.333%; padding-right:20px;">
                    <table style="width:100%; border-collapse:collapse; font-size:13px;">
                        <thead>
                            <tr style="background-color:#f5f5f5;">
                                <th style="text-align:center; padding:6px 4px; width:6%;"></th>
                                <th style="text-align:left; padding:6px 8px; width:11%;">Start</th>
                                <th style="text-align:left; padding:6px 8px; width:11%;">Ende</th>
                                <th style="text-align:left; padding:6px 8px; width:36%;">Aktivität</th>
                                <th style="text-align:left; padding:6px 8px; width:36%;">Raum</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $rowIndex = 0; @endphp
                            @foreach($dayData['rows'] as $row)
                                @php
                                    $bgColor = $rowIndex % 2 === 0 ? '#ffffff' : '#f9f9f9';
                                    $rowIndex++;
                                @endphp
                                <tr style="background-color:{{ $bgColor }};">
                                    <td style="text-align:center; padding:4px;">
                                        @if(!empty($row['is_free']))
                                            <img src="{{ public_path('flow/hourglass.png') }}" alt="Free interval" style="height:16px; width:auto;">
                                        @endif
                                    </td>
                                    <td style="padding:5px 8px;">{{ $row['start'] }}</td>
                                    <td style="padding:5px 8px;">{{ $row['end'] }}</td>
                                    <td style="padding:5px 8px;">{{ $row['activity'] }}</td>
                                    <td style="padding:5px 8px;">{{ $row['room'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>

                {{-- Rechte Spalte: QR-Code (only on first day) --}}
                @if($loop->first)
                    @include('pdf.content.right_qr', ['event' => $event, 'roomsWithNav' => $roomsWithNav ?? []])
                @else
                    <td style="width:16.667%;"></td>
                @endif
            </tr>
        </table>
    @endforeach
@else
    {{-- Single-day team: direct table without day grouping --}}
    <table style="width:100%; border-collapse:collapse;">
        <tr valign="top">
            {{-- Linke Spalte: Tabelle --}}
            <td style="width:83.333%; padding-right:20px;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background-color:#f5f5f5;">
                            <th style="text-align:center; padding:6px 4px; width:6%;"></th>
                            <th style="text-align:left; padding:6px 8px; width:11%;">Start</th>
                            <th style="text-align:left; padding:6px 8px; width:11%;">Ende</th>
                            <th style="text-align:left; padding:6px 8px; width:36%;">Aktivität</th>
                            <th style="text-align:left; padding:6px 8px; width:36%;">Raum</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $rowIndex = 0; @endphp
                        @foreach($rows as $row)
                            @php
                                $bgColor = $rowIndex % 2 === 0 ? '#ffffff' : '#f9f9f9';
                                $rowIndex++;
                            @endphp
                            <tr style="background-color:{{ $bgColor }};">
                                <td style="text-align:center; padding:4px;">
                                    @if(!empty($row['is_free']))
                                        <img src="{{ public_path('flow/hourglass.png') }}" alt="Free interval" style="height:16px; width:auto;">
                                    @endif
                                </td>
                                <td style="padding:5px 8px;">{{ $row['start'] }}</td>
                                <td style="padding:5px 8px;">{{ $row['end'] }}</td>
                                <td style="padding:5px 8px;">{{ $row['activity'] }}</td>
                                <td style="padding:5px 8px;">{{ $row['room'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </td>

            {{-- Rechte Spalte: QR-Code --}}
            @include('pdf.content.right_qr', ['event' => $event, 'roomsWithNav' => $roomsWithNav ?? []])
        </tr>
    </table>
@endif