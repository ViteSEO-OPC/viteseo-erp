<?php

use App\Modules\Crm\Services\Scoring\LeadScoreCalculator;

/**
 * Personal Lead Score — business parameters (BD-PERF-COMP-SPEC §4 + Amendment A/B).
 *
 * This is the **single source of truth for every scoring weight, window, and cap**. The calculator
 * ({@see LeadScoreCalculator}) reads these values and never hard-codes a number,
 * so a future business-rule change (a re-weight approved by the Product Owner) is a config edit — not
 * a code change. The *conditions* that decide whether a signal fires (which enum value counts as
 * "opened", the real-contact rule, mutual exclusivity) live in the calculator; only the tunable
 * business numbers live here. This is the "KPI Configuration layer" the spec anticipates externalizing
 * (§9) — realized here without changing any approved meaning.
 *
 * Negative-signal points are stored as positive **magnitudes**; the calculator subtracts them.
 */
return [

    // Fixed additive points per signal (whole integers — no weighting/averaging; R-3).
    'points' => [
        // Fit signals
        'no_website' => 25,
        'needs_redesign' => 10,   // Amendment B — previously-undefined middle website state
        'target_location' => 10,
        'contact_person' => 5,

        // Engagement signals
        'interested_reply' => 30,
        'call_completed' => 15,
        'call_scheduled' => 8,
        'email_opened' => 5,
        'awaiting_reply' => 5,
        'recent_activity' => 5,

        // Negative signals (magnitudes — subtracted)
        'has_website' => 15,
        'no_activity' => 10,
        'invalid_email' => 15,    // not evaluable until Gmail — contributes 0 today (§10 / B-4)
    ],

    // Time-relative signal windows, in days (evaluated against `leads.last_activity`).
    'windows' => [
        'recent_days' => 7,       // Recent Activity (≤7 days)  → +recent_activity
        'stale_days' => 30,       // No Activity (>30 days)     → −no_activity
    ],

    // Structural caps (BD-PERF-COMP-SPEC §4.2/§4.3). Enforced as hard ceilings so a future weight
    // change can never breach the model's shape: category subtotals are capped before the final
    // clamp, and the final score is clamped to [score_min, score_max].
    'caps' => [
        'fit_max' => 40,
        'engagement_max' => 60,
        'score_min' => 0,
        'score_max' => 100,
    ],
];
