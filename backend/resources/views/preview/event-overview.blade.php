@php
$isPdf = false;
@endphp

<x-event-overview-table 
    :eventsByDay="$eventsByDay" 
    :columnNames="$columnNames" 
    :isPdf="$isPdf" 
/>