@php
$contentHtml = '
<div style="font-family: sans-serif; line-height: 1.6; color: #333;">
    
    <h1 style="text-align: center; margin-bottom: 30px; color: #2c3e50; font-size: 24px;">
        Übersichtsplan
    </h1>';

// Calculate global time range for all days
$globalEarliestStart = null;
$globalLatestEnd = null;
foreach($eventsByDay as $dayKey => $dayData) {
    $allEvents = collect($dayData['events']);
    $earliestStart = $allEvents->min('earliest_start');
    $latestEnd = $allEvents->max('latest_end');
    
    if ($globalEarliestStart === null || $earliestStart->lt($globalEarliestStart)) {
        $globalEarliestStart = $earliestStart;
    }
    if ($globalLatestEnd === null || $latestEnd->gt($globalLatestEnd)) {
        $globalLatestEnd = $latestEnd;
    }
}

// Create 5-minute grid from global earliest start to latest end
$startTime = $globalEarliestStart->copy()->startOfHour();
$endTime = $globalLatestEnd->copy()->addHour()->startOfHour();

// Generate all 5-minute slots
$timeSlots = [];
$current = $startTime->copy();
while ($current->lt($endTime)) {
    $timeSlots[] = $current->copy();
    $current->addMinutes(5);
}

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
                    <th style="width: 15%; background-color: #f8f9fa; padding: 8px; border: 1px solid #ddd; font-size: 12px; font-weight: bold;">Zeit</th>
                    <th style="width: 28.33%; background-color: #27ae60; color: white; padding: 8px; border: 1px solid #ddd; font-size: 12px; font-weight: bold;">Explore</th>
                    <th style="width: 28.33%; background-color: #e74c3c; color: white; padding: 8px; border: 1px solid #ddd; font-size: 12px; font-weight: bold;">Challenge</th>
                    <th style="width: 28.33%; background-color: #95a5a6; color: white; padding: 8px; border: 1px solid #ddd; font-size: 12px; font-weight: bold;">Allgemein</th>
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
                    <td rowspan="6" style="padding: 2px; border: 1px solid #ddd; font-size: 10px; font-weight: bold; background-color: #f8f9fa; text-align: center; vertical-align: middle;">' . $timeLabel . '</td>';
        }
        
        // Find events starting at this time slot for each column
        $exploreEvents = collect($eventsWithRowspan)->filter(function($item) use ($slotTime) {
            return isset($item['event']['group_first_program_id']) && 
                   $item['event']['group_first_program_id'] == 2 &&
                   $item['start_slot'] == $slotTime;
        });
        
        $challengeEvents = collect($eventsWithRowspan)->filter(function($item) use ($slotTime) {
            return isset($item['event']['group_first_program_id']) && 
                   $item['event']['group_first_program_id'] == 3 &&
                   $item['start_slot'] == $slotTime;
        });
        
        $generalEvents = collect($eventsWithRowspan)->filter(function($item) use ($slotTime) {
            return (!isset($item['event']['group_first_program_id']) || 
                   ($item['event']['group_first_program_id'] != 2 && $item['event']['group_first_program_id'] != 3)) &&
                   $item['start_slot'] == $slotTime;
        });
        
        // Explore column
        if ($exploreEvents->count() > 0) {
            $event = $exploreEvents->first()['event'];
            $rowspan = $exploreEvents->first()['rowspan'];
            $contentHtml .= '
                    <td rowspan="' . $rowspan . '" style="background-color: #d5f4e6; border-left: 3px solid #27ae60; padding: 2px 4px; font-size: 9px; font-weight: bold; vertical-align: middle;">
                        ' . htmlspecialchars($event['group_name']) . '
                    </td>';
        } else {
            $contentHtml .= '
                    <td style="padding: 0; border: 1px solid #ddd; height: 8px;"></td>';
        }
        
        // Challenge column
        if ($challengeEvents->count() > 0) {
            $event = $challengeEvents->first()['event'];
            $rowspan = $challengeEvents->first()['rowspan'];
            $contentHtml .= '
                    <td rowspan="' . $rowspan . '" style="background-color: #fdeaea; border-left: 3px solid #e74c3c; padding: 2px 4px; font-size: 9px; font-weight: bold; vertical-align: middle;">
                        ' . htmlspecialchars($event['group_name']) . '
                    </td>';
        } else {
            $contentHtml .= '
                    <td style="padding: 0; border: 1px solid #ddd; height: 8px;"></td>';
        }
        
        // General column
        if ($generalEvents->count() > 0) {
            $event = $generalEvents->first()['event'];
            $rowspan = $generalEvents->first()['rowspan'];
            $contentHtml .= '
                    <td rowspan="' . $rowspan . '" style="background-color: #f5f5f5; border-left: 3px solid #95a5a6; padding: 2px 4px; font-size: 9px; font-weight: bold; vertical-align: middle;">
                        ' . htmlspecialchars($event['group_name']) . '
                    </td>';
        } else {
            $contentHtml .= '
                    <td style="padding: 0; border: 1px solid #ddd; height: 8px;"></td>';
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
