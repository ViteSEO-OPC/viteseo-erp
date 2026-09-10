<?php

use App\Modules\Crm\Support\CrmRuleDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Business Rules Administration — Version 1 baseline seed (Implementation Design §2.2).
 *
 * Seeds the baseline lead_scoring configuration as a DATA MIGRATION (not a seeder), so every environment
 * gets the same baseline atomically with the schema and drift is impossible (§2). The baseline is
 * ACTIVE Version 1 and equals the frozen `config/scoring.php` values byte-for-byte — POINT VALUES are read
 * straight from `config('scoring')` here, so the seed cannot transcribe a wrong number and "Version 1 == the
 * file" holds by construction (§2.8, launch gate).
 *
 * IDEMPOTENT (§2.3): a guard checks for an existing lead_scoring Version 1 first and exits without touching
 * anything if present — re-running `migrate`, re-provisioning, or a partial re-run never duplicates or
 * overwrites edited data. The whole seed runs in ONE transaction (§2.2), so it either establishes the
 * complete baseline (version + all rules + seed audit) or does nothing — never a partial baseline (§2.5).
 *
 * Completion / readiness (§2.11): this design uses the provider's baseline VERIFICATION as the readiness
 * signal (Option 2) — the presence of exactly one valid active version, the complete ruleset, and this
 * `seed` audit row collectively prove completion. No separate marker column is added.
 *
 * The structural caps and time windows are intentionally NOT seeded as rules — they stay file-sourced (not
 * per-rule, not admin-editable in v1); see the crm_business_rules migration.
 */
return new class extends Migration
{
    private const SPEC_VERSION = 'v1.0.0';

    /**
     * The frozen lead_scoring ruleset metadata: [rule_key, category, description, is_enabled].
     * POINT VALUES are NOT listed here — they are read from config('scoring.points') so the baseline is
     * guaranteed equal to the file. Order in this array is the display_order (presentation only).
     */
    private function ruleset(): array
    {
        return [
            // Fit signals (category subtotal capped at 40).
            ['no_website', 'fit', 'A business with no website is a strong opportunity.', true],
            ['needs_redesign', 'fit', 'A website that needs improvement is a moderate opportunity.', true],
            ['target_location', 'fit', 'The business is inside a configured target service area.', true],
            ['contact_person', 'fit', 'A real, named contact makes the lead actionable.', true],

            // Engagement signals (category subtotal capped at 60).
            ['interested_reply', 'engagement', 'The lead has expressed genuine interest.', true],
            ['call_completed', 'engagement', 'A call actually took place.', true],
            ['call_scheduled', 'engagement', 'A call is booked but not yet held.', true],
            ['email_opened', 'engagement', 'The lead engaged with an outreach email.', true],
            ['awaiting_reply', 'engagement', 'Outreach has been sent; a reply is pending.', true],
            ['recent_activity', 'engagement', 'The lead was worked within the last 7 days.', true],

            // Negative signals — stored as positive MAGNITUDES; the sign is carried by category=negative and
            // the frozen calculator subtracts them.
            ['has_website', 'negative', 'An existing, adequate website is a weaker opportunity.', true],
            ['no_activity', 'negative', 'Untouched for more than 30 days.', true],
            // Coming Soon — inactive until email-delivery signals exist; contributes 0 today.
            ['invalid_email', 'negative', 'An undeliverable email indicates a low-quality lead.', false],
        ];
    }

    public function up(): void
    {
        // Delegated on 2026-09-04. The ruleset metadata now has ONE home --
        // CrmRuleDefaults -- because the same baseline must also be installed for
        // every org onboarded after this migration ran, and two copies of a list
        // like this drift the first time somebody edits one of them.
        CrmRuleDefaults::installScoringFor(null);
    }

    /**
     * Targeted reversal (§2.4): remove ONLY the baseline this migration created — Version 1 and its rules
     * (cascade), plus its seed audit row. Later admin-created versions are untouched. In practice the
     * whole-feature revert drops the tables anyway; this is the courtesy single-step reversal.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('crm_business_rule_versions')
                ->where('module', 'lead_scoring')
                ->where('version_number', 1)
                ->delete(); // rules cascade via the foreign key.

            DB::table('crm_business_rule_audit')
                ->where('module', 'lead_scoring')
                ->where('action', 'seed')
                ->where('version_number', 1)
                ->delete();
        });
    }
};
