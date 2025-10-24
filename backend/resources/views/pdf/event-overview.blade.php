@php
$contentHtml = '
<div style="font-family: sans-serif; line-height: 1.6; color: #333;">
    
    <h1 style="text-align: center; margin-bottom: 30px; color: #2c3e50; font-size: 24px;">
        Übersichtsplan
    </h1>';

$isFirstDay = true;
foreach($eventsByDay as $dayKey => $dayData) {
    // Add page break before each day except the first
    $pageBreakStyle = $isFirstDay ? '' : 'page-break-before: always; page-break-inside: avoid;';
    
    $contentHtml .= '
    <div style="margin-bottom: 20px; ' . $pageBreakStyle . '">
        <!-- Day Header -->
        <h2 style="background-color: #34495e; color: white; padding: 8px 12px; margin: 0 0 10px 0; font-size: 16px; border-radius: 3px;">
            ' . $dayData['date']->locale('de')->isoFormat('dddd, DD.MM.YYYY') . '
        </h2>

        <!-- Three Column Layout -->
        <table style="width: 100%; border-collapse: collapse; table-layout: fixed; page-break-inside: avoid;">
            <tr>
                <!-- Left Column: Explore -->
                <td style="width: 33.33%; vertical-align: top; background-color: #f0f8f0; padding: 8px; border-radius: 3px;">
                    <h3 style="margin: 0 0 8px 0; color: #2c3e50; font-size: 12px; text-align: center; background-color: #27ae60; color: white; padding: 4px; border-radius: 2px;">
                        FIRST LEGO League Explore
                    </h3>
                    <div>';
    
    // Filter and sort Explore events (program ID = 2)
    $exploreEvents = collect($dayData['events'])->filter(function($event) {
        return isset($event['group_first_program_id']) && $event['group_first_program_id'] == 2;
    })->sortBy('earliest_start');
    
    foreach($exploreEvents as $event) {
        $contentHtml .= '
                    <div style="border-left: 3px solid #27ae60; padding: 6px 8px; margin-bottom: 6px; background-color: #ffffff; border-radius: 0 3px 3px 0; page-break-inside: avoid;">
                        <div style="font-family: monospace; font-size: 10px; color: #666; margin-bottom: 2px;">
                            ' . $event['earliest_start']->format('H:i') . ' - ' . $event['latest_end']->format('H:i') . '
                        </div>
                        <div style="font-size: 12px; font-weight: bold; color: #2c3e50;">
                            ' . htmlspecialchars($event['group_name']) . '
                        </div>';
        
        if($event['group_description']) {
            $contentHtml .= '
                        <div style="font-size: 9px; color: #555; font-style: italic; margin-top: 1px;">
                            ' . htmlspecialchars($event['group_description']) . '
                        </div>';
        }
        
        $contentHtml .= '
                    </div>';
    }
    
    $contentHtml .= '
                    </div>
                </td>
                
                <!-- Middle Column: Challenge -->
                <td style="width: 33.33%; vertical-align: top; background-color: #f8f0f0; padding: 8px; border-radius: 3px;">
                    <h3 style="margin: 0 0 8px 0; color: #2c3e50; font-size: 12px; text-align: center; background-color: #e74c3c; color: white; padding: 4px; border-radius: 2px;">
                        FIRST LEGO League Challenge
                    </h3>
                    <div>';
    
    // Filter and sort Challenge events (program ID = 3)
    $challengeEvents = collect($dayData['events'])->filter(function($event) {
        return isset($event['group_first_program_id']) && $event['group_first_program_id'] == 3;
    })->sortBy('earliest_start');
    
    foreach($challengeEvents as $event) {
        $contentHtml .= '
                    <div style="border-left: 3px solid #e74c3c; padding: 6px 8px; margin-bottom: 6px; background-color: #ffffff; border-radius: 0 3px 3px 0; page-break-inside: avoid;">
                        <div style="font-family: monospace; font-size: 10px; color: #666; margin-bottom: 2px;">
                            ' . $event['earliest_start']->format('H:i') . ' - ' . $event['latest_end']->format('H:i') . '
                        </div>
                        <div style="font-size: 12px; font-weight: bold; color: #2c3e50;">
                            ' . htmlspecialchars($event['group_name']) . '
                        </div>';
        
        if($event['group_description']) {
            $contentHtml .= '
                        <div style="font-size: 9px; color: #555; font-style: italic; margin-top: 1px;">
                            ' . htmlspecialchars($event['group_description']) . '
                        </div>';
        }
        
        $contentHtml .= '
                    </div>';
    }
    
    $contentHtml .= '
                    </div>
                </td>
                
                <!-- Right Column: Other/General -->
                <td style="width: 33.33%; vertical-align: top; background-color: #f5f5f5; padding: 8px; border-radius: 3px;">
                    <h3 style="margin: 0 0 8px 0; color: #2c3e50; font-size: 12px; text-align: center; background-color: #95a5a6; color: white; padding: 4px; border-radius: 2px;">
                        Allgemein
                    </h3>
                    <div>';
    
    // Filter and sort Other events (no program ID or other IDs)
    $otherEvents = collect($dayData['events'])->filter(function($event) {
        return !isset($event['group_first_program_id']) || 
               ($event['group_first_program_id'] != 2 && $event['group_first_program_id'] != 3);
    })->sortBy('earliest_start');
    
    foreach($otherEvents as $event) {
        $contentHtml .= '
                    <div style="border-left: 3px solid #95a5a6; padding: 6px 8px; margin-bottom: 6px; background-color: #ffffff; border-radius: 0 3px 3px 0; page-break-inside: avoid;">
                        <div style="font-family: monospace; font-size: 10px; color: #666; margin-bottom: 2px;">
                            ' . $event['earliest_start']->format('H:i') . ' - ' . $event['latest_end']->format('H:i') . '
                        </div>
                        <div style="font-size: 12px; font-weight: bold; color: #2c3e50;">
                            ' . htmlspecialchars($event['group_name']) . '
                        </div>';
        
        if($event['group_description']) {
            $contentHtml .= '
                        <div style="font-size: 9px; color: #555; font-style: italic; margin-top: 1px;">
                            ' . htmlspecialchars($event['group_description']) . '
                        </div>';
        }
        
        $contentHtml .= '
                    </div>';
    }
    
    $contentHtml .= '
                    </div>
                </td>
            </tr>
        </table>
    </div>';
    
    $isFirstDay = false;
}

$contentHtml .= '
</div>';
@endphp

@include('pdf.layout_portrait', ['title' => 'Übersichtsplan - Alle Aktivitäten auf einen Blick'])
