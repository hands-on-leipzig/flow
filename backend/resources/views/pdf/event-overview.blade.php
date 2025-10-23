@php
$contentHtml = '
<div style="font-family: sans-serif; line-height: 1.6; color: #333;">
    
    <h1 style="text-align: center; margin-bottom: 30px; color: #2c3e50; font-size: 24px;">
        Übersichtsplan
    </h1>
    
    <p style="text-align: center; margin-bottom: 40px; color: #666; font-size: 14px;">
        Alle Aktivitäten auf einen Blick - Chronologische Übersicht
    </p>';

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
        $contentHtml .= '
            <div style="border-left: 4px solid #3498db; padding: 15px 20px; margin-bottom: 15px; background-color: #f8f9fa; border-radius: 0 5px 5px 0;">
                
                <!-- Event Title -->
                <h3 style="margin: 0 0 8px 0; color: #2c3e50; font-size: 16px; font-weight: bold;">
                    ' . htmlspecialchars($event['group_name']) . '
                </h3>';
        
        if($event['group_description']) {
            $contentHtml .= '
                <!-- Event Description -->
                <p style="margin: 0 0 10px 0; color: #555; font-size: 13px; font-style: italic;">
                    ' . htmlspecialchars($event['group_description']) . '
                </p>';
        }
        
        $contentHtml .= '
                <!-- Time Information -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                    <div style="flex: 1;">
                        <span style="font-weight: bold; color: #27ae60;">Start:</span>
                        <span style="margin-left: 5px; font-family: monospace; background-color: #e8f5e8; padding: 2px 6px; border-radius: 3px;">
                            ' . $event['earliest_start']->format('H:i') . '
                        </span>
                    </div>
                    
                    <div style="flex: 1; text-align: center;">
                        <span style="font-weight: bold; color: #e74c3c;">Ende:</span>
                        <span style="margin-left: 5px; font-family: monospace; background-color: #fdeaea; padding: 2px 6px; border-radius: 3px;">
                            ' . $event['latest_end']->format('H:i') . '
                        </span>
                    </div>
                    
                    <div style="flex: 1; text-align: right;">
                        <span style="font-weight: bold; color: #8e44ad;">Dauer:</span>
                        <span style="margin-left: 5px; font-family: monospace; background-color: #f3e5f5; padding: 2px 6px; border-radius: 3px;">
                            ' . floor($event['duration_minutes'] / 60) . 'h ' . ($event['duration_minutes'] % 60) . 'min
                        </span>
                    </div>
                </div>
                
                <!-- Activity Count -->
                <div style="margin-top: 8px; font-size: 12px; color: #7f8c8d;">
                    ' . $event['activity_count'] . ' ' . ($event['activity_count'] == 1 ? 'Aktivität' : 'Aktivitäten') . '
                </div>
            </div>';
    }
    
    $contentHtml .= '
        </div>
    </div>';
}

$totalEvents = collect($eventsByDay)->sum(function($day) { return count($day['events']); });

$contentHtml .= '
    <!-- Summary -->
    <div style="margin-top: 40px; padding: 20px; background-color: #ecf0f1; border-radius: 5px; border-left: 4px solid #95a5a6;">
        <h3 style="margin: 0 0 10px 0; color: #2c3e50;">Zusammenfassung</h3>
        <p style="margin: 0; color: #555; font-size: 14px;">
            Gesamt: ' . count($eventsByDay) . ' ' . (count($eventsByDay) == 1 ? 'Tag' : 'Tage') . ' mit 
            ' . $totalEvents . ' 
            ' . ($totalEvents == 1 ? 'Aktivitätsgruppe' : 'Aktivitätsgruppen') . '
        </p>
    </div>

</div>';
@endphp

@include('pdf.layout', ['title' => 'Übersichtsplan - Alle Aktivitäten auf einen Blick'])
