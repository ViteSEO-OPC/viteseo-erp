<?php

namespace App\Modules\Crm\Services;

/**
 * Derives the coarse `website_status` enum from Website Intelligence audit findings.
 *
 * The five states answer two different questions, in order. First, **do we know?**
 *
 *   - no URL to check                                            → `website-unknown`
 *   - a URL that did not respond                                 → `website-unreachable`
 *   - a URL that responded, but the page is not provably theirs   → `website-unknown`
 *
 * Only once a page is confirmed to belong to the business ({@see WebsiteIdentityVerifier}) does the
 * original PO-approved **availability + quality** rule (2026-07-13) decide **how good is it?**
 *
 *   - verified, but no HTTPS OR missing a core page              → `needs-redesign`
 *     (core pages = About + Contact + Services)
 *   - verified, HTTPS, and all core pages present                → `has-website`
 *
 * `no-website` — "this business genuinely has no website" — is deliberately NOT reachable from here.
 * An audit can only ever prove that a *given URL* is or isn't theirs; proving that *no* website exists
 * requires having looked for one, which is candidate discovery (Step 3, not built). Until then
 * `no-website` is set by the evidence chain once every required tier has run and verified nothing
 * (WebsiteEvidenceService), by a human who checked, or carried in by an import. Previously this
 * class returned `no-website` for an unreachable URL, which conflated "we couldn't reach it" with
 * "it doesn't exist" and awarded the +25 greenfield-prospect signal on no evidence.
 *
 * This is a small, deterministic fit-data derivation — NOT business scoring. The rich audit findings
 * live on `crm_lead_website_enrichments`; the interpretation/scoring of digital maturity remains the
 * future KPI Engine's responsibility.
 */
class WebsiteStatusDeriver
{
    public function derive(
        bool $hasUrl,
        bool $reachable,
        bool $identityVerified,
        bool $https,
        bool $aboutPage,
        bool $contactPage,
        bool $servicesPage,
    ): string {
        if (! $hasUrl) {
            return 'website-unknown';
        }

        if (! $reachable) {
            return 'website-unreachable';
        }

        // Reachable is not ownership. An unconfirmed page leaves the lead exactly as uninformed as it
        // was — never better, never worse.
        if (! $identityVerified) {
            return 'website-unknown';
        }

        $hasCorePages = $aboutPage && $contactPage && $servicesPage;
        if (! $https || ! $hasCorePages) {
            return 'needs-redesign';
        }

        return 'has-website';
    }
}
