@php
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

// Create 10-minute grid from global earliest hour to latest hour
$startTime = \Carbon\Carbon::createFromTime($globalEarliestHour, 0, 0);
$endTime = \Carbon\Carbon::createFromTime($globalLatestHour, 59, 59); // End of the last hour

// Generate all 10-minute slots
$timeSlots = [];
$current = $startTime->copy();
while ($current->lt($endTime)) {
    $timeSlots[] = $current->copy();
    $current->addMinutes(10);
}

// Check if this is a multi-day event
$isMultiDay = count($eventsByDay) > 1;

// Determine styling based on context
$isPdf = isset($isPdf) && $isPdf;
$containerClass = $isPdf ? '' : 'event-overview-container';
$tableClass = $isPdf ? '' : 'overview-table';
$daySectionClass = $isPdf ? '' : 'day-section';
$dayHeaderClass = $isPdf ? '' : 'day-header';
@endphp

<div class="{{ $containerClass }}">
    @foreach($eventsByDay as $dayKey => $dayData)
        <div class="{{ $daySectionClass }}">
            @if($isMultiDay)
                <!-- Day Header -->
                <div class="{{ $dayHeaderClass }}">
                    {{ $dayData['date']->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}
                </div>
            @endif

            <!-- Time Grid Layout -->
            <div class="table-container">
                <table class="{{ $tableClass }}">
                    <thead>
                        <tr>
                            <th class="time-column">Zeit</th>
                            @foreach($columnNames as $columnName)
                                <th class="column-header">{{ $columnName }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $allEvents = collect($dayData['events']);
                            
                            // Pre-calculate all events with their rowspan
                            $eventsWithRowspan = [];
                            foreach($allEvents as $event) {
                                $duration = $event['earliest_start']->diffInMinutes($event['latest_end']);
                                $rowspan = max(1, ceil($duration / 10));
                                $eventsWithRowspan[] = [
                                    'event' => $event,
                                    'rowspan' => $rowspan,
                                    'start_slot' => $event['earliest_start']->format('H:i')
                                ];
                            }
                            
                            // Track occupied cells (column => end time slot index)
                            $occupiedCells = [];
                        @endphp
                        
                        @foreach($timeSlots as $index => $slot)
                            @php
                                $isFullHour = $slot->minute == 0;
                                $timeLabel = $isFullHour ? $slot->format('H:i') : '';
                                $slotTime = $slot->format('H:i');
                            @endphp
                            
                            <tr class="time-row">
                                @if($isFullHour)
                                    <td rowspan="6" class="time-cell" style="{{ !$isPdf ? 'height: 24px;' : '' }}">{{ $timeLabel }}</td>
                                @endif
                                
                                @foreach($columnNames as $columnName)
                                    @php
                                        // Skip if this cell is occupied by a rowspan from a previous row
                                        if (isset($occupiedCells[$columnName]) && $occupiedCells[$columnName] > $index) {
                                            continue;
                                        }
                                        
                                        // Find events for this column - use pre-assigned column from controller
                                        $columnEvents = collect($eventsWithRowspan)->filter(function($item) use ($slotTime, $columnName) {
                                            $eventAssignedColumn = $item['event']['assigned_column'] ?? 'Allgemein';
                                            
                                            // Only check events that match this column
                                            if ($eventAssignedColumn !== $columnName) {
                                                return false;
                                            }
                                            
                                            // Check time slot matching - handle 5-minute grid activities on 10-minute template grid
                                            $slotTimeStr = is_object($slotTime) ? $slotTime->format('H:i') : $slotTime;
                                            $startSlotStr = is_object($item['start_slot']) ? $item['start_slot']->format('H:i') : $item['start_slot'];
                                            
                                            // Check if activity starts in current 10-minute slot OR in the next 5-minute slot
                                            $slotTimeObj = is_object($slotTime) ? $slotTime : \Carbon\Carbon::createFromFormat('H:i', $slotTime);
                                            $nextSlotTime = $slotTimeObj->copy()->addMinutes(5);
                                            $nextSlotTimeStr = $nextSlotTime->format('H:i');
                                            
                                            $matches = ($startSlotStr == $slotTimeStr) || ($startSlotStr == $nextSlotTimeStr);
                                            
                                            return $matches;
                                        });
                                        
                                        if ($columnEvents->count() > 0) {
                                            $event = $columnEvents->first()['event'];
                                            $rowspan = $columnEvents->first()['rowspan'];
                                            
                                            // Mark this column as occupied for the next N-1 rows
                                            $occupiedCells[$columnName] = $index + $rowspan;
                                            
                                            // Get color based on the event's assigned column
                                            $eventAssignedColumn = $event['assigned_column'] ?? 'Allgemein';
                                            $baseColor = $eventAssignedColumn;
                                            if (strpos($baseColor, 'Allgemein-') === 0) {
                                                $baseColor = 'Allgemein';
                                            }
                                            
                                            $columnColors = [
                                                'Explore' => ['bg' => '#d5f4e6', 'border' => '#27ae60'],
                                                'Challenge' => ['bg' => '#fdeaea', 'border' => '#e74c3c'],
                                                'Live-Challenge' => ['bg' => '#f4e6f7', 'border' => '#8e44ad'],
                                                'Robot-Game' => ['bg' => '#fef5e7', 'border' => '#f39c12'],
                                                'Allgemein' => ['bg' => '#f5f5f5', 'border' => '#95a5a6']
                                            ];
                                            
                                            $colors = $columnColors[$baseColor] ?? ['bg' => '#f5f5f5', 'border' => '#95a5a6'];
                                            
                                            $startTime = $event['earliest_start']->format('H:i');
                                            $endTime = $event['latest_end']->format('H:i');
                                        }
                                    @endphp
                                    
                                    @if($columnEvents->count() > 0)
                                        <td rowspan="{{ $rowspan }}" class="activity-cell" style="background-color: {{ $colors['bg'] }}; border-left: 3px solid {{ $colors['border'] }}; {{ !$isPdf ? 'height: 24px; overflow: hidden; line-height: 1.1;' : '' }}">
                                            {{ $event['group_name'] }}{{ !$isPdf ? ' ' : '<br>' }}<span class="activity-time">{{ $startTime }} - {{ $endTime }}</span>
                                        </td>
                                    @else
                                        <td class="empty-cell" style="{{ !$isPdf ? 'height: 24px;' : '' }}"></td>
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

@if(!$isPdf)
<style>
.event-overview-container {
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

.activity-time {
    font-weight: normal;
    font-size: 10px;
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
}
</style>
@endif
