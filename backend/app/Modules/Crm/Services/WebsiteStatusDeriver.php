<?php

namespace App\Modules\Crm\Services;

/**
 * Derives the coarse `website_status` enum from Website Intelligence audit findings, using the
 * PO-approved **availability + quality** rule (2026-07-13):
 *
 *   - not reachable                                              → `no-website`
 *   - reachable, but no HTTPS OR missing a core page             → `needs-redesign`
 *     (core pages = About + Contact + Services)
 *   - reachable, HTTPS, and all core pages present               → `has-website`
 *
 * This is a small, deterministic fit-data derivation — NOT business scoring. The rich audit findings
 * live on `lead_website_enrichments`; the interpretation/scoring of digital maturity remains the
 * future KPI Engine's responsibility.
 */
class WebsiteStatusDeriver
{
    public function derive(
        bool $reachable,
        bool $https,
        bool $aboutPage,
        bool $contactPage,
        bool $servicesPage,
    ): string {
        if (! $reachable) {
            return 'no-website';
        }

        $hasCorePages = $aboutPage && $contactPage && $servicesPage;
        if (! $https || ! $hasCorePages) {
            return 'needs-redesign';
        }

        return 'has-website';
    }
}
