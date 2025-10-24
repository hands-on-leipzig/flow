@php
$contentHtml = '
<div style="font-family: sans-serif; line-height: 1.6; color: #333;">
    
    <h1 style="text-align: center; margin-bottom: 30px; color: #2c3e50; font-size: 24px;">
        Übersichtsplan
    </h1>';

// Calculate global time range for all days
$globalEarliestHour = null;
$globalLatestHour = null;

// First pass: calculate global time range
foreach($eventsByDay as $dayKey => $dayData) {
    $allEvents = collect($dayData['events']);
    $earliestStart = $allEvents->min('earliest_start');
    $latestEnd = $allEvents->max('latest_end');
    
    // Find earliest and latest hours for this day
    $dayEarliestHour = $earliestStart->hour;
    $dayLatestHour = $latestEnd->hour;
    if ($latestEnd->minute > 0) $dayLatestHour++; // Round up if there are minutes
    
    // Update global min/max hours
    if ($globalEarliestHour === null || $dayEarliestHour < $globalEarliestHour) {
        $globalEarliestHour = $dayEarliestHour;
    }
    if ($globalLatestHour === null || $dayLatestHour > $globalLatestHour) {
        $globalLatestHour = $dayLatestHour;
    }
}

// Create 5-minute grid from global earliest hour to latest hour
$startTime = \Carbon\Carbon::createFromTime($globalEarliestHour, 0, 0);
$endTime = \Carbon\Carbon::createFromTime($globalLatestHour, 0, 0);

// Debug: Log the time range
\Log::info('Time range debug', [
    'globalEarliestHour' => $globalEarliestHour,
    'globalLatestHour' => $globalLatestHour,
    'startTime' => $startTime->format('H:i'),
    'endTime' => $endTime->format('H:i')
]);

// Generate all 5-minute slots
$timeSlots = [];
$current = $startTime->copy();
while ($current->lt($endTime)) {
    $timeSlots[] = $current->copy();
    $current->addMinutes(5);
}

// Second pass: generate content for each day
foreach($eventsByDay as $dayKey => $dayData) {
    $allEvents = collect($dayData['events']);
    
    $contentHtml .= '
    <div style="margin-bottom: 30px; page-break-inside: avoid;">
        <!-- Day Header -->
        <h2 style="background-color: #34495e; color: white; padding: 8px 12px; margin: 0 0 10px 0; font-size: 16px; border-radius: 3px;">
            ' . $dayData['date']->locale('de')->isoFormat('dddd, DD.MM.YYYY') . '
        </h2>

        <!-- Time Grid Layout -->
        <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
            <thead>
                <tr>
                    <th style="width: 8%; background-color: #f8f9fa; padding: 4px; border: 1px solid #ddd; font-size: 10px; font-weight: bold;">Zeit</th>';
        
        // Dynamic column headers
        $columnWidth = 92 / count($columnNames);
        $columnColors = [
            'Explore' => '#27ae60',
            'Challenge' => '#e74c3c', 
            'Live-Challenge' => '#f39c12',
            'Robot-Game' => '#8e44ad',
            'Allgemein' => '#95a5a6'
        ];
        
        // Debug: Log column names being used in template
        \Log::info('Blade template column names', [
            'columnNames' => $columnNames,
            'count' => count($columnNames)
        ]);
        
            foreach($columnNames as $columnName) {
                // Use column name as display name to show uniqueness
                $displayName = $columnName;
            
            // Map unique column names to colors
            $baseColor = $displayName;
            if (strpos($displayName, 'Allgemein-') === 0) {
                $baseColor = 'Allgemein';
            }
            $color = $columnColors[$baseColor] ?? '#95a5a6';
            $contentHtml .= '
                    <th style="width: ' . $columnWidth . '%; background-color: ' . $color . '; color: white; padding: 4px; border: 1px solid #ddd; font-size: 10px; font-weight: bold;">' . htmlspecialchars($displayName) . '</th>';
        }
        
        $contentHtml .= '
                </tr>
            </thead>
            <tbody>';
    
    // Pre-calculate all events with their rowspan
    $eventsWithRowspan = [];
    foreach($allEvents as $event) {
        $duration = $event['earliest_start']->diffInMinutes($event['latest_end']);
        $rowspan = max(1, ceil($duration / 5));
        $eventsWithRowspan[] = [
            'event' => $event,
            'rowspan' => $rowspan,
            'start_slot' => $event['earliest_start']->format('H:i')
        ];
    }
    
    // Track occupied cells (column => end time slot index)
    $occupiedCells = [];
    
    // Generate 5-minute rows
    foreach ($timeSlots as $index => $slot) {
        $isFullHour = $slot->minute == 0;
        $timeLabel = $isFullHour ? $slot->format('H:i') : '';
        $slotTime = $slot->format('H:i');
        
        $contentHtml .= '
                <tr>';
        
        // Time column with rowspan for full hours
        if ($isFullHour) {
            $contentHtml .= '
                    <td rowspan="12" style="padding: 1px; border: 1px solid #ddd; font-size: 9px; font-weight: bold; background-color: #f8f9fa; text-align: center; vertical-align: middle;">' . $timeLabel . '</td>';
        }
        
        // Dynamic column generation
        foreach($columnNames as $columnName) {
            // Skip if this cell is occupied by a rowspan from a previous row
            if (isset($occupiedCells[$columnName]) && $occupiedCells[$columnName] > $index) {
                continue;
            }
            // Find events for this column
            $columnEvents = collect($eventsWithRowspan)->filter(function($item) use ($slotTime, $columnName) {
                $eventColumn = $item['event']['group_overview_plan_column'] ?? 'Allgemein';
                $eventProgram = $item['event']['group_first_program_id'];
                
                // Debug: Log Check-In events
                if (strpos($item['event']['group_name'], 'Check-In') !== false) {
                    \Log::info('Blade template Check-In debug', [
                        'group_name' => $item['event']['group_name'],
                        'eventColumn' => $eventColumn,
                        'eventProgram' => $eventProgram,
                        'columnName' => $columnName,
                        'slotTime' => $slotTime,
                        'start_slot' => $item['start_slot']
                    ]);
                }
                
                // Only check events that match this column
                if ($eventColumn !== $columnName) {
                    return false;
                }
                
                // Check time slot matching
                $matches = $item['start_slot'] == $slotTime;
                
                // Debug: Log successful matches
                if ($matches && strpos($item['event']['group_name'], 'Check-In') !== false) {
                    \Log::info('Successful Check-In match', [
                        'group_name' => $item['event']['group_name'],
                        'eventColumn' => $eventColumn,
                        'columnName' => $columnName,
                        'slotTime' => $slotTime,
                        'start_slot' => $item['start_slot']
                    ]);
                }
                
                return $matches;
            });
            
            if ($columnEvents->count() > 0) {
                $event = $columnEvents->first()['event'];
                $rowspan = $columnEvents->first()['rowspan'];
                
                // Mark this column as occupied for the next N-1 rows
                $occupiedCells[$columnName] = $index + $rowspan;
                
                // Get color based on the event's actual overview_plan_column, not the column name
                $eventColumn = $event['group_overview_plan_column'] ?? 'Allgemein';
                $columnColors = [
                    'Explore' => ['bg' => '#d5f4e6', 'border' => '#27ae60'],
                    'Challenge' => ['bg' => '#fdeaea', 'border' => '#e74c3c'],
                    'Live-Challenge' => ['bg' => '#fef5e7', 'border' => '#f39c12'],
                    'Robot-Game' => ['bg' => '#f4e6f7', 'border' => '#8e44ad'],
                    'Allgemein' => ['bg' => '#f5f5f5', 'border' => '#95a5a6']
                ];
                
                $colors = $columnColors[$eventColumn] ?? ['bg' => '#f5f5f5', 'border' => '#95a5a6'];
                
                $contentHtml .= '
                    <td rowspan="' . $rowspan . '" style="background-color: ' . $colors['bg'] . '; border-left: 3px solid ' . $colors['border'] . '; padding: 2px 4px; font-size: 9px; font-weight: bold; vertical-align: middle;">
                        ' . htmlspecialchars($event['group_name']) . '
                    </td>';
            } else {
                $contentHtml .= '
                    <td style="padding: 0; border: 1px solid #ddd; height: 8px;"></td>';
            }
        }
        
        $contentHtml .= '
                </tr>';
    }
    
    $contentHtml .= '
            </tbody>
        </table>
    </div>';
}

$contentHtml .= '
</div>';
@endphp

@include('pdf.layout_portrait', ['title' => 'Übersichtsplan - Alle Aktivitäten auf einen Blick'])
