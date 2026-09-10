<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Models\Org;
use App\Modules\Crm\Database\Seeders\EmailTemplateSeeder;
use App\Support\CurrentOrg;

/**
 * Provisions the per-org CRM baseline for a NEW tenant — the HrProvisioner pattern,
 * applied to a module where the stakes are lower and the list is therefore shorter.
 *
 * WHAT A NEW ORG WAS MISSING, MEASURED RATHER THAN ASSUMED. A freshly onboarded CRM
 * tenant came up with zero email templates, zero niches and zero business rules.
 * Only the first of those is a gap:
 *
 *   - EMAIL TEMPLATES are provisioned here. They arrive from a seeder that ran once
 *     before tenancy, so the provider org has fourteen and every client after it had
 *     none — Outreach with nothing to send is a product that looks broken on first
 *     use.
 *
 *   - NICHES are deliberately NOT provisioned. NicheSeeder::BUILT_INS is empty on
 *     purpose, pending an approval that has not happened; an empty niche list is the
 *     intended state, not a hole. Filling it here would be inventing product content.
 *
 *   - BUSINESS RULES and the KPI catalog are deliberately NOT provisioned. They are
 *     installed by two data migrations that ran before any org existed, so a new
 *     tenant has none — but ScoringConfigProvider is built for exactly that: on any
 *     absence it falls back to the frozen `config('scoring')` and LOGS the fallback.
 *     A tenant without them scores leads correctly and says so in the log. That is a
 *     different class of gap from HR's, where an absent tax table silently withholds
 *     nothing, and it is why this class is four lines rather than two hundred.
 *
 * If the Business Rules SCREEN is ever meant to open with something editable, the
 * work is to lift those two migrations' data into a shared defaults class the way
 * PhStatutoryDefaults holds HR's — never to copy the provider org's rows, which a
 * client may since have edited.
 *
 * IDEMPOTENT: the seeder it delegates to is firstOrCreate throughout, so
 * re-onboarding or adding CRM to an existing org changes nothing already there.
 */
class CrmProvisioner
{
    public function provisionFor(Org $org): void
    {
        CurrentOrg::runAs($org->id, function (): void {
            // Through the seeder, not a copy of it: one catalogue, one place to edit.
            (new EmailTemplateSeeder)->run();

            /*
             * Version 1 of both Business Rules modules. Not a scoring fix -- scoring
             * already falls back to config('scoring') and logs it -- but an EDITING
             * one: a draft is built by copying the ACTIVE version, so a tenant with no
             * Version 1 is told "No active configuration exists to base a draft on"
             * and can neither read its own rules nor change them.
             */
            CrmRuleDefaults::install();
        });
    }
}
