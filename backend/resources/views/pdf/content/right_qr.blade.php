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

    <img src="data:image/png;base64,{{ $event->qrcode }}" style="width:100px; height:100px; margin-bottom:10px;" />

    <div style="
        font-size:12px;
        color:#444;
        word-break:break-all;
        font-family:sans-serif;
    ">
        {{ $event->link }}
    </div>

    @if(!empty($roomsWithNav) && count($roomsWithNav) > 0)
        @php
            // Sort rooms by name (case-insensitive)
            $sortedRooms = $roomsWithNav;
            uksort($sortedRooms, function($a, $b) {
                return strcasecmp($a, $b);
            });
        @endphp
        <div style="
            margin-top:20px;
            padding-top:15px;
            border-top:1px solid #ddd;
        ">
            <div style="
                font-size:16px;
                font-weight:bold;
                margin-bottom:10px;
                color:#222;
                font-family:sans-serif;
                letter-spacing:0.3px;
            ">
                Hinweise&nbsp;zu&nbsp;den&nbsp;Räumen
            </div>
            
            <table style="width:100%; border-collapse:collapse;">
                <tbody>
                    @foreach($sortedRooms as $roomName => $roomData)
                        @php
                            $navigationText = is_array($roomData) ? ($roomData['navigation'] ?? '') : $roomData;
                            $navigationText = is_string($navigationText) ? $navigationText : '';
                            $isAccessible = is_array($roomData)
                                ? (!array_key_exists('is_accessible', $roomData) ? true : (bool)$roomData['is_accessible'])
                                : true;
                        @endphp
                        <tr>
                            <td valign="top" style="width:33%; padding:4px 8px 4px 6px; vertical-align:top; font-size:12px; color:#444; font-family:sans-serif; white-space:normal; line-height:1.2;">
                                {{ $roomName }}
                                @if(!$isAccessible)
                                    <img src="{{ public_path('flow/accessible_no.png') }}" alt="Nicht barrierefrei" style="height:12px; width:auto; margin-left:4px;">
                                @endif
                            </td>
                            <td valign="top" style="width:67%; padding:4px 6px; vertical-align:top; font-size:12px; color:#444; font-family:sans-serif; white-space:normal; word-wrap:break-word; line-height:1.2;">
                                @if(trim($navigationText) !== '')
                                    {{ $navigationText }}
                                @else
                                    &nbsp;
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</td>