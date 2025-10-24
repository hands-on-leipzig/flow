@php
$contentHtml = '
<div style="font-family: sans-serif; line-height: 1.6; color: #333;">
    
    <h1 style="text-align: center; margin-bottom: 30px; color: #2c3e50; font-size: 24px;">
        Übersichtsplan
    </h1>';

foreach($eventsByDay as $dayKey => $dayData) {
    $contentHtml .= '
    <div style="margin-bottom: 30px;">
        <!-- Day Header -->
        <h2 style="background-color: #34495e; color: white; padding: 10px 15px; margin: 0 0 15px 0; font-size: 18px; border-radius: 5px;">
            ' . $dayData['date']->locale('de')->isoFormat('dddd, DD.MM.YYYY') . '
        </h2>

        <!-- Events for this day -->
        <div style="margin-left: 20px;">';
    
    foreach($dayData['events'] as $event) {
        // Determine program icon
        $programIcon = '';
        if (isset($event['group_first_program_id']) && $event['group_first_program_id'] !== null) {
            if ($event['group_first_program_id'] == 2) {
                $programIcon = '<img src="file://' . public_path('flow/fll_explore_v.png') . '" alt="Explore" style="width: 20px; height: 20px; margin-right: 8px; vertical-align: middle;" />';
            } elseif ($event['group_first_program_id'] == 3) {
                $programIcon = '<img src="file://' . public_path('flow/fll_challenge_v.png') . '" alt="Challenge" style="width: 20px; height: 20px; margin-right: 8px; vertical-align: middle;" />';
            } else {
                $programIcon = '<img src="file://' . public_path('flow/first_v.png') . '" alt="FIRST" style="width: 20px; height: 20px; margin-right: 8px; vertical-align: middle;" />';
            }
        } else {
            // Show both icons when no program ID is available (Explore first)
            $programIcon = '<img src="file://' . public_path('flow/fll_explore_v.png') . '" alt="Explore" style="width: 20px; height: 20px; margin-right: 4px; vertical-align: middle;" />';
            $programIcon .= '<img src="file://' . public_path('flow/fll_challenge_v.png') . '" alt="Challenge" style="width: 20px; height: 20px; margin-right: 8px; vertical-align: middle;" />';
        }
        
        $contentHtml .= '
            <div style="border-left: 4px solid #3498db; padding: 15px 20px; margin-bottom: 15px; background-color: #f8f9fa; border-radius: 0 5px 5px 0;">
                
                <!-- Time and Title -->
                <div style="margin-bottom: 8px;">
                    <div style="font-family: monospace; font-size: 14px; color: #666; margin-bottom: 4px;">
                        ' . $event['earliest_start']->format('H:i') . ' - ' . $event['latest_end']->format('H:i') . '
                    </div>
                    <h3 style="margin: 0; color: #2c3e50; font-size: 16px; font-weight: bold; display: flex; align-items: center;">
                        ' . $programIcon . htmlspecialchars($event['group_name']) . '
                    </h3>';
        
        if($event['group_description']) {
            $contentHtml .= '
                    <p style="margin: 4px 0 0 0; color: #555; font-size: 13px; font-style: italic;">
                        ' . htmlspecialchars($event['group_description']) . '
                    </p>';
        }
        
        $contentHtml .= '
                </div>
            </div>';
    }
    
    $contentHtml .= '
        </div>
    </div>';
}

$contentHtml .= '
</div>';
@endphp

@include('pdf.layout_portrait', ['title' => 'Übersichtsplan - Alle Aktivitäten auf einen Blick'])
