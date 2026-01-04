@php
// Round labels
$roundLabels = [
    1 => 'Vorrunde 1',
    2 => 'Vorrunde 2',
    3 => 'Vorrunde 3',
];

// Helper function to format team display
$formatTeam = function($team) {
    if (!$team) {
        return 'Freier Slot';
    }
    return $team['name'] . ' [' . $team['hot_number'] . ']';
};

// Helper function to check if team is empty slot
$isEmptySlot = function($team) {
    return $team === null;
};
@endphp

<div style="font-family: sans-serif; line-height: 1.3; color: #333;">
    @foreach([1, 2, 3] as $round)
        @php
            $matches = $roundsData[$round] ?? [];
            $roundLabel = $roundLabels[$round] ?? "Vorrunde {$round}";
        @endphp
        
        <div style="margin-bottom: 12px;">
            <!-- Round Header -->
            <div style="background-color: #f3f4f6; padding: 6px 10px; font-weight: bold; text-transform: uppercase; color: #000; border-radius: 4px; margin-bottom: 6px; font-size: 11px;">
                {{ $roundLabel }}
            </div>
            
            <!-- Match Grid - 2 columns per match using table for PDF compatibility -->
            <table style="width: 100%; border-collapse: separate; border-spacing: 0 3px;">
                @foreach($matches as $match)
                    @php
                        $team1IsEmpty = $isEmptySlot($match['team_1']);
                        $team2IsEmpty = $isEmptySlot($match['team_2']);
                        $team1Style = $team1IsEmpty 
                            ? 'background-color: #d1d5db; color: #374151;'
                            : 'background-color: #2563eb; color: #ffffff;';
                        $team2Style = $team2IsEmpty 
                            ? 'background-color: #d1d5db; color: #374151;'
                            : 'background-color: #2563eb; color: #ffffff;';
                    @endphp
                    <tr>
                        <!-- Team 1 (Left Column) -->
                        <td style="width: 50%; padding: 0; padding-right: 3px;">
                            <div style="padding: 5px 8px; border-radius: 4px; font-size: 9px; font-weight: 500; {{ $team1Style }}">
                                {{ $formatTeam($match['team_1']) }}
                            </div>
                        </td>
                        
                        <!-- Team 2 (Right Column) -->
                        <td style="width: 50%; padding: 0; padding-left: 3px;">
                            <div style="padding: 5px 8px; border-radius: 4px; font-size: 9px; font-weight: 500; {{ $team2Style }}">
                                {{ $formatTeam($match['team_2']) }}
                            </div>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endforeach
</div>
