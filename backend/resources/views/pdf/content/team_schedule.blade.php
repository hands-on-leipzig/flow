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

<h2 style="margin-bottom:15px; font-size:22px; font-weight:bold; font-family:sans-serif; display:flex; align-items:center; gap:10px;">
    @if($icon)
        <img src="file://{{ $icon }}" alt="Program Icon" style="height:28px; width:auto; vertical-align:middle;">
    @endif
    {{ $cleanTitle }}
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
                        <th style="text-align:left; padding:6px 8px; width:40%;">Aktivität</th>
                        <th style="text-align:left; padding:6px 8px; width:40%;">Raum</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $i => $row)
                        <tr style="background-color:{{ $i % 2 === 0 ? '#ffffff' : '#f9f9f9' }};">
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
        @include('pdf.content.right_qr', ['event' => $event])

    </tr>
</table>