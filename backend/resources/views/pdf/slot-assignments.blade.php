<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Slot-Zuordnung</title>
    <style>
        @page { size: A4 portrait; margin: 20mm 15mm 15mm 15mm; }
        body { font-family: sans-serif; font-size: 11px; color: #333; line-height: 1.4; }
        .header { margin-bottom: 15px; }
        .header h1 { font-size: 16px; margin: 0 0 3px 0; font-weight: bold; }
        .header p { font-size: 10px; color: #555; margin: 0; }
        .section { margin-bottom: 20px; }
        .section-header { font-size: 13px; font-weight: bold; margin-bottom: 8px; padding-bottom: 3px; border-bottom: 1px solid #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th, td { border: 1px solid #333; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background-color: #f0f0f0; font-weight: bold; text-align: left; }
        td { font-size: 10px; }
        .noshow { text-decoration: line-through; }
        .day-header { background-color: #34495e; color: white; padding: 8px 12px; margin: 0 0 10px 0; font-size: 16px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $eventName }} – {{ $eventDate }}</h1>
        <p>Letzte Änderung: {{ $lastUpdated }}</p>
    </div>

    @foreach(($slots ?? []) as $slotIndex => $slot)
        <div class="section">
            <div class="section-header">{{ $slot['slot_name'] ?? 'Slot' }}</div>

            @php
                $assignmentsByDay = [];
                foreach (($slot['assignments'] ?? []) as $a) {
                    $dayKey = !empty($a['start_date']) ? $a['start_date']->format('Y-m-d') : 'unscheduled';
                    if (!isset($assignmentsByDay[$dayKey])) {
                        $assignmentsByDay[$dayKey] = [
                            'date' => $a['start_date'] ?? null,
                            'rows' => []
                        ];
                    }
                    $assignmentsByDay[$dayKey]['rows'][] = $a;
                }
            @endphp

            @if(empty($slot['assignments']))
                <p>Keine Zuordnungen vorhanden.</p>
            @else
                @foreach($assignmentsByDay as $dayKey => $dayData)
                    @if($dayKey === 'unscheduled')
                        <div class="day-header">Ohne Startzeit</div>
                    @elseif(!empty($dayData['date']))
                        <div class="day-header">{{ $dayData['date']->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}</div>
                    @endif

                    <table>
                        <thead>
                            <tr>
                                <th style="width: 20%;">Start</th>
                                <th style="width: 80%;">Team</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($dayData['rows'] as $row)
                                <tr>
                                    <td>{{ $row['start_time'] }}</td>
                                    <td>{!! \App\Helpers\PdfHelper::formatTeamNameWithNoshow($row['team_label'] ?? '–', $row['team_noshow'] ?? false) !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endforeach
            @endif
        </div>

        @if($slotIndex < count($slots) - 1)
            <div style="page-break-before: always;"></div>
        @endif
    @endforeach
</body>
</html>
