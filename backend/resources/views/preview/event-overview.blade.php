<div class="event-overview">
    @foreach($eventsByDay as $dayKey => $dayData)
        <div class="day-section">
            @if($isMultiDay)
                <!-- Day Header -->
                <div class="day-header">
                    {{ $dayData['date']->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}
                </div>
            @endif

            <!-- Time Grid Layout -->
            <div class="table-container">
                <table class="overview-table">
                    <thead>
                        <tr>
                            <th class="time-column">Zeit</th>
                            @foreach($columnNames as $columnName)
                                @php
                                    $displayName = $columnName;
                                    $baseColor = $displayName;
                                    if (strpos($displayName, 'Allgemein-') === 0) {
                                        $baseColor = 'Allgemein';
                                    }
                                    
                                    // Column colors
                                    $columnColors = [
                                        'Explore' => '#27ae60',
                                        'Challenge' => '#e74c3c', 
                                        'Live-Challenge' => '#8e44ad',
                                        'Robot-Game' => '#f39c12',
                                        'Allgemein' => '#95a5a6'
                                    ];
                                    $color = $columnColors[$baseColor] ?? '#95a5a6';
                                @endphp
                                
                                @if($columnName === 'Allgemein')
                                    <th class="column-header" style="color: {{ $color }};">
                                        <img src="{{ asset('flow/hot.png') }}" alt="HOT" class="header-logo">
                                    </th>
                                @elseif($columnName === 'Allgemein-2')
                                    <th class="column-header merged" colspan="2" style="color: {{ $color }};">
                                        <img src="{{ asset('flow/fll_explore_h.png') }}" alt="Explore" class="header-logo">
                                    </th>
                                @elseif($columnName === 'Allgemein-3')
                                    <th class="column-header merged" colspan="2" style="color: {{ $color }};">
                                        <img src="{{ asset('flow/fll_challenge_h.png') }}" alt="Challenge" class="header-logo">
                                    </th>
                                @elseif($columnName === 'Explore')
                                    @if(!in_array('Allgemein-2', $columnNames))
                                        <th class="column-header" style="color: {{ $color }};">
                                            <img src="{{ asset('flow/fll_explore_h.png') }}" alt="Explore" class="header-logo">
                                        </th>
                                    @endif
                                @elseif($columnName === 'Challenge')
                                    @if(!in_array('Allgemein-3', $columnNames))
                                        <th class="column-header" style="color: {{ $color }};">
                                            <img src="{{ asset('flow/fll_challenge_h.png') }}" alt="Challenge" class="header-logo">
                                        </th>
                                    @endif
                                @elseif($columnName === 'Robot-Game')
                                    <th class="column-header" style="color: {{ $color }};">
                                        {{ $displayName }}
                                    </th>
                                @elseif($columnName === 'Live-Challenge')
                                    <th class="column-header" style="color: {{ $color }};">
                                        {{ $displayName }}
                                    </th>
                                @endif
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($timeSlots as $slotTime)
                            <tr class="time-row">
                                <td class="time-cell">
                                    {{ $slotTime->format('H:i') }}
                                </td>
                                
                                @foreach($columnNames as $columnName)
                                    @php
                                        $hasActivity = false;
                                        $activityText = '';
                                        $rowspan = 1;
                                        
                                        // Check if there's an activity in this column at this time
                                        foreach($dayData['events'] as $event) {
                                            $eventColumn = $event['group_overview_plan_column'] ?? 'Allgemein';
                                            
                                            // Map event column to display column
                                            $matchesColumn = false;
                                            if ($columnName === 'Allgemein' && ($eventColumn === 'Allgemein' || $eventColumn === null)) {
                                                $matchesColumn = true;
                                            } elseif ($columnName === 'Allgemein-2' && $eventColumn === 'Allgemein' && $event['group_first_program_id'] == 2) {
                                                $matchesColumn = true;
                                            } elseif ($columnName === 'Allgemein-3' && $eventColumn === 'Allgemein' && $event['group_first_program_id'] == 3) {
                                                $matchesColumn = true;
                                            } elseif ($eventColumn === $columnName) {
                                                $matchesColumn = true;
                                            }
                                            
                                            if ($matchesColumn) {
                                                
                                                $startTime = $event['earliest_start'];
                                                $endTime = $event['latest_end'];
                                                
                                                // Check if this time slot falls within the activity
                                                if ($slotTime->gte($startTime) && $slotTime->lt($endTime)) {
                                                    $hasActivity = true;
                                                    
                                                    // Calculate duration in 10-minute slots
                                                    $durationSlots = ceil($startTime->diffInMinutes($endTime) / 10);
                                                    $rowspan = max(1, $durationSlots);
                                                    
                                                    // Format activity text
                                                    $activityText = $event['group_name'];
                                                    if ($event['group_description']) {
                                                        $activityText .= "\n" . $event['group_description'];
                                                    }
                                                    
                                                    // Add time range
                                                    $activityText .= "\n" . $startTime->format('H:i') . ' - ' . $endTime->format('H:i');
                                                    
                                                    break;
                                                }
                                            }
                                        }
                                    @endphp
                                    
                                    @if($hasActivity)
                                        <td class="activity-cell" rowspan="{{ $rowspan }}" style="background-color: white;">
                                            {{ $activityText }}
                                        </td>
                                    @else
                                        <td class="empty-cell"></td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>

<style>
.event-overview {
    font-family: sans-serif;
    line-height: 1.6;
    color: #333;
}

.day-section {
    margin-bottom: 30px;
    page-break-inside: avoid;
}

.day-header {
    background-color: #34495e;
    color: white;
    padding: 8px 12px;
    margin: 0 0 10px 0;
    font-size: 16px;
    border-radius: 3px;
}

.table-container {
    overflow-x: auto;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.overview-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 12px;
}

.overview-table th {
    background-color: #f8f9fa;
    padding: 8px 4px;
    border: 1px solid #ddd;
    font-weight: bold;
    text-align: center;
}

.time-column {
    width: 10%;
    background-color: #f8f9fa;
}

.column-header {
    background-color: white;
    font-weight: bold;
}

.column-header.merged {
    /* Merged header styling */
}

.header-logo {
    height: 20px;
    width: auto;
    max-width: 100%;
}

.overview-table td {
    padding: 4px;
    border: 1px solid #ddd;
    vertical-align: top;
    white-space: pre-line;
}

.time-cell {
    background-color: #f8f9fa;
    font-weight: bold;
    text-align: center;
}

.activity-cell {
    background-color: white;
    font-weight: normal;
}

.empty-cell {
    background-color: #f9f9f9;
}

.time-row:hover {
    background-color: #f5f5f5;
}

.activity-cell:hover {
    background-color: #e8f4fd;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .overview-table {
        font-size: 10px;
    }
    
    .overview-table th,
    .overview-table td {
        padding: 2px;
    }
    
    .header-logo {
        height: 16px;
    }
}
</style>
