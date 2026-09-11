<?php

return [
    /*
    | Rebuild event_calendar (ICS) from write-path hooks and DRAHT sync.
    |
    | Default off: each rebuild calls DRAHT then INSERTs event_calendar.
    | On production that stalled DRAHT sync and Overview event switching.
    | Set CALENDAR_REBUILD_ENABLED=true when ICS writes are safe again.
    |
    */
    'rebuild_enabled' => filter_var(env('CALENDAR_REBUILD_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
