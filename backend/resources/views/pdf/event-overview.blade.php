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


// Generate all 10-minute slots
$timeSlots = [];
$current = $startTime->copy();
while ($current->lt($endTime)) {
    $timeSlots[] = $current->copy();
    $current->addMinutes(10);
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
                    <th style="width: 10%; background-color: #f8f9fa; padding: 4px; border: 1px solid #ddd; font-size: 9px; font-weight: bold;">Zeit</th>';
        
        // Dynamic column headers with merged cells
        $columnColors = [
            'Explore' => '#27ae60',
            'Challenge' => '#e74c3c', 
            'Live-Challenge' => '#8e44ad',
            'Robot-Game' => '#f39c12',
            'Allgemein' => '#95a5a6'
        ];
        
        // Calculate column widths dynamically
        // Zeit gets 10%, remaining 90% is divided among actual HTML columns
        $actualHtmlColumns = 0;
        foreach($columnNames as $columnName) {
            if ($columnName === 'Allgemein') {
                $actualHtmlColumns += 1; // Single column
            } elseif ($columnName === 'Allgemein-2') {
                $actualHtmlColumns += 2; // Merged cell (Allgemein-2 + Explore)
            } elseif ($columnName === 'Allgemein-3') {
                $actualHtmlColumns += 2; // Merged cell (Allgemein-3 + Challenge)
            } elseif ($columnName === 'Robot-Game') {
                $actualHtmlColumns += 1; // Single column
            } elseif ($columnName === 'Live-Challenge') {
                $actualHtmlColumns += 1; // Single column
            }
            // Skip Explore and Challenge as they are merged
        }
        
        $remainingWidth = 90;
        $columnWidth = $remainingWidth / $actualHtmlColumns;
        
        
        // Check if Allgemein-2 and Allgemein-3 exist to determine merge behavior
        $hasAllgemein2 = in_array('Allgemein-2', $columnNames);
        $hasAllgemein3 = in_array('Allgemein-3', $columnNames);
        
        // Generate headers with conditional merging
        foreach($columnNames as $columnName) {
            $displayName = $columnName;
            $baseColor = $displayName;
            if (strpos($displayName, 'Allgemein-') === 0) {
                $baseColor = 'Allgemein';
            }
            $color = $columnColors[$baseColor] ?? '#95a5a6';
            
            if ($columnName === 'Allgemein') {
                // Logo only
                $headerContent = '<img src="file://' . public_path('flow/hot.png') . '" style="height: 20px; width: auto;">';
                $contentHtml .= '
                    <th style="width: ' . $columnWidth . '%; background-color: white; color: ' . $color . '; padding: 4px; border: 1px solid #ddd; font-size: 9px; font-weight: bold; text-align: center;">' . $headerContent . '</th>';
            } elseif ($columnName === 'Allgemein-2') {
                if ($hasAllgemein2) {
                    // Merged cell for Allgemein-2 + Explore
                    $headerContent = '<img src="file://' . public_path('flow/fll_explore_h.png') . '" style="height: 20px; width: auto;">';
                    $contentHtml .= '
                        <th colspan="2" style="width: ' . $columnWidth . '%; background-color: white; color: ' . $color . '; padding: 4px; border: 1px solid #ddd; font-size: 9px; font-weight: bold; text-align: center;">' . $headerContent . '</th>';
                }
            } elseif ($columnName === 'Allgemein-3') {
                if ($hasAllgemein3) {
                    // Merged cell for Allgemein-3 + Challenge
                    $headerContent = '<img src="file://' . public_path('flow/fll_challenge_h.png') . '" style="height: 20px; width: auto;">';
                    $contentHtml .= '
                        <th colspan="2" style="width: ' . $columnWidth . '%; background-color: white; color: ' . $color . '; padding: 4px; border: 1px solid #ddd; font-size: 9px; font-weight: bold; text-align: center;">' . $headerContent . '</th>';
                }
            } elseif ($columnName === 'Explore') {
                // Explore gets icon only if Allgemein-2 doesn't exist
                if (!$hasAllgemein2) {
                    $headerContent = '<img src="file://' . public_path('flow/fll_explore_h.png') . '" style="height: 20px; width: auto;">';
                    $contentHtml .= '
                        <th style="width: ' . $columnWidth . '%; background-color: white; color: ' . $color . '; padding: 4px; border: 1px solid #ddd; font-size: 9px; font-weight: bold; text-align: center;">' . $headerContent . '</th>';
                }
            } elseif ($columnName === 'Challenge') {
                // Challenge gets icon only if Allgemein-3 doesn't exist
                if (!$hasAllgemein3) {
                    $headerContent = '<img src="file://' . public_path('flow/fll_challenge_h.png') . '" style="height: 20px; width: auto;">';
                    $contentHtml .= '
                        <th style="width: ' . $columnWidth . '%; background-color: white; color: ' . $color . '; padding: 4px; border: 1px solid #ddd; font-size: 9px; font-weight: bold; text-align: center;">' . $headerContent . '</th>';
                }
            } elseif ($columnName === 'Robot-Game') {
                // Keep as is for Robot-Game
                $headerContent = htmlspecialchars($displayName);
                $contentHtml .= '
                    <th style="width: ' . $columnWidth . '%; background-color: white; color: ' . $color . '; padding: 4px; border: 1px solid #ddd; font-size: 9px; font-weight: bold; text-align: center;">' . $headerContent . '</th>';
            } elseif ($columnName === 'Live-Challenge') {
                // Keep as is for Live-Challenge
                $headerContent = htmlspecialchars($displayName);
                $contentHtml .= '
                    <th style="width: ' . $columnWidth . '%; background-color: white; color: ' . $color . '; padding: 4px; border: 1px solid #ddd; font-size: 9px; font-weight: bold; text-align: center;">' . $headerContent . '</th>';
            }
        }
        
        $contentHtml .= '
                </tr>
            </thead>
            <tbody>';
    
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
    
    // Generate 10-minute rows
    foreach ($timeSlots as $index => $slot) {
        $isFullHour = $slot->minute == 0;
        $timeLabel = $isFullHour ? $slot->format('H:i') : '';
        $slotTime = $slot->format('H:i');
        
        $contentHtml .= '
                <tr>';
        
        // Time column with rowspan for full hours (6 × 10min = 60min)
        if ($isFullHour) {
            $contentHtml .= '
                    <td rowspan="6" style="padding: 1px; border: 1px solid #ddd; font-size: 8px; font-weight: bold; background-color: #f8f9fa; text-align: center; vertical-align: middle;">' . $timeLabel . '</td>';
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
                
                
                // Only check events that match this column
                if ($eventColumn !== $columnName) {
                    return false;
                }
                
                // Check time slot matching
                $matches = $item['start_slot'] == $slotTime;
                
                
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
                    'Live-Challenge' => ['bg' => '#f4e6f7', 'border' => '#8e44ad'],
                    'Robot-Game' => ['bg' => '#fef5e7', 'border' => '#f39c12'],
                    'Allgemein' => ['bg' => '#f5f5f5', 'border' => '#95a5a6']
                ];
                
                $colors = $columnColors[$eventColumn] ?? ['bg' => '#f5f5f5', 'border' => '#95a5a6'];
                
                $startTime = $event['earliest_start']->format('H:i');
                $endTime = $event['latest_end']->format('H:i');
                
                $contentHtml .= '
                    <td rowspan="' . $rowspan . '" style="background-color: ' . $colors['bg'] . '; border-left: 3px solid ' . $colors['border'] . '; padding: 1px 2px; font-size: 8px; font-weight: bold; vertical-align: middle;">
                        ' . htmlspecialchars($event['group_name']) . '<br>
                        <span style="font-weight: normal; font-size: 7px;">' . $startTime . ' - ' . $endTime . '</span>
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
