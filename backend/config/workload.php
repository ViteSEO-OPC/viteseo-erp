<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Workload level thresholds
    |--------------------------------------------------------------------------
    |
    | A member's aggregated active effort score is classified into a level for
    | the assignment workload indicator. Bounds are inclusive upper limits:
    |
    |   Light    : 0 .. light
    |   Moderate : light + 1 .. moderate
    |   Heavy    : > moderate
    |
    | Kept in config (env-overridable) rather than hardcoded so thresholds can
    | be tuned per deployment.
    */
    'thresholds' => [
        'light' => (int) env('WORKLOAD_LIGHT_MAX', 20),
        'moderate' => (int) env('WORKLOAD_MODERATE_MAX', 40),
    ],
];
