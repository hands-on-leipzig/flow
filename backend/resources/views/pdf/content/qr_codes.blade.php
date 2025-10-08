{{-- resources/views/pdf/content/event.blade.php --}}

@if ($wifi && !empty($event->wifi_ssid) && !empty($event->wifi_qrcode))
    {{-- Zwei Spalten: links Plan, rechts WLAN --}}
    <table style="width:100%; table-layout:fixed; border-collapse:collapse; margin-bottom: 40px;">
        <tr>
            {{-- Plan-QR links --}}
            <td style="width:50%; text-align:center; vertical-align:top; padding:10px;">
                <div style="margin-top:10px; font-size:20px; color:#333;">Online Zeitplan</div>
                <img src="data:image/png;base64,{{ $event->qrcode }}" style="width:200px; height:200px;" />
                <div style="margin-top:10px; font-size:16px; color:#333;">{{ e($event->link) }}</div>
            </td>

            {{-- WLAN rechts --}}
            <td style="width:50%; text-align:center; vertical-align:top; padding:10px;">
                <div style="margin-top:10px; font-size:20px; color:#333;">Kostenloses WLAN</div>
                <img src="data:image/png;base64,{{ $event->wifi_qrcode }}" style="width:200px; height:200px;" />
                <div style="margin-top:10px; font-size:14px; color:#333;">
                    SSID: {{ e($event->wifi_ssid) }}<br>
                    @if (!empty($wifiPassword))
                        Passwort: {{ e($wifiPassword) }}
                    @else
                        Kein Passwort erforderlich
                    @endif
                </div>
 
                @if (!empty($event->wifi_instruction))
                    <div style="margin:8px auto 0 auto;
                                max-width:200px;
                                border:1px solid #ccc;
                                border-radius:6px;
                                padding:6px;
                                font-size:12px;
                                color:#555;
                                text-align:left;
                                line-height:1.3;">
                        {!! nl2br(e(trim($event->wifi_instruction))) !!}
                    </div>
                @endif
            </td>
        </tr>
    </table>
@else
    {{-- Nur Plan-QR mittig --}}
    <div style="text-align:center; margin-bottom:40px;">
        <div style="margin-top:10px; font-size:20px; color:#333;">Online Zeitplan</div>
        <img src="data:image/png;base64,{{ $event->qrcode }}" style="width:200px; height:200px;" />
        <div style="margin-top:10px; font-size:16px; color:#333;">{{ e($event->link) }}</div>
    </div>
@endif