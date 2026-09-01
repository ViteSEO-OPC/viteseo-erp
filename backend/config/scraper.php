<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Python Scraper service account
    |--------------------------------------------------------------------------
    | The discovery service authenticates as a DEDICATED account rather than as a person, and holds
    | one permission. `crm.leads.import` is the whole ability set the deterministic workflow needs —
    | it authorizes the enrichment ingest, the website-evidence queue, and candidate submission, and
    | nothing else.
    |
    | DELIBERATELY NO ROLE. The scraper is not an administrator. Granting a role would hand it the
    | AI-discovery endpoints (`/discovery/proposals`, `/discovery/tag-expansions` gate on
    | `role:super_admin|crm_admin` because they spend money) and lead visibility it has no use for.
    | A permission without a role is the narrowest thing that works.
    |
    | AND DELIBERATELY NOT `no-website`. This account can propose candidates and record what a crawl
    | saw; it cannot confirm a website's absence. That stays a human decision through
    | POST /leads/{lead}/website-absence, which is guarded by a different ability entirely.
    |
    | Provisioned by `php artisan crm:scraper-token`. Tokens are per-database — one minted against
    | another deployment (or against this one before a `migrate:fresh`) will not authenticate here,
    | which is exactly why this is a repeatable command rather than a manual step.
    */
    'service_account' => [
        'email' => env('SCRAPER_SERVICE_EMAIL', 'scraper-service@viteseo.ph'),
        'name' => env('SCRAPER_SERVICE_NAME', 'Scraper Service Account'),
        'permissions' => [
            'crm.leads.import',
        ],
    ],

];
