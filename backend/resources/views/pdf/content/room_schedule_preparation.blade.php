@php
    $toDataUri = function (string $path): ?string {
        if (!is_file($path)) {
            return null;
        }
        $mime = mime_content_type($path) ?: 'image/png';
        $data = @file_get_contents($path);
        if ($data === false) {
            return null;
        }
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    };
    $programIcons = [];
    foreach (collect($rows)->pluck('program_names')->flatten()->unique()->filter() as $name) {
        $programIcons[$name] = $toDataUri(\App\Support\ProgramCatalog::logoPath($name, 'v'));
    }
@endphp
<h2 style="margin-bottom: 15px; font-size: 22px; font-weight: bold;">
    {{ $room }}
</h2>

<table style="width:100%; border-collapse:collapse;">
    <tr valign="top">
        {{-- Linke Spalte: Teams im Vorbereitungsraum --}}
        <td style="width:66%; padding-right:20px;">
            @php
                $teamCount = count($rows ?? []);
                $useTwoColumns = $teamCount > 18;
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
                                        <th style="text-align:center; padding:6px 4px; width:12%;"></th>
                                        <th style="text-align:left; padding:6px 8px;">Team</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($leftRows as $i => $row)
                                        @php
                                            $bgColor = $i % 2 === 0 ? '#ffffff' : '#f9f9f9';
                                        @endphp
                                        <tr bgcolor="{{ $bgColor }}">
                                            <td style="text-align:center; padding:4px; white-space:nowrap;">
                                                @foreach(($row['program_names'] ?? []) as $name)
                                                    @if(!empty($programIcons[$name]))
                                                        <img src="{{ $programIcons[$name] }}" alt="{{ $name }}" style="height:16px; margin-right:2px;">
                                                    @endif
                                                @endforeach
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
                                        <th style="text-align:center; padding:6px 4px; width:12%;"></th>
                                        <th style="text-align:left; padding:6px 8px;">Team</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($rightRows as $i => $row)
                                        @php
                                            $bgColor = $i % 2 === 0 ? '#ffffff' : '#f9f9f9';
                                        @endphp
                                        <tr bgcolor="{{ $bgColor }}">
                                            <td style="text-align:center; padding:4px; white-space:nowrap;">
                                                @foreach(($row['program_names'] ?? []) as $name)
                                                    @if(!empty($programIcons[$name]))
                                                        <img src="{{ $programIcons[$name] }}" alt="{{ $name }}" style="height:16px; margin-right:2px;">
                                                    @endif
                                                @endforeach
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
                            <th style="text-align:center; padding:6px 4px; width:8%;"></th>
                            <th style="text-align:left; padding:6px 8px;">Team</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $i => $row)
                            @php
                                $bgColor = $i % 2 === 0 ? '#ffffff' : '#f9f9f9';
                            @endphp
                            <tr bgcolor="{{ $bgColor }}">
                                <td style="text-align:center; padding:4px; white-space:nowrap;">
                                    @foreach(($row['program_names'] ?? []) as $name)
                                        @if(!empty($programIcons[$name]))
                                            <img src="{{ $programIcons[$name] }}" alt="{{ $name }}" style="height:16px; margin-right:2px;">
                                        @endif
                                    @endforeach
                                </td>
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
