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
    
    // Create hour grid from earliest start to latest end
    $startHour = $earliestStart->hour;
    $endHour = $latestEnd->hour;
    if ($latestEnd->minute > 0) $endHour++; // Round up if there are minutes
    
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
    
    // Generate hour rows
    for ($hour = $startHour; $hour <= $endHour; $hour++) {
        $timeLabel = sprintf('%02d:00', $hour);
        $contentHtml .= '
                <tr>
                    <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px; font-weight: bold; background-color: #f8f9fa;">' . $timeLabel . '</td>
                    <td style="padding: 0; border: 1px solid #ddd; vertical-align: top;">';
        
        // Find Explore events for this hour
        $exploreEvents = $allEvents->filter(function($event) use ($hour) {
            return isset($event['group_first_program_id']) && 
                   $event['group_first_program_id'] == 2 &&
                   $event['earliest_start']->hour <= $hour && 
                   $event['latest_end']->hour >= $hour;
        });
        
        foreach($exploreEvents as $event) {
            $contentHtml .= '
                        <div style="background-color: #d5f4e6; border-left: 3px solid #27ae60; padding: 4px 6px; margin: 1px; font-size: 10px; font-weight: bold;">
                            ' . htmlspecialchars($event['group_name']) . '
                        </div>';
        }
        
        $contentHtml .= '
                    </td>
                    <td style="padding: 0; border: 1px solid #ddd; vertical-align: top;">';
        
        // Find Challenge events for this hour
        $challengeEvents = $allEvents->filter(function($event) use ($hour) {
            return isset($event['group_first_program_id']) && 
                   $event['group_first_program_id'] == 3 &&
                   $event['earliest_start']->hour <= $hour && 
                   $event['latest_end']->hour >= $hour;
        });
        
        foreach($challengeEvents as $event) {
            $contentHtml .= '
                        <div style="background-color: #fdeaea; border-left: 3px solid #e74c3c; padding: 4px 6px; margin: 1px; font-size: 10px; font-weight: bold;">
                            ' . htmlspecialchars($event['group_name']) . '
                        </div>';
        }
        
        $contentHtml .= '
                    </td>
                    <td style="padding: 0; border: 1px solid #ddd; vertical-align: top;">';
        
        // Find General events for this hour
        $generalEvents = $allEvents->filter(function($event) use ($hour) {
            return (!isset($event['group_first_program_id']) || 
                   ($event['group_first_program_id'] != 2 && $event['group_first_program_id'] != 3)) &&
                   $event['earliest_start']->hour <= $hour && 
                   $event['latest_end']->hour >= $hour;
        });
        
        foreach($generalEvents as $event) {
            $contentHtml .= '
                        <div style="background-color: #f5f5f5; border-left: 3px solid #95a5a6; padding: 4px 6px; margin: 1px; font-size: 10px; font-weight: bold;">
                            ' . htmlspecialchars($event['group_name']) . '
                        </div>';
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
