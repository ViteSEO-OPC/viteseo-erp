<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Niche;

/**
 * Resolves a lead's raw `industry` string to the Business Niche registry's canonical spelling.
 *
 * A lead stores its niche as a plain `crm_leads.industry` string — there is no `niche_id` and no
 * relation ({@see Niche}). The link is a CASE-INSENSITIVE NAME MATCH, and that rule is already
 * applied consistently across the CRM: `Niche::leadsMatching()`, `Niche::isInUse()`,
 * `Niche::repointLeads()`, and the Leads industry filter all compare on `LOWER(industry)`.
 *
 * Nearby Clients was the one surface that did not. Its niche filter derives options from the raw
 * strings, so two prospects recorded as `pharmacy` and `Pharmacy` produced two dropdown entries and
 * filtering by either missed the other. This class closes that gap by giving the Nearby surface the
 * same view of a niche the rest of the CRM has: whatever spelling the registry holds.
 *
 * **What it deliberately does NOT do.** It never invents, registers, or renames a niche, and it
 * never writes. A value with no registry match passes through UNCHANGED — discovery legitimately
 * produces niches the registry has never heard of (the scraper derives `industry` from OpenStreetMap
 * tags), and silently hiding or rewriting those would misrepresent the prospect pool. Registering
 * them stays a human decision through Create Lead → Add Business Niche.
 *
 * **Scraper contract untouched.** This reads on the way OUT. `POST /leads/discovered` still stores
 * exactly the `industry` the provider supplied; OSM discovery categories and the CRM niche registry
 * remain separate vocabularies that merely happen to meet, case-insensitively, at display time.
 *
 * The registry is loaded ONCE per instance and cached, so serializing a whole prospect pool costs a
 * single query rather than one per row.
 */
class NicheCanonicalizer
{
    /** @var array<string, string>|null lower-cased registry name => canonical name */
    private ?array $map = null;

    /**
     * The registry's spelling of `$industry`, or the value unchanged when it is not registered.
     *
     * Null and blank pass straight through: `distinct()` on the frontend already drops them, and
     * fabricating a niche for a lead that has none would be worse than showing none.
     */
    public function canonical(?string $industry): ?string
    {
        if ($industry === null) {
            return null;
        }

        $trimmed = trim($industry);
        if ($trimmed === '') {
            return $industry;
        }

        return $this->map()[mb_strtolower($trimmed)] ?? $industry;
    }

    /** Whether this industry value exists in the registry (case-insensitively). */
    public function isRegistered(?string $industry): bool
    {
        if ($industry === null || trim($industry) === '') {
            return false;
        }

        return isset($this->map()[mb_strtolower(trim($industry))]);
    }

    /** @return array<string, string> */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $this->map = Niche::query()
            ->get(['name'])
            ->mapWithKeys(fn (Niche $niche): array => [
                mb_strtolower((string) $niche->name) => (string) $niche->name,
            ])
            ->all();

        return $this->map;
    }
}
