<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenancy core for HR (TeamOra): stamp every hr_* table with the owning
 * org so the BelongsToOrg global scope can fence each tenant's data. Mirrors
 * the Projects template.
 *
 * PORTED from kps (2026_08_18_210000) on 2026-09-03, with three differences,
 * all because this repo's HR has drifted from that one:
 *
 *   - Every loop and every index rewrite guards on the table (and the index)
 *     actually being there. Two hr_* tables exist there and not here, two exist
 *     here and not there, and an unguarded pass dies on the first absent one
 *     and leaves the schema half-stamped.
 *   - `hr_job_runs` joins the content list: it records who ran which payroll job
 *     over which range, and that is one tenant's operational history.
 *   - `hr_company_profiles` joins the SINGLETON list. It is the employer's legal
 *     identity -- name, TIN, SSS/PhilHealth/Pag-IBIG employer numbers -- which
 *     was a platform singleton and is per-org the moment a second client exists.
 *     Filing under the wrong employer number is not a display bug.
 *
 * Content tables end up `org_id NOT NULL`; the single membership pivot
 * (hr_benefit_enrollments) keeps the column NULLABLE because its rows are
 * written through belongsToMany syncWithoutDetaching()/detach(), which bypass
 * model events (no auto-stamp). Both sides of the pivot (hr_benefit_programs,
 * hr_employees) are org-scoped, so a pivot row can never bridge two orgs —
 * the Projects/Booking "pure pivot needs no org_id of its own" precedent,
 * while still carrying the column for reporting/defense-in-depth.
 *
 * Backfill: production is single-org today (17 employees, live payroll), so
 * `org_id = first org` is safe by definition. The backfill ONLY writes the new
 * org_id column — it never reads or rewrites any existing column, so the
 * encrypted PII columns on hr_employees (bank_account, tin, sss_number, ...)
 * are untouched. On a fresh install there are no rows and no org yet — the
 * backfill is a no-op and the NOT NULL change applies to empty tables.
 *
 * Unique-index conversions: every unique key whose columns are global text
 * (a code, a date, a reference) rather than an already-org-scoped FK becomes
 * (org_id, ...) so two tenants can both have an "IT" department, a "VL" leave
 * type, or a payroll run for the same period. Uniques keyed on employee_id /
 * leave_type_id / a version FK stay as they are — those ids are globally
 * unique and already fenced through their parent row.
 */
return new class extends Migration
{
    /** Content tables — org_id becomes NOT NULL after the backfill. */
    private const CONTENT_TABLES = [
        'hr_attendance',
        'hr_attendance_policies',
        'hr_attendance_policy_versions',
        'hr_audit_logs',
        'hr_benefit_program_versions',
        'hr_benefit_programs',
        'hr_candidate_emails',
        'hr_candidates',
        'hr_change_requests',
        'hr_concern_reveal_requests',
        'hr_concerns',
        'hr_contribution_brackets',
        'hr_contribution_settings',
        'hr_contribution_versions',
        'hr_daily_overrides',
        'hr_department_positions',
        'hr_departments',
        'hr_document_requests',
        'hr_early_out_requests',
        'hr_employee_benefits',
        'hr_employee_documents',
        'hr_employee_loans',
        'hr_employees',
        'hr_employment_histories',
        'hr_faqs',
        'hr_half_day_requests',
        'hr_holiday_sets',
        'hr_holidays',
        'hr_leave_balances',
        'hr_leave_requests',
        'hr_leave_settings',
        'hr_leave_type_allotments',
        'hr_leave_types',
        'hr_loan_payments',
        'hr_meetings',
        'hr_office_expenses',
        'hr_overtime_requests',
        'hr_payment_batches',
        'hr_payments',
        'hr_payroll_inquiries',
        'hr_payroll_runs',
        'hr_payroll_setting_versions',
        'hr_payroll_settings',
        'hr_payslip_amendments',
        'hr_payslip_requests',
        'hr_payslips',
        'hr_reimbursements',
        'hr_remote_checkins',
        'hr_report_exports',
        'hr_requisitions',
        'hr_rest_day_requests',
        'hr_retro_flags',
        'hr_salary_histories',
        'hr_shared_leave_allotments',
        'hr_shared_leave_balances',
        'hr_tax_brackets',
        'hr_tax_versions',
        'hr_violations',
        'hr_work_schedules',
        'hr_work_suspensions',
    ];

    /** Pivot/link tables — org_id stays nullable (sync()/detach() bypass events). */
    private const PIVOT_TABLES = [
        'hr_benefit_enrollments',
    ];

    /**
     * Content tables this repo has and the kps port does not.
     *
     * `hr_work_requests` is `hr_rest_day_requests` RENAMED here (2026_08_19_000002).
     * The port names the old table, the hasTable guard skipped it, and the new name
     * would have been left unfenced -- silently, with everything else green. That is
     * the exact failure the guard exists to make survivable rather than invisible.
     *
     * `hr_company_profiles` is also in SINGLETON_TABLES, which only adds the unique
     * index; the column itself is only ever added from the lists below.
     */
    private const EXTRA_CONTENT_TABLES = [
        'hr_job_runs',
        'hr_work_requests',
        'hr_company_profiles',
    ];

    /**
     * Global unique keys that must become per-org.
     *
     * old: existing index name (Laravel's convention unless it was named).
     * cols: the original columns, with org_id prepended in the replacement.
     * new: explicit replacement name (MySQL's 64-char identifier cap).
     *
     * @var array<string, array{old: string, cols: list<string>, new: string}>
     */
    private const UNIQUE_CONVERSIONS = [
        'hr_employees' => [
            'old' => 'hr_employees_employee_no_unique',
            'cols' => ['employee_no'],
            'new' => 'hr_employees_org_employee_no_unique',
        ],
        'hr_departments' => [
            'old' => 'hr_departments_code_unique',
            'cols' => ['code'],
            'new' => 'hr_departments_org_code_unique',
        ],
        'hr_leave_types' => [
            'old' => 'hr_leave_types_code_unique',
            'cols' => ['code'],
            'new' => 'hr_leave_types_org_code_unique',
        ],
        'hr_contribution_settings' => [
            'old' => 'hr_contribution_settings_type_unique',
            'cols' => ['type'],
            'new' => 'hr_contribution_settings_org_type_unique',
        ],
        'hr_concerns' => [
            'old' => 'hr_concerns_reference_no_unique',
            'cols' => ['reference_no'],
            'new' => 'hr_concerns_org_reference_no_unique',
        ],
        'hr_daily_overrides' => [
            'old' => 'hr_daily_overrides_date_unique',
            'cols' => ['date'],
            'new' => 'hr_daily_overrides_org_date_unique',
        ],
        'hr_holiday_sets' => [
            'old' => 'hr_holiday_sets_country_code_year_unique',
            'cols' => ['country_code', 'year'],
            'new' => 'hr_holiday_sets_org_country_year_unique',
        ],
        'hr_shared_leave_allotments' => [
            'old' => 'hr_shared_leave_allotments_employment_status_unique',
            'cols' => ['employment_status'],
            'new' => 'hr_shared_leave_allotments_org_status_unique',
        ],
        'hr_contribution_versions' => [
            'old' => 'hr_contribution_versions_type_effective_from_unique',
            'cols' => ['type', 'effective_from'],
            'new' => 'hr_contribution_versions_org_type_from_unique',
        ],
        'hr_payroll_setting_versions' => [
            'old' => 'hr_payroll_setting_versions_effective_from_unique',
            'cols' => ['effective_from'],
            'new' => 'hr_payroll_setting_versions_org_from_unique',
        ],
        'hr_tax_versions' => [
            'old' => 'hr_tax_versions_effective_from_unique',
            'cols' => ['effective_from'],
            'new' => 'hr_tax_versions_org_from_unique',
        ],
        'hr_attendance_policy_versions' => [
            'old' => 'hr_attendance_policy_versions_effective_from_unique',
            'cols' => ['effective_from'],
            'new' => 'hr_attendance_policy_versions_org_from_unique',
        ],
        'hr_payment_batches' => [
            'old' => 'hr_payment_batches_reference_unique',
            'cols' => ['reference'],
            'new' => 'hr_payment_batches_org_reference_unique',
        ],
        'hr_work_suspensions' => [
            'old' => 'hr_work_suspensions_date_unique',
            'cols' => ['date'],
            'new' => 'hr_work_suspensions_org_date_unique',
        ],
        'hr_payroll_runs' => [
            'old' => 'hr_payroll_runs_period_unique',
            'cols' => ['cycle_start', 'cycle_end', 'frequency'],
            'new' => 'hr_payroll_runs_org_period_unique',
        ],
    ];

    /**
     * One settings row per org — these were `firstOrCreate([])` platform
     * singletons; the unique index backs up the new per-org `current()`.
     */
    private const SINGLETON_TABLES = [
        'hr_payroll_settings',
        'hr_leave_settings',
        'hr_attendance_policies',
        // The employer's own legal identity. One per org, and the one row whose
        // being wrong means filing under another company's employer numbers.
        'hr_company_profiles',
    ];

    /**
     * True when the table, the column and the named index are all really there.
     *
     * Every index rewrite below is guarded through this. Index NAMES are the ones
     * this schema happens to carry, and a dropUnique() on a name that is not here
     * aborts the whole migration half-way -- which on a tenancy migration means a
     * schema where some tables are fenced and some are not. That is worse than
     * not running it at all, because it looks finished.
     */
    private function indexExists(string $table, string $name): bool
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'org_id')) {
            return false;
        }

        return collect(Schema::getIndexes($table))->contains(fn (array $i): bool => $i['name'] === $name);
    }

    public function up(): void
    {
        $tables = [...self::CONTENT_TABLES, ...self::EXTRA_CONTENT_TABLES, ...self::PIVOT_TABLES];

        // 1) Add the column nullable first so existing rows survive the ALTER
        //    on both MySQL (prod) and SQLite (tests).
        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'org_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                // index() names the index explicitly so MySQL reuses it for the
                // FK instead of creating a duplicate, and SQLite (which never
                // auto-indexes FKs) gets one too.
                $blueprint->foreignId('org_id')
                    ->nullable()
                    ->index()
                    ->constrained('orgs')
                    ->cascadeOnDelete();
            });
        }

        // 2) Backfill: single-org production — everything belongs to the first
        //    (provider) org. WRITES THE NEW COLUMN ONLY; no existing column is
        //    read or transformed (the encrypted PII stays byte-identical). No
        //    org yet means a fresh install: nothing to fill.
        $firstOrgId = DB::table('orgs')->orderBy('id')->value('id');
        if ($firstOrgId !== null) {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::table($table)->whereNull('org_id')->update(['org_id' => $firstOrgId]);
            }
        }

        // 3) Lock the content tables down to NOT NULL. Guarded per table: if a
        //    row somehow still has no org (rows exist but the orgs table is
        //    empty), leave that table nullable rather than fail mid-migration.
        foreach ([...self::CONTENT_TABLES, ...self::EXTRA_CONTENT_TABLES] as $table) {
            if (! Schema::hasTable($table) || DB::table($table)->whereNull('org_id')->exists()) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('org_id')->nullable(false)->change();
            });
        }

        // 4) Re-key the global unique indexes per org.
        foreach (self::UNIQUE_CONVERSIONS as $table => $conversion) {
            if ($this->indexExists($table, $conversion['new'])) {
                continue; // already converted
            }
            if (! $this->indexExists($table, $conversion['old'])) {
                continue; // this repo never had it, or a pod renamed it
            }

            Schema::table($table, function (Blueprint $blueprint) use ($conversion): void {
                $blueprint->dropUnique($conversion['old']);
                $blueprint->unique(['org_id', ...$conversion['cols']], $conversion['new']);
            });
        }

        // 5) One settings row per org (each model's current() is firstOrCreate
        //    keyed on org_id — the unique index backs that up).
        foreach (self::SINGLETON_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'org_id')) {
                continue;
            }
            if ($this->indexExists($table, $table.'_org_id_unique')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unique('org_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::SINGLETON_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique(['org_id']);
            });
        }

        // Restore the original global uniques (safe on a single-org dataset —
        // which is the only dataset a rollback of this migration can meet).
        foreach (self::UNIQUE_CONVERSIONS as $table => $conversion) {
            Schema::table($table, function (Blueprint $blueprint) use ($conversion): void {
                $blueprint->dropUnique($conversion['new']);
                $blueprint->unique($conversion['cols'], $conversion['old']);
            });
        }

        foreach ([...self::CONTENT_TABLES, ...self::EXTRA_CONTENT_TABLES, ...self::PIVOT_TABLES] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'org_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('org_id');
            });
        }
    }
};
