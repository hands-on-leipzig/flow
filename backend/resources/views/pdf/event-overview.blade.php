@php
$contentHtml = '
<div style="font-family: sans-serif; line-height: 1.6; color: #333;">
    
    <h1 style="text-align: center; margin-bottom: 30px; color: #2c3e50; font-size: 24px;">
        Übersichtsplan
    </h1>';

foreach($eventsByDay as $dayKey => $dayData) {
    // Calculate time range for the day
    $allEvents = collect($dayData['events']);
    $earliestStart = $allEvents->min('earliest_start');
    $latestEnd = $allEvents->max('latest_end');
    
    // Create 5-minute grid from earliest start to latest end
    $startTime = $earliestStart->copy()->startOfHour();
    $endTime = $latestEnd->copy()->addHour()->startOfHour();
    
    // Generate all 5-minute slots
    $timeSlots = [];
    $current = $startTime->copy();
    while ($current->lt($endTime)) {
        $timeSlots[] = $current->copy();
        $current->addMinutes(5);
    }
    
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
    
    // Track which events have been placed to avoid duplicates
    $placedEvents = ['explore' => [], 'challenge' => [], 'general' => []];
    
    // Generate 5-minute rows
    foreach ($timeSlots as $index => $slot) {
        $isFullHour = $slot->minute == 0;
        $timeLabel = $isFullHour ? $slot->format('H:i') : '';
        
        $contentHtml .= '
                <tr>
                    <td style="padding: 2px; border: 1px solid #ddd; font-size: 10px; font-weight: bold; background-color: #f8f9fa; text-align: center;">' . $timeLabel . '</td>
                    <td style="padding: 0; border: 1px solid #ddd; vertical-align: top; height: 8px;">';
        
        // Find Explore events starting at this time slot
        $exploreEvents = $allEvents->filter(function($event) use ($slot, $placedEvents) {
            return isset($event['group_first_program_id']) && 
                   $event['group_first_program_id'] == 2 &&
                   $event['earliest_start']->format('H:i') == $slot->format('H:i') &&
                   !in_array($event['group_id'], $placedEvents['explore']);
        });
        
        foreach($exploreEvents as $event) {
            // Calculate rowspan (duration in 5-minute slots)
            $duration = $event['earliest_start']->diffInMinutes($event['latest_end']);
            $rowspan = max(1, ceil($duration / 5));
            
            $contentHtml .= '
                        <div style="background-color: #d5f4e6; border-left: 3px solid #27ae60; padding: 2px 4px; font-size: 9px; font-weight: bold; height: ' . ($rowspan * 8 - 4) . 'px; display: flex; align-items: center;">
                            ' . htmlspecialchars($event['group_name']) . '
                        </div>';
            
            $placedEvents['explore'][] = $event['group_id'];
        }
        
        $contentHtml .= '
                    </td>
                    <td style="padding: 0; border: 1px solid #ddd; vertical-align: top; height: 8px;">';
        
        // Find Challenge events starting at this time slot
        $challengeEvents = $allEvents->filter(function($event) use ($slot, $placedEvents) {
            return isset($event['group_first_program_id']) && 
                   $event['group_first_program_id'] == 3 &&
                   $event['earliest_start']->format('H:i') == $slot->format('H:i') &&
                   !in_array($event['group_id'], $placedEvents['challenge']);
        });
        
        foreach($challengeEvents as $event) {
            // Calculate rowspan (duration in 5-minute slots)
            $duration = $event['earliest_start']->diffInMinutes($event['latest_end']);
            $rowspan = max(1, ceil($duration / 5));
            
            $contentHtml .= '
                        <div style="background-color: #fdeaea; border-left: 3px solid #e74c3c; padding: 2px 4px; font-size: 9px; font-weight: bold; height: ' . ($rowspan * 8 - 4) . 'px; display: flex; align-items: center;">
                            ' . htmlspecialchars($event['group_name']) . '
                        </div>';
            
            $placedEvents['challenge'][] = $event['group_id'];
        }
        
        $contentHtml .= '
                    </td>
                    <td style="padding: 0; border: 1px solid #ddd; vertical-align: top; height: 8px;">';
        
        // Find General events starting at this time slot
        $generalEvents = $allEvents->filter(function($event) use ($slot, $placedEvents) {
            return (!isset($event['group_first_program_id']) || 
                   ($event['group_first_program_id'] != 2 && $event['group_first_program_id'] != 3)) &&
                   $event['earliest_start']->format('H:i') == $slot->format('H:i') &&
                   !in_array($event['group_id'], $placedEvents['general']);
        });
        
        foreach($generalEvents as $event) {
            // Calculate rowspan (duration in 5-minute slots)
            $duration = $event['earliest_start']->diffInMinutes($event['latest_end']);
            $rowspan = max(1, ceil($duration / 5));
            
            $contentHtml .= '
                        <div style="background-color: #f5f5f5; border-left: 3px solid #95a5a6; padding: 2px 4px; font-size: 9px; font-weight: bold; height: ' . ($rowspan * 8 - 4) . 'px; display: flex; align-items: center;">
                            ' . htmlspecialchars($event['group_name']) . '
                        </div>';
            
            $placedEvents['general'][] = $event['group_id'];
        }
        
        $contentHtml .= '
                    </td>
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
