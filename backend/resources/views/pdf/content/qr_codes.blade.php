{{-- resources/views/pdf/content/event.blade.php --}}

@php
    // Responsive logo sizing based on count
    $logoCount = count($footerLogos ?? []);
    $logoSize = 90; // Default for 5+ logos
    $maxLogosPerRow = 4;
    
    if ($logoCount <= 2) {
        $logoSize = 120;
    } elseif ($logoCount <= 4) {
        $logoSize = 100;
    }
    
    // Determine if we need 2 rows (5+ logos)
    $useTwoRows = $logoCount > $maxLogosPerRow;
@endphp

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

{{-- Footer Logos: Responsive layout at bottom of content area --}}
@if(!empty($footerLogos) && $logoCount > 0)
    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
        @if($useTwoRows)
            {{-- Two-row grid for 5+ logos --}}
            <table style="width: 100%; border-collapse: collapse; margin: 0 auto;">
                @php
                    $firstRow = array_slice($footerLogos, 0, ceil($logoCount / 2));
                    $secondRow = array_slice($footerLogos, ceil($logoCount / 2));
                @endphp
                <tr>
                    @foreach($firstRow as $src)
                        <td style="text-align: center; vertical-align: middle; padding: 8px; width: {{ 100 / count($firstRow) }}%;">
                            <img src="{{ $src }}" alt="Sponsor logo" style="max-width: {{ $logoSize }}px; max-height: {{ $logoSize }}px; width: auto; height: auto; display: inline-block;" />
                        </td>
                    @endforeach
                </tr>
                <tr>
                    @foreach($secondRow as $src)
                        <td style="text-align: center; vertical-align: middle; padding: 8px; width: {{ 100 / count($secondRow) }}%;">
                            <img src="{{ $src }}" alt="Sponsor logo" style="max-width: {{ $logoSize }}px; max-height: {{ $logoSize }}px; width: auto; height: auto; display: inline-block;" />
                        </td>
                    @endforeach
                </tr>
            </table>
        @else
            {{-- Single-row layout for 1-4 logos --}}
            <table style="width: 100%; border-collapse: collapse; margin: 0 auto;">
                <tr>
                    @foreach($footerLogos as $src)
                        <td style="text-align: center; vertical-align: middle; padding: 8px; width: {{ 100 / $logoCount }}%;">
                            <img src="{{ $src }}" alt="Sponsor logo" style="max-width: {{ $logoSize }}px; max-height: {{ $logoSize }}px; width: auto; height: auto; display: inline-block;" />
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
    </div>
@endif