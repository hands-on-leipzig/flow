<h2 style="margin-bottom: 15px; font-size: 22px; font-weight: bold;">
    {{ $team }}
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
        <td style="width:34%; text-align:center;">

            <div style="
                font-size:16px;
                font-weight:bold;
                margin-bottom:10px;
                color:#222;
                font-family:sans-serif;
                letter-spacing:0.3px;
            ">
                Online&nbsp;Zeitplan
            </div>

            <div style="
                    font-size:11px;
                    color:#888;
                    margin-top:8px;
                    font-family:sans-serif;
                ">
                Alle Aktivitäten der Veranstaltung, sortiert nach Teams und Räumen.
            </div>

            <img src="data:image/png;base64,{{ $event->qrcode }}" style="width:180px; height:180px; margin-bottom:10px;" />

            <div style="
                font-size:12px;
                color:#444;
                word-break:break-all;
                font-family:sans-serif;
            ">
                {{ $event->link }}
            </div>

        </td>
    </tr>
</table>