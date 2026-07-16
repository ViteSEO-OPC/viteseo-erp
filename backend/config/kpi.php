<?php

/**
 * BD KPI Engine — operational metric parameters (BD-PERF-COMP-SPEC §3, M-01…M-07).
 *
 * The externalized configuration layer for the operational KPI catalog, mirroring config/scoring.php:
 * the calculator reads the status sets and the rolling window from here rather than hard-coding them,
 * so a business-rule adjustment is a config edit. The **conditions/formulas** (which counts divide by
 * which, the zero-denominator rule) live in the calculator; only the tunable sets/windows live here.
 *
 * These sets mirror the FROZEN spec (ACTIVE_SET = R-1); they are centralized here for reuse, not to
 * imply they are casually re-configurable — a change to a frozen set is a spec-level decision.
 */
return [

    // Rolling window (days) for period-bounded KPIs — M-06 Activity Volume (spec §2 fixed period).
    'activity_window_days' => 30,

    // M-03 ACTIVE_SET (R-1): every status except `declined`.
    'active_statuses' => ['new', 'interested', 'awaiting-reply', 'call-back-req'],

    // M-05 Interest Rate numerator statuses.
    'interest_statuses' => ['interested', 'call-back-req'],

    // The status excluded from the M-05 denominator (non-declined assigned).
    'declined_status' => 'declined',

    // The full frozen status enum — the M-02 "Leads by Status" breakdown keys (stable order).
    'statuses' => ['new', 'interested', 'awaiting-reply', 'call-back-req', 'declined'],
];
