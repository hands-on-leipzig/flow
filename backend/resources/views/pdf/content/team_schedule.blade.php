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
@endphp

<h2 style="margin-bottom:6px; font-size:22px; font-weight:bold; font-family:sans-serif; display:flex; align-items:center; gap:10px;">
    @if($icon)
        <img src="file://{{ $icon }}" alt="Program Icon" style="height:28px; width:auto; vertical-align:middle;">
    @endif
    {{ $cleanTitle }}
</h2>
@if(!empty($multi_day_event) && !empty($page_date))
    <div style="background-color: #34495e; color: white; padding: 6px 10px; margin: 0 0 8px 0; font-size: 14px; border-radius: 3px;">
        {{ $page_date->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}
    </div>
@endif
<table style="width:100%; border-collapse:collapse;">
    <tr valign="top">
        {{-- Linke Spalte: Tabelle --}}
        <td style="width:83.333%; padding-right:20px;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background-color:#f5f5f5;">
                        <th style="text-align:center; padding:6px 4px; width:4%;"></th>
                        <th style="text-align:left; padding:6px 8px; width:8%;">Start</th>
                        <th style="text-align:left; padding:6px 8px; width:8%;">Ende</th>
                        <th style="text-align:left; padding:6px 8px; width:40%;">Aktivität</th>
                        <th style="text-align:left; padding:6px 8px; width:40%;">Raum</th>
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