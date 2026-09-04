<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Support\CurrentOrg;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The Version 1 baselines for the two Business Rules modules, and the ONE place
 * they are written from.
 *
 * WHY THIS EXISTS. Both baselines were installed by data migrations that ran once,
 * before tenancy — so the provider org has them and every client onboarded after
 * it has none. That is not a scoring bug: ScoringConfigProvider falls back to the
 * frozen `config('scoring')` and logs the fallback, so a tenant without a baseline
 * still scores leads correctly.
 *
 * It is an EDITING bug, and a silent one. A draft is built by copying the active
 * version, so with no Version 1 the API answers "No active configuration exists to
 * base a draft on" — a client who opens Business Rules cannot read what the rules
 * are and cannot change them. Telling them to "create your own" would be a lie;
 * there is nothing to create from.
 *
 * WHAT IS AND IS NOT DUPLICATED HERE. The rule METADATA — keys, categories,
 * descriptions, display order — lives here and nowhere else; the two migrations
 * call this class rather than carrying their own copy. The NUMBERS do not live
 * here at all: point values come from `config('scoring.points')` exactly as they
 * did before, so the baseline is guaranteed equal to the file and this class
 * cannot transcribe a wrong one.
 *
 * IDEMPOTENT per org, on the same guard the migrations used: a version 1 that
 * already exists is left alone, so re-provisioning never duplicates a catalogue
 * or overwrites one a client has since edited.
 */
final class CrmRuleDefaults
{
    public const SPEC_VERSION = 'v1.0.0';

    /**
     * The frozen lead_scoring ruleset: [rule_key, category, description, is_enabled].
     * Array order is the display order.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function scoringRuleset(): array
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

            // Negative signals — stored as positive MAGNITUDES; the sign is carried by
            // category=negative and the frozen calculator subtracts them.
            ['has_website', 'negative', 'An existing, adequate website is a weaker opportunity.', true],
            ['no_activity', 'negative', 'Untouched for more than 30 days.', true],
            // Coming Soon — inactive until email-delivery signals exist; contributes 0 today.
            ['invalid_email', 'negative', 'An undeliverable email indicates a low-quality lead.', false],
        ];
    }

    /**
     * The approved KPI catalog: [rule_key, description, is_enabled], in KpiCalculator order.
     *
     * No category and no point value: BusinessRuleCategory carries a SCORING rule's
     * arithmetic sign and has no meaning for a KPI.
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public static function kpiCatalog(): array
    {
        return [
            ['M-01', 'Assigned Leads — the size of the portfolio.', true],
            ['M-02', 'Leads by Status — the portfolio broken down by lead status.', true],
            ['M-03', 'Active Leads — leads still in play (every status except declined).', true],
            ['M-04', 'Engagement Coverage — the share of assigned leads with outreach or calling started.', true],
            ['M-05', 'Interest Rate — the share of non-declined leads showing genuine interest.', true],
            ['M-06', 'Activity Volume — recorded CRM activity within the rolling window.', true],
        ];
    }

    /**
     * Install both baselines for one org. Safe to call again.
     *
     * `$orgId` is passed rather than read, because the migrations run before any org
     * exists and stamp null — which the tenancy backfill then claims for the provider
     * org, exactly as it did for every other pre-tenancy row.
     */
    public static function installFor(?int $orgId): void
    {
        DB::transaction(static function () use ($orgId): void {
            self::installScoring($orgId);
            self::installKpi($orgId);
        });
    }

    /** Install for whichever org is current. */
    public static function install(): void
    {
        self::installFor(CurrentOrg::id());
    }

    /**
     * Scoring only. The two migrations install one module each, in their own place
     * in the history: the KPI catalogue writes `category` as NULL, and that column
     * is not nullable yet at the point the lead_scoring baseline runs.
     */
    public static function installScoringFor(?int $orgId): void
    {
        DB::transaction(static fn () => self::installScoring($orgId));
    }

    /** KPI catalogue only. See installScoringFor(). */
    public static function installKpiFor(?int $orgId): void
    {
        DB::transaction(static fn () => self::installKpi($orgId));
    }

    private static function installScoring(?int $orgId): void
    {
        if (self::versionExists('lead_scoring', $orgId)) {
            return;
        }

        $points = config('scoring.points');
        $now = now();

        $versionId = DB::table('crm_business_rule_versions')->insertGetId([
            ...self::orgColumn($orgId),
            'module' => 'lead_scoring',
            'version_number' => 1,
            'state' => 'active',
            'computation_spec_version' => self::SPEC_VERSION,
            'reason_for_change' => 'System baseline seeded from the frozen Business Computation Specification v1.0.',
            'created_by' => null,
            'published_by' => null,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $order = 0;
        $rows = [];
        foreach (self::scoringRuleset() as [$ruleKey, $category, $description, $isEnabled]) {
            // A missing frozen point value aborts the whole install rather than
            // seeding an invalid baseline. The transaction rolls it back.
            if (! array_key_exists($ruleKey, $points)) {
                throw new RuntimeException(
                    "Baseline seed aborted: scoring point value for '{$ruleKey}' is missing from config('scoring')."
                );
            }

            $rows[] = [
                ...self::orgColumn($orgId),
                'version_id' => $versionId,
                'rule_key' => $ruleKey,
                'description' => $description,
                'category' => $category,
                'point_value' => (int) $points[$ruleKey],
                'is_enabled' => $isEnabled,
                'display_order' => ++$order,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('crm_business_rules')->insert($rows);

        DB::table('crm_business_rule_audit')->insert([
            ...self::orgColumn($orgId),
            'module' => 'lead_scoring',
            'action' => 'seed',
            'rule_key' => null,
            'previous_value' => null,
            'new_value' => 'Version 1 baseline ('.count($rows).' rules) seeded from the frozen Business Computation Specification v1.0.',
            'version_number' => 1,
            'computation_spec_version' => self::SPEC_VERSION,
            'reason_for_change' => 'Initial system baseline.',
            'user_id' => null,
            'created_at' => $now,
        ]);
    }

    private static function installKpi(?int $orgId): void
    {
        if (self::versionExists('kpi', $orgId)) {
            return;
        }

        $now = now();

        $versionId = DB::table('crm_business_rule_versions')->insertGetId([
            ...self::orgColumn($orgId),
            'module' => 'kpi',
            'version_number' => 1,
            'state' => 'active',
            'computation_spec_version' => self::SPEC_VERSION,
            'reason_for_change' => 'System baseline: the approved operational KPI catalog.',
            'created_by' => null,
            'published_by' => null,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $order = 0;
        $rows = [];
        foreach (self::kpiCatalog() as [$ruleKey, $description, $isEnabled]) {
            $rows[] = [
                ...self::orgColumn($orgId),
                'version_id' => $versionId,
                'rule_key' => $ruleKey,
                'description' => $description,
                'category' => null,
                'point_value' => null,
                'is_enabled' => $isEnabled,
                'display_order' => ++$order,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('crm_business_rules')->insert($rows);

        DB::table('crm_business_rule_audit')->insert([
            ...self::orgColumn($orgId),
            'module' => 'kpi',
            'action' => 'seed',
            'rule_key' => null,
            'previous_value' => null,
            'new_value' => null,
            'version_number' => 1,
            'computation_spec_version' => self::SPEC_VERSION,
            'reason_for_change' => 'Initial system baseline.',
            'user_id' => null,
            'created_at' => $now,
        ]);
    }

    /**
     * The org column, or nothing at all.
     *
     * The two migrations that call this run BEFORE 2026_09_03_100001 adds org_id to
     * the crm_* tables, so on a fresh install the column does not exist yet when the
     * baseline is written. Their rows land unstamped and the tenancy backfill then
     * claims them for the provider org -- exactly what happens to every other
     * pre-tenancy row. From the provisioner, called long after, the column is there.
     *
     * @return array<string, int|null>
     */
    private static function orgColumn(?int $orgId): array
    {
        return Schema::hasColumn('crm_business_rule_versions', 'org_id')
            ? ['org_id' => $orgId]
            : [];
    }

    private static function versionExists(string $module, ?int $orgId): bool
    {
        return DB::table('crm_business_rule_versions')
            ->where('module', $module)
            ->where('version_number', 1)
            ->when(
                $orgId !== null && Schema::hasColumn('crm_business_rule_versions', 'org_id'),
                fn ($q) => $q->where('org_id', $orgId)
            )
            ->exists();
    }
}
