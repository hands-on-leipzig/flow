<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sync staffing structure after plan generation
    |--------------------------------------------------------------------------
    |
    | When true, GeneratePlanJob runs staffing review/sync after a successful
    | generate. Keep false on shared databases until Slice 2 is ready.
    |
    */
    'sync_after_generate' => (bool) env('STAFFING_SYNC_AFTER_GENERATE', false),
];
