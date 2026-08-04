<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KPI Definitions — the M-01…M-06 catalog as an ACTIVE Version 1, seeded as a DATA MIGRATION so
 * every environment gets the same baseline atomically with the schema. Mirrors the lead_scoring
 * baseline seed exactly, including its idempotency guard, single transaction and seed audit row.
 *
 * METADATA ONLY. `point_value` is NULL for every row — a KPI carries no scoring points, and the
 * preceding migration widened the column precisely so that absence could be stored honestly rather
 * than faked as 0.
 *
 * THE FORMULAS ARE NOT HERE AND ARE NOT EDITABLE. M-01…M-06 remain code-defined in KpiCalculator and
 * authoritative; these rows describe the catalog, they do not drive computation. `rule_key` is the
 * join back to the frozen implementation and is immutable — a row without a matching calculator
 * formula would be a definition of nothing, which is why the management UI cannot create new ones.
 */
return new class extends Migration
{
    private const SPEC_VERSION = 'v1.0.0';

    /**
     * The approved catalog: [rule_key, description, is_enabled], in KpiCalculator order.
     *
     * No category: BusinessRuleCategory carries a scoring rule's arithmetic sign and has no meaning
     * for a KPI, so the column is left NULL exactly as point_value is.
     */
    private function catalog(): array
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

    public function up(): void
    {
        DB::transaction(function (): void {
            // Idempotency guard, as the lead_scoring baseline does: re-running migrate or
            // re-provisioning must never duplicate or overwrite an edited catalog.
            if (DB::table('crm_business_rule_versions')->where('module', 'kpi')->where('version_number', 1)->exists()) {
                return;
            }

            $now = now();

            $versionId = DB::table('crm_business_rule_versions')->insertGetId([
                'module' => 'kpi',
                'version_number' => 1,
                'state' => 'active',
                'computation_spec_version' => self::SPEC_VERSION,
                'reason_for_change' => 'System baseline: the frozen M-01…M-06 KPI catalog. Definition metadata only — formulas remain code-defined.',
                'created_by' => null,
                'published_by' => null,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $order = 0;
            $rows = [];
            foreach ($this->catalog() as [$ruleKey, $description, $isEnabled]) {
                $rows[] = [
                    'version_id' => $versionId,
                    'rule_key' => $ruleKey,
                    'description' => $description,
                    'category' => null, // not applicable to a KPI — see catalog() docblock
                    'point_value' => null, // not applicable to a KPI — see the class docblock
                    'is_enabled' => $isEnabled,
                    'display_order' => ++$order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('crm_business_rules')->insert($rows);

            DB::table('crm_business_rule_audit')->insert([
                'module' => 'kpi',
                'action' => 'seed',
                'rule_key' => null,
                'previous_value' => null,
                'new_value' => null,
                'version_number' => 1,
                'computation_spec_version' => self::SPEC_VERSION,
                'reason_for_change' => 'System baseline seed of the KPI definition catalog (M-01…M-06).',
                'user_id' => null,
                'created_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $versionIds = DB::table('crm_business_rule_versions')->where('module', 'kpi')->pluck('id');
            DB::table('crm_business_rules')->whereIn('version_id', $versionIds)->delete();
            DB::table('crm_business_rule_audit')->where('module', 'kpi')->delete();
            DB::table('crm_business_rule_versions')->where('module', 'kpi')->delete();
        });
    }
};
