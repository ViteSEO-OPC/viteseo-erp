<?php

/**
 * BD Ranking (Interim) — business parameters (BD-PERF-COMP-SPEC §5, B-01).
 *
 * The externalized configuration layer for the **Interim** BD Ranking model, mirroring
 * config/scoring.php and config/kpi.php. The calculator reads the component weights, the ranking
 * precision, and the minimum-peer threshold from here; the normalization method (peer-relative
 * min-max), the formula, the tie-breaks, and the edge rules live in the calculator.
 *
 * ⚠ INTERIM MODEL (R-10). These weights belong to the interim proxy computed from currently-available
 * CRM data. The production ranking (research-based normalized composite of Revenue / Deals Closed /
 * Conversion Rate / Sales Cycle) is deferred to Phase 2 and will REPLACE this model — not extend it.
 */
return [

    // C1–C4 component weights (R-7: must sum to 1.00). §5.2.
    'weights' => [
        'c1_portfolio_quality' => 0.40, // mean PLS (leads.score) across the BD's assigned leads
        'c2_engagement_coverage' => 0.25, // M-04
        'c3_interest_rate' => 0.20, // M-05
        'c4_activity_volume' => 0.15, // M-06
    ],

    // BDR index DISPLAY precision. PO decision 2026-07-13: present the index as an **integer**
    // (e.g. 43), per §2/§5.3 `round()` — easier to read. Ranking always orders by the FULL-precision
    // composite internally (so near-ties still rank correctly); only the surfaced `bdr` is rounded to
    // this precision. Set to 1 to show the §5.7 worked-example form (42.6).
    'bdr_decimals' => 0,

    // Relative ranking needs at least this many ranked peers (BDs with a portfolio). Fewer → N/A (§5.6).
    'min_ranked_peers' => 2,
];
