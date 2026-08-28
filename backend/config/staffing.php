<?php

return [
    /*
    | When true, GeneratePlanJob runs staffing sync after a successful generate.
    | Default on — staffing structure follows plan generation automatically.
    |
    */
    'sync_after_generate' => (bool) env('STAFFING_SYNC_AFTER_GENERATE', true),
];
