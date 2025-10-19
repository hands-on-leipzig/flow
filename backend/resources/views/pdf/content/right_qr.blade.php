{{-- resources/views/pdf/partials/right_qr.blade.php --}}
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
        Alle Aktivitäten der Veranstaltung, sortiert nach Teams, Räumen und Rollen.
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

    @if(!empty($roomsWithNav) && count($roomsWithNav) > 0)
        <div style="
            margin-top:20px;
            padding-top:15px;
            border-top:1px solid #ddd;
        ">
            <div style="
                font-size:14px;
                font-weight:bold;
                color:#222;
                margin-bottom:8px;
                font-family:sans-serif;
            ">
                Raumorientierung
            </div>
            
            @foreach($roomsWithNav as $roomName => $navigation)
                <div style="margin-bottom:8px;">
                    <div style="
                        font-size:13px;
                        font-weight:bold;
                        color:#333;
                        font-family:sans-serif;
                    ">
                        {{ $roomName }}
                    </div>
                    <div style="
                        font-size:11px;
                        color:#666;
                        font-family:sans-serif;
                        line-height:1.3;
                    ">
                        {{ $navigation }}
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</td>