{{-- resources/views/pdf/content/event.blade.php --}}

@php
    // Adaptive grid sizing based on logo count (Option C)
    $logoCount = count($footerLogos ?? []);
    $logoSize = 100; // Default
    $layoutType = 'single'; // 'single', 'grid', 'asymmetric'
    $colsPerRow = $logoCount;
    
    if ($logoCount === 1) {
        $logoSize = 150;
        $layoutType = 'single';
        $colsPerRow = 1;
    } elseif ($logoCount === 2) {
        $logoSize = 140;
        $layoutType = 'single';
        $colsPerRow = 2;
    } elseif ($logoCount === 3) {
        $logoSize = 135;
        $layoutType = 'single';
        $colsPerRow = 3;
    } elseif ($logoCount === 4) {
        $logoSize = 125;
        $layoutType = 'grid';
        $colsPerRow = 2; // 2×2 grid
    } elseif ($logoCount === 5) {
        $logoSize = 115;
        $layoutType = 'asymmetric'; // 3 top + 2 bottom (centered)
        $colsPerRow = 3; // Top row
    } elseif ($logoCount === 6) {
        $logoSize = 110;
        $layoutType = 'grid';
        $colsPerRow = 3; // 3×2 grid
    } elseif ($logoCount >= 7) {
        $logoSize = 100;
        $layoutType = 'grid';
        $colsPerRow = 4; // 4×2 grid (or more rows if needed)
    }
@endphp

@if ($wifi && !empty($event->wifi_ssid) && !empty($event->wifi_qrcode))
    {{-- Zwei Spalten: links Plan, rechts WLAN --}}
    <table style="width:100%; table-layout:fixed; border-collapse:collapse; margin-bottom: 20px;">
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
    <div style="text-align:center; margin-bottom:20px;">
        <div style="margin-top:10px; font-size:20px; color:#333;">Online Zeitplan</div>
        <img src="data:image/png;base64,{{ $event->qrcode }}" style="width:200px; height:200px;" />
        <div style="margin-top:10px; font-size:16px; color:#333;">{{ e($event->link) }}</div>
    </div>
@endif

{{-- Footer Logos: Adaptive grid layout with full-width distribution --}}
@if(!empty($footerLogos) && $logoCount > 0)
    <div style="margin-top: 20px; padding: 15px 20px; border-top: 1px solid #eee;">
        @if($layoutType === 'single')
            {{-- Single row: 1-3 logos --}}
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    @foreach($footerLogos as $src)
                        <td style="text-align: center; vertical-align: middle; padding: 8px; width: {{ 100 / $logoCount }}%;">
                            <img src="{{ $src }}" alt="Sponsor logo" style="max-width: {{ $logoSize }}px; max-height: {{ $logoSize }}px; width: auto; height: auto; display: inline-block;" />
                        </td>
                    @endforeach
                </tr>
            </table>
        @elseif($layoutType === 'asymmetric' && $logoCount === 5)
            {{-- Asymmetric layout: 5 logos (3 top + 2 bottom centered) --}}
            @php
                $topRow = array_slice($footerLogos, 0, 3);
                $bottomRow = array_slice($footerLogos, 3);
            @endphp
            {{-- Top row: 3 logos full width --}}
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
                <tr>
                    @foreach($topRow as $src)
                        <td style="text-align: center; vertical-align: middle; padding: 8px; width: 33.33%;">
                            <img src="{{ $src }}" alt="Sponsor logo" style="max-width: {{ $logoSize }}px; max-height: {{ $logoSize }}px; width: auto; height: auto; display: inline-block;" />
                        </td>
                    @endforeach
                </tr>
            </table>
            {{-- Bottom row: 2 logos centered --}}
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 25%;"></td> {{-- Left spacer --}}
                    @foreach($bottomRow as $src)
                        <td style="text-align: center; vertical-align: middle; padding: 8px; width: 25%;">
                            <img src="{{ $src }}" alt="Sponsor logo" style="max-width: {{ $logoSize }}px; max-height: {{ $logoSize }}px; width: auto; height: auto; display: inline-block;" />
                        </td>
                    @endforeach
                    <td style="width: 25%;"></td> {{-- Right spacer --}}
                </tr>
            </table>
        @elseif($layoutType === 'grid')
            {{-- Grid layout: 4, 6, or 7+ logos --}}
            @php
                $rows = ceil($logoCount / $colsPerRow);
            @endphp
            @for($row = 0; $row < $rows; $row++)
                @php
                    $startIdx = $row * $colsPerRow;
                    $endIdx = min($startIdx + $colsPerRow, $logoCount);
                    $rowLogos = array_slice($footerLogos, $startIdx, $endIdx - $startIdx);
                    $colsInThisRow = count($rowLogos);
                    $isLastRow = ($row === $rows - 1);
                    $needsCentering = $isLastRow && $colsInThisRow < $colsPerRow;
                @endphp
                <table style="width: 100%; border-collapse: collapse;{{ $row > 0 ? ' margin-top: 12px;' : '' }}">
                    <tr>
                        @if($needsCentering)
                            {{-- Center the last row if it has fewer logos --}}
                            @php
                                $logoWidthPercent = 100 / $colsPerRow;
                                $usedWidth = $logoWidthPercent * $colsInThisRow;
                                $spacerWidth = (100 - $usedWidth) / 2;
                            @endphp
                            <td style="width: {{ $spacerWidth }}%;"></td>
                            @foreach($rowLogos as $src)
                                <td style="text-align: center; vertical-align: middle; padding: 8px; width: {{ $logoWidthPercent }}%;">
                                    <img src="{{ $src }}" alt="Sponsor logo" style="max-width: {{ $logoSize }}px; max-height: {{ $logoSize }}px; width: auto; height: auto; display: inline-block;" />
                                </td>
                            @endforeach
                            <td style="width: {{ $spacerWidth }}%;"></td>
                        @else
                            {{-- Full width row --}}
                            @php
                                $logoWidthPercent = 100 / $colsInThisRow;
                            @endphp
                            @foreach($rowLogos as $src)
                                <td style="text-align: center; vertical-align: middle; padding: 8px; width: {{ $logoWidthPercent }}%;">
                                    <img src="{{ $src }}" alt="Sponsor logo" style="max-width: {{ $logoSize }}px; max-height: {{ $logoSize }}px; width: auto; height: auto; display: inline-block;" />
                                </td>
                            @endforeach
                        @endif
                    </tr>
                </table>
            @endfor
        @endif
    </div>
@endif