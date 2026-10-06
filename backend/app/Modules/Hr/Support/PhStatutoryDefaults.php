<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

/**
 * The canonical Philippine statutory defaults a new HR tenant starts from.
 *
 * WHY THIS CLASS EXISTS. The opening BIR withholding table lives in a migration
 * (2026_08_04_000003_create_hr_tax_versions) and the contribution schedules in
 * ContributionSettingSeeder — both of which run ONCE, against the provider org
 * (migrations pre-date multi-tenancy; the org_id backfill adopted their rows into
 * the FIRST org). A tenant provisioned after that gets none of it, and every gap
 * fails silently the same way: TaxService::bandsAsOf() returns [] → ₱0 withholding;
 * ContributionService::versionsAsOf() drops the type → ₱0 SSS/PhilHealth/Pag-IBIG.
 * The run completes, the totals reconcile, and nothing anywhere says a statutory
 * deduction was skipped (the FIN-035 failure class).
 *
 * These constants are the ONE canonical statement of those defaults for
 * provisioning, transcribed from the sources above (which are deliberately left
 * untouched — the migration must keep backfilling the provider org, and the
 * seeder file is owned by the demo-data workflow). HrProvisioner copies FROM
 * HERE, never from another org's rows, so a tenant's start state cannot depend
 * on what some other tenant has since edited.
 *
 * Figures:
 *  - TAX_BRACKETS: TRAIN graduated rates (RA 10963, second tranche, in force
 *    from 1 Jan 2023), dated 2000-01-01 as the opening table so no historical
 *    period resolves to "no table".
 *  - SSS_2025_BANDS: the published schedule (Circular 2024-006, effective
 *    January 2025) — [salary from, MSC, employer EC add-on].
 *  - CONTRIBUTION_* : opening (pre-2025 simplified) + current (2025) versions,
 *    exactly the two ContributionSettingSeeder records and for the same reason:
 *    old cycles reprice at what they actually charged.
 */
final class PhStatutoryDefaults
{
    /** The opening/current version dates payroll resolves against. */
    public const OPENING_DATE = '2000-01-01';

    public const CURRENT_DATE = '2025-01-01';

    /**
     * TRAIN graduated annual withholding bands: [min_annual, base_tax, rate_on_excess].
     *
     * @var list<array{0: int, 1: int, 2: float}>
     */
    public const TAX_BRACKETS = [
        [0, 0, 0.0],
        [250000, 0, 0.15],
        [400000, 22500, 0.20],
        [800000, 102500, 0.25],
        [2000000, 402500, 0.30],
        [8000000, 2202500, 0.35],
    ];

    public const TAX_NOTE = 'TRAIN graduated rates (RA 10963), in force from 1 January 2023 — provisioned as the opening table so no historical period reprices.';

    /**
     * Contribution settings rows (the active-type registry payroll's
     * assertRatesInForce() checks against) with their opening configs.
     *
     * @var array<string, array<string, mixed>>
     */
    public const CONTRIBUTION_SETTINGS = [
        'sss' => [
            'rate' => 0.045,          // employee share
            'employer_rate' => 0.095, // employer share (the pre-2025 4.5 / 9.5 split)
            'salary_cap' => 30000,
            'note' => 'Opening version — the pre-2025 simplified rates.',
        ],
        'philhealth' => [
            // 5 per cent total premium split 50/50 to 2.5 per cent each side.
            'rate' => 0.025, 'employer_rate' => 0.025, 'salary_floor' => 10000, 'salary_cap' => 100000,
        ],
        'pagibig' => [
            // Employer matches the employee, both capped at ₱200.
            'rate' => 0.02, 'cap' => 200, 'employer_rate' => 0.02, 'employer_cap' => 200,
            // GOV-003 — HDMF's low-income tier: the EMPLOYEE pays 1% (not 2%) when monthly
            // compensation is ₱1,500 or below; the employer stays at 2%. In the opening
            // version too — the tier long predates 2025.
            'low_income_threshold' => 1500, 'low_income_rate' => 0.01,
        ],
    ];

    /**
     * The current (2025) contribution versions, keyed by type, in
     * ContributionVersion column shape (brackets handled separately for SSS).
     *
     * @var array<string, array<string, mixed>>
     */
    public const CONTRIBUTION_VERSIONS_2025 = [
        'sss' => [
            'basis' => 'bracket',
            'employee_rate' => 0.05,
            'employer_rate' => 0.10,
            // The ceiling is the top band's MSC. Kept in step with the bands themselves.
            'salary_cap' => 35000,
            'note' => 'SSS Circular 2024-006 — schedule effective January 2025.',
        ],
        'philhealth' => [
            'basis' => 'percentage',
            'employee_rate' => 0.025,
            'employer_rate' => 0.025,
            'salary_floor' => 10000,
            'salary_cap' => 100000,
            'note' => 'PhilHealth premium, shared equally between employee and employer.',
        ],
        'pagibig' => [
            'basis' => 'percentage',
            'employee_rate' => 0.02,
            'employer_rate' => 0.02,
            'salary_cap' => 10000,
            // GOV-003 — the low-income employee tier: 1% EE (ER stays 2%) at or under
            // ₱1,500 monthly compensation. Provisioner defaults only — EXISTING orgs'
            // stored version rows are not retrofitted.
            'low_income_threshold' => 1500,
            'low_income_employee_rate' => 0.01,
            'note' => 'Pag-IBIG — fund salary capped at ₱10,000; employee share 1% at or below ₱1,500 monthly compensation.',
        ],
    ];

    /**
     * The published SSS schedule: [salary from, MSC, employer EC].
     *
     * A salary is charged on its band's MSC, not on the salary itself. The first
     * band starts at 0 so every salary lands somewhere; the last runs to
     * infinity, which is what makes ₱35,000 the ceiling.
     *
     * @var list<array{0: int, 1: int, 2: int}>
     */
    public const SSS_2025_BANDS = [
        [0, 5000, 10],
        [5250, 5500, 10],
        [5750, 6000, 10],
        [6250, 6500, 10],
        [6750, 7000, 10],
        [7250, 7500, 10],
        [7750, 8000, 10],
        [8250, 8500, 10],
        [8750, 9000, 10],
        [9250, 9500, 10],
        [9750, 10000, 10],
        [10250, 10500, 10],
        [10750, 11000, 10],
        [11250, 11500, 10],
        [11750, 12000, 10],
        [12250, 12500, 10],
        [12750, 13000, 10],
        [13250, 13500, 10],
        [13750, 14000, 10],
        [14250, 14500, 10],
        [14750, 15000, 30],
        [15250, 15500, 30],
        [15750, 16000, 30],
        [16250, 16500, 30],
        [16750, 17000, 30],
        [17250, 17500, 30],
        [17750, 18000, 30],
        [18250, 18500, 30],
        [18750, 19000, 30],
        [19250, 19500, 30],
        [19750, 20000, 30],
        [20250, 20500, 30],
        [20750, 21000, 30],
        [21250, 21500, 30],
        [21750, 22000, 30],
        [22250, 22500, 30],
        [22750, 23000, 30],
        [23250, 23500, 30],
        [23750, 24000, 30],
        [24250, 24500, 30],
        [24750, 25000, 30],
        [25250, 25500, 30],
        [25750, 26000, 30],
        [26250, 26500, 30],
        [26750, 27000, 30],
        [27250, 27500, 30],
        [27750, 28000, 30],
        [28250, 28500, 30],
        [28750, 29000, 30],
        [29250, 29500, 30],
        [29750, 30000, 30],
        [30250, 30500, 30],
        [30750, 31000, 30],
        [31250, 31500, 30],
        [31750, 32000, 30],
        [32250, 32500, 30],
        [32750, 33000, 30],
        [33250, 33500, 30],
        [33750, 34000, 30],
        [34250, 34500, 30],
        [34750, 35000, 30],
    ];

    /**
     * Default leave types (LeaveTypesSeeder shape). LWOP is unlimited so a
     * fresh tenant can file it despite its 0-day allotment.
     *
     * @var list<array<string, mixed>>
     */
    public const LEAVE_TYPES = [
        ['name' => 'Annual Leave', 'code' => 'ANL', 'days_per_year' => 15, 'is_paid' => true, 'is_unlimited' => false, 'color' => '#4FDBD7', 'allow_backdated' => false, 'backdate_limit_days' => null],
        ['name' => 'Sick Leave', 'code' => 'SCK', 'days_per_year' => 10, 'is_paid' => true, 'is_unlimited' => false, 'color' => '#22C554', 'allow_backdated' => true, 'backdate_limit_days' => 14],
        ['name' => 'Emergency Leave', 'code' => 'EMG', 'days_per_year' => 5, 'is_paid' => true, 'is_unlimited' => false, 'color' => '#F0A608', 'allow_backdated' => true, 'backdate_limit_days' => 14],
        ['name' => 'Bereavement Leave', 'code' => 'BRV', 'days_per_year' => 3, 'is_paid' => true, 'is_unlimited' => false, 'color' => '#6B7280', 'allow_backdated' => true, 'backdate_limit_days' => 14],
        ['name' => 'Service Incentive Leave', 'code' => 'SIL', 'days_per_year' => 5, 'is_paid' => true, 'is_unlimited' => false, 'color' => '#2563EB', 'allow_backdated' => false, 'backdate_limit_days' => null],
        ['name' => 'Unpaid Leave (LWOP)', 'code' => 'LWOP', 'days_per_year' => 0, 'is_paid' => false, 'is_unlimited' => true, 'color' => '#EF4444', 'allow_backdated' => false, 'backdate_limit_days' => null],
    ];

    /**
     * Fraction of a leave type's base days each employment status gets by
     * default (LeaveTypeAllotmentSeeder).
     *
     * @var array<string, float>
     */
    public const LEAVE_ALLOTMENT_FACTORS = [
        'regular' => 1.0,
        'probationary' => 0.5,
        'fixed_term' => 0.75,
        'project_based' => 0.75,
        'seasonal' => 0.75,
        // Interns start with none; HR grants credits per person where warranted.
        'ojt' => 0.0,
    ];

    /**
     * Default work schedules (WorkScheduleSeeder). The first is the org default
     * every employee resolves to until HR assigns another.
     *
     * @var list<array<string, mixed>>
     */
    public const WORK_SCHEDULES = [
        [
            'name' => 'Office',
            'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00',
            'end_time' => '18:00',
            'grace_minutes' => 15,
            'unpaid_break_minutes' => 60,
            'paid_break_minutes' => 20,
            'am_start_time' => '08:00',
            'am_end_time' => '12:00',
            'pm_start_time' => '13:00',
            'pm_end_time' => '18:00',
            'is_default' => true,
        ],
        [
            'name' => 'Field',
            'workdays' => [1, 2, 3, 4, 5, 6],
            'start_time' => '08:00',
            'end_time' => '17:00',
            'grace_minutes' => 30,
            'unpaid_break_minutes' => 60,
            'paid_break_minutes' => 20,
            'am_start_time' => '08:00',
            'am_end_time' => '12:00',
            'pm_start_time' => '13:00',
            'pm_end_time' => '17:00',
            'is_default' => false,
        ],
    ];
}
