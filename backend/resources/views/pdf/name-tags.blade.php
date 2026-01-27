<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Namensaufkleber</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 13.5mm 17.5mm; /* top/bottom: 13.5mm, left/right: 17.5mm */
        }

        body {
            font-family: sans-serif;
            margin: 0;
            padding: 0;
        }

        /* Label container - absolute positioning for precise placement */
        .label {
            position: absolute;
            width: 80mm;
            height: 50mm;
            padding: 5mm 2mm; /* top: 5mm, left/right: 2mm */
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* Person name - large, bold, top */
        .person-name {
            font-size: 18px;
            font-weight: bold;
            line-height: 1.2;
            margin-bottom: 4px;
            word-wrap: break-word;
        }

        /* Team name - smaller, below person name */
        .team-name {
            font-size: 12px;
            line-height: 1.2;
            margin-bottom: 8px;
            color: #333;
            word-wrap: break-word;
        }

        /* Logos container - bottom of label */
        .logos-container {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 8px;
            margin-top: auto;
            flex-wrap: wrap;
        }

        .logo {
            max-height: 15mm;
            max-width: 20mm;
            width: auto;
            height: auto;
            object-fit: contain;
        }

        /* Column 1 positions */
        .col1-row1 { top: 13.5mm; left: 17.5mm; }
        .col1-row2 { top: 68.5mm; left: 17.5mm; } /* 13.5 + 50 + 5 */
        .col1-row3 { top: 123.5mm; left: 17.5mm; } /* 13.5 + 50 + 5 + 50 + 5 */
        .col1-row4 { top: 178.5mm; left: 17.5mm; } /* 13.5 + 50 + 5 + 50 + 5 + 50 + 5 */

        /* Column 2 positions (17.5mm left margin + 80mm label + 15mm spacing) */
        .col2-row1 { top: 13.5mm; left: 112.5mm; }
        .col2-row2 { top: 68.5mm; left: 112.5mm; }
        .col2-row3 { top: 123.5mm; left: 112.5mm; }
        .col2-row4 { top: 178.5mm; left: 112.5mm; }
    </style>
</head>
<body>
    @php
        // Calculate labels per page: 2 columns × 4 rows = 8 labels per page
        $labelsPerPage = 8;
        $totalLabels = count($nameTags);
        $totalPages = ceil($totalLabels / $labelsPerPage);
    @endphp

    @for($page = 0; $page < $totalPages; $page++)
        @php
            $startIdx = $page * $labelsPerPage;
            $endIdx = min($startIdx + $labelsPerPage, $totalLabels);
            $pageLabels = array_slice($nameTags, $startIdx, $endIdx - $startIdx);
        @endphp

        <div style="page-break-after: {{ $page < $totalPages - 1 ? 'always' : 'auto' }};">
            @foreach($pageLabels as $index => $nameTag)
                @php
                    // Calculate position within this page (0-7)
                    $pagePosition = $index;
                    // Column: 0 = left (col1), 1 = right (col2)
                    $col = ($pagePosition % 2) + 1;
                    // Row: 0-3, but we need 1-4
                    $row = floor($pagePosition / 2) + 1;
                    $cssClass = "col{$col}-row{$row}";
                @endphp

                <div class="label {{ $cssClass }}">
                    <div>
                        <div class="person-name">{{ e($nameTag['person_name']) }}</div>
                        <div class="team-name">{{ e($nameTag['team_name']) }}</div>
                    </div>
                    
                    <div class="logos-container">
                        @php
                            // Get program logo from cache instead of storing in each name tag
                            $programLogo = $programLogoCache[$nameTag['program']] ?? null;
                        @endphp
                        
                        @if(!empty($programLogo))
                            <img src="{{ $programLogo }}" alt="Program Logo" class="logo" />
                        @endif
                        
                        @if(!empty($seasonLogo))
                            <img src="{{ $seasonLogo }}" alt="Season Logo" class="logo" />
                        @endif
                        
                        @if(!empty($organizerLogos))
                            @foreach($organizerLogos as $organizerLogo)
                                <img src="{{ $organizerLogo }}" alt="Organizer Logo" class="logo" />
                            @endforeach
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endfor
</body>
</html>
