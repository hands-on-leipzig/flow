@php
$isPdf = true;
@endphp

<x-event-overview-table 
    :eventsByDay="$eventsByDay" 
    :columnNames="$columnNames" 
    :isPdf="$isPdf" 
/>