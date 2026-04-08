<h2 style="margin-bottom: 15px; font-size: 22px; font-weight: bold;">
    {{ $room }}
</h2>

<table style="width:100%; border-collapse:collapse;">
    <tr valign="top">
        {{-- Linke Spalte: Teams im Vorbereitungsraum --}}
        <td style="width:66%; padding-right:20px;">
            @php
                $teamCount = count($rows ?? []);
                $useTwoColumns = $teamCount > 20;
            @endphp

            @if($useTwoColumns)
                @php
                    $rowsCollection = collect($rows);
                    $splitPoint = (int) ceil($teamCount / 2);
                    $leftRows = $rowsCollection->slice(0, $splitPoint)->values()->all();
                    $rightRows = $rowsCollection->slice($splitPoint)->values()->all();
                @endphp

                <table style="width:100%; border-collapse:collapse;">
                    <tr valign="top">
                        <td style="width:50%; padding-right:8px;">
                            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                <thead>
                                    <tr style="background-color:#f5f5f5;">
                                        <th style="text-align:center; padding:6px 4px; width:8%;"></th>
                                        <th style="text-align:center; padding:6px 4px; width:8%;"></th>
                                        <th style="text-align:left; padding:6px 8px;">Team</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($leftRows as $i => $row)
                                        @php
                                            $bgColor = $i % 2 === 0 ? '#ffffff' : '#f9f9f9';
                                        @endphp
                                        <tr bgcolor="{{ $bgColor }}">
                                            <td style="text-align:center; padding:4px;">
                                                @if($row['is_explore'])
                                                    <img src="{{ public_path('flow/fll_explore_v.png') }}" alt="Explore" style="height:16px;">
                                                @endif
                                            </td>
                                            <td style="text-align:center; padding:4px;">
                                                @if($row['is_challenge'])
                                                    <img src="{{ public_path('flow/fll_challenge_v.png') }}" alt="Challenge" style="height:16px;">
                                                @endif
                                            </td>
                                            <td style="padding:5px 8px;">{!! \App\Helpers\PdfHelper::formatTeamNameWithNoshow($row['team_display'] ?? '–', $row['team_is_noshow'] ?? false) !!}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                        <td style="width:50%; padding-left:8px;">
                            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                <thead>
                                    <tr style="background-color:#f5f5f5;">
                                        <th style="text-align:center; padding:6px 4px; width:8%;"></th>
                                        <th style="text-align:center; padding:6px 4px; width:8%;"></th>
                                        <th style="text-align:left; padding:6px 8px;">Team</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($rightRows as $i => $row)
                                        @php
                                            $bgColor = $i % 2 === 0 ? '#ffffff' : '#f9f9f9';
                                        @endphp
                                        <tr bgcolor="{{ $bgColor }}">
                                            <td style="text-align:center; padding:4px;">
                                                @if($row['is_explore'])
                                                    <img src="{{ public_path('flow/fll_explore_v.png') }}" alt="Explore" style="height:16px;">
                                                @endif
                                            </td>
                                            <td style="text-align:center; padding:4px;">
                                                @if($row['is_challenge'])
                                                    <img src="{{ public_path('flow/fll_challenge_v.png') }}" alt="Challenge" style="height:16px;">
                                                @endif
                                            </td>
                                            <td style="padding:5px 8px;">{!! \App\Helpers\PdfHelper::formatTeamNameWithNoshow($row['team_display'] ?? '–', $row['team_is_noshow'] ?? false) !!}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </table>
            @else
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background-color:#f5f5f5;">
                            <th style="text-align:center; padding:6px 4px; width:5%;"></th>
                            <th style="text-align:center; padding:6px 4px; width:5%;"></th>
                            <th style="text-align:left; padding:6px 8px;">Team</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $i => $row)
                            @php
                                $bgColor = $i % 2 === 0 ? '#ffffff' : '#f9f9f9';
                            @endphp
                            <tr bgcolor="{{ $bgColor }}">
                                {{-- Explore Icon --}}
                                <td style="text-align:center; padding:4px;">
                                    @if($row['is_explore'])
                                        <img src="{{ public_path('flow/fll_explore_v.png') }}" alt="Explore" style="height:16px;">
                                    @endif
                                </td>

                                {{-- Challenge Icon --}}
                                <td style="text-align:center; padding:4px;">
                                    @if($row['is_challenge'])
                                        <img src="{{ public_path('flow/fll_challenge_v.png') }}" alt="Challenge" style="height:16px;">
                                    @endif
                                </td>

                                {{-- Teamname --}}
                                <td style="padding:5px 8px;">{!! \App\Helpers\PdfHelper::formatTeamNameWithNoshow($row['team_display'] ?? '–', $row['team_is_noshow'] ?? false) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </td>

        {{-- Rechte Spalte: QR-Code (identisch wie bei anderen Raumseiten) --}}
        @include('pdf.content.right_qr', ['event' => $event])
    </tr>
</table>