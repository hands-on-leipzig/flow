@php
$isMultiDay = count($eventsByDay) > 1;
$allColumns = [];
foreach ($programs as $program) {
    foreach ($program['columns'] as $col) {
        $allColumns[] = $col;
    }
}
@endphp

@if($programs === [] || $allColumns === [])
    <div class="roles-grid-empty">Keine Rollen-Spalten für diesen Plan.</div>
@elseif($eventsByDay === [])
    <div class="roles-grid-empty">Keine Aktivitäten mit Spur oder Tisch.</div>
@else
<div class="roles-grid-container">
    @if($hasOverlaps ?? false)
        <div class="roles-grid-overlap-warning">Es gibt überlappende Aktivitäten</div>
    @endif
    @foreach($eventsByDay as $dayKey => $dayData)
        <div class="roles-grid-day">
            @if($isMultiDay)
                <div class="roles-grid-day-header">
                    {{ $dayData['date']->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}
                </div>
            @endif

            <div class="roles-grid-table-wrap">
                <table class="roles-grid-table">
                    <thead>
                        <tr>
                            <th class="roles-grid-time-col" rowspan="2">Zeit</th>
                            @foreach($programs as $program)
                                <th
                                    colspan="{{ count($program['columns']) }}"
                                    class="roles-grid-program-header"
                                    data-program-id="{{ $program['id'] }}"
                                >
                                    <div class="roles-grid-program-header__inner">
                                        <img
                                            src="{{ $program['logo'] }}"
                                            alt="{{ $program['label'] }}"
                                            class="roles-grid-program-logo"
                                        >
                                    </div>
                                </th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach($allColumns as $col)
                                <th
                                    class="roles-grid-role-header"
                                    data-program-id="{{ $col['program_id'] }}"
                                >{{ $col['title'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $occupied = []; // column_key => end slot index
                            $events = collect($dayData['events']);
                        @endphp
                        @foreach($dayData['timeSlots'] as $index => $slot)
                            @php
                                $isFullHour = $slot->minute === 0;
                                $slotTime = $slot->format('H:i');
                                $rowsPerHour = 60 / 5; // 12
                            @endphp
                            <tr class="roles-grid-row">
                                @if($isFullHour)
                                    @php
                                        $timeRowspan = min($rowsPerHour, count($dayData['timeSlots']) - $index);
                                    @endphp
                                    <td rowspan="{{ $timeRowspan }}" class="roles-grid-time-cell">{{ $slot->format('H:i') }}</td>
                                @endif

                                @foreach($allColumns as $col)
                                    @php
                                        $ck = $col['key'];
                                        if (isset($occupied[$ck]) && $occupied[$ck] > $index) {
                                            continue;
                                        }

                                        $starting = $events->first(function ($ev) use ($ck, $slotTime) {
                                            return $ev['column_key'] === $ck
                                                && $ev['start']->format('H:i') === $slotTime;
                                        });

                                        $rowspan = 0;
                                        if ($starting) {
                                            $remaining = count($dayData['timeSlots']) - $index;
                                            $rowspan = max(1, min((int) $starting['rowspan'], $remaining));
                                            $occupied[$ck] = $index + $rowspan;
                                            $isOverlapCell = ! empty($starting['overlap_adjusted']) || ! empty($starting['overlap_container']);
                                            if ($isOverlapCell) {
                                                $colors = ['bg' => '#000', 'border' => '#000'];
                                            } else {
                                                $colors = \App\Support\OverviewPlanStyle::cellColors($starting['style_column']);
                                            }
                                        }
                                    @endphp

                                    @if($starting)
                                        <td
                                            rowspan="{{ $rowspan }}"
                                            class="roles-grid-activity"
                                            data-program-id="{{ $col['program_id'] }}"
                                            style="background-color: {{ $colors['bg'] }}; border-left: 3px solid {{ $colors['border'] }};{{ $isOverlapCell ? ' color: #fff;' : '' }}"
                                        >{{ $starting['text'] }}</td>
                                    @else
                                        <td class="roles-grid-empty-cell" data-program-id="{{ $col['program_id'] }}"></td>
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
@endif

<style>
.roles-grid-container {
    font-family: sans-serif;
    line-height: 1.3;
    color: #333;
}
.roles-grid-empty {
    padding: 1.5rem;
    text-align: center;
    color: #6b7280;
    font-size: 0.875rem;
}
.roles-grid-overlap-warning {
    background-color: #000;
    color: #fff;
    padding: 8px 12px;
    margin: 0 0 10px 0;
    font-size: 13px;
    font-weight: 600;
    border-radius: 3px;
}
.roles-grid-day {
    margin-bottom: 1.5rem;
}
.roles-grid-day-header {
    background-color: #34495e;
    color: white;
    padding: 8px 12px;
    margin: 0 0 10px 0;
    font-size: 16px;
    border-radius: 3px;
}
.roles-grid-table-wrap {
    overflow-x: auto;
    border: 1px solid #ddd;
    border-radius: 3px;
}
.roles-grid-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 11px;
}
.roles-grid-table th,
.roles-grid-table td {
    border: 1px solid #ddd;
    padding: 2px 3px;
    vertical-align: top;
}
.roles-grid-time-col {
    width: 3.5rem;
    background: #f8f9fa;
}
.roles-grid-program-header {
    background: #f8f9fa;
    text-align: center;
    font-weight: 600;
}
.roles-grid-program-header__inner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 4px;
}
.roles-grid-program-logo {
    height: 28px;
    width: auto;
}
.roles-grid-role-header {
    background: #fff;
    text-align: center;
    font-weight: 600;
    font-size: 10px;
    white-space: nowrap;
}
.roles-grid-time-cell {
    background: #f8f9fa;
    font-weight: 600;
    text-align: center;
    vertical-align: top;
}
.roles-grid-activity {
    overflow: hidden;
    line-height: 1.15;
    font-size: 10px;
}
.roles-grid-empty-cell {
    background: #f9f9f9;
    height: 14px;
}
.roles-grid-row {
    height: 14px;
}
</style>
