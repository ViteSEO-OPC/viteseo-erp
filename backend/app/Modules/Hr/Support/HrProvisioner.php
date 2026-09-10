<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

use App\Models\Org;
use App\Modules\Hr\Models\ContributionBracket;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\PayrollSettingVersion;
use App\Modules\Hr\Models\TaxBracket;
use App\Modules\Hr\Models\TaxVersion;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\HolidayService;
use App\Support\CurrentOrg;

/**
 * Provisions the per-org HR statutory baseline for a NEW tenant — the
 * ChartOfAccountsProvisioner pattern, applied to payroll correctness.
 *
 * WHY THIS IS LOAD-BEARING, NOT CONVENIENCE. Payroll resolves everything from
 * dated, org-scoped rows: the BIR table (hr_tax_versions), the contribution
 * schedules (hr_contribution_settings + versions), the payroll-settings
 * versions, the attendance policy and the work schedules. For the provider org
 * those rows came from migrations and seeders that ran once, pre-tenancy, and
 * were adopted by the org_id backfill. An org created AFTER that has none of
 * them — and almost every gap fails silently: no tax table → ₱0 withholding,
 * no contribution version for an inactive-typeless org → ₱0 SSS/PhilHealth/
 * Pag-IBIG (assertRatesInForce only guards types that EXIST), no default work
 * schedule → zero scheduled workdays. The run completes and every payslip
 * looks ordinary. This class closes that gap at onboarding.
 *
 * The figures come from {@see PhStatutoryDefaults} — the canonical PH
 * defaults — never from another org's rows, so a tenant's start state cannot
 * depend on what some other tenant has since edited.
 *
 * IDEMPOTENT. Every step is firstOrCreate/exists-guarded on the org-scoped
 * unique keys, so calling it again (re-onboarding, adding the HR system to an
 * existing org, a retried job) never duplicates rows and never overwrites
 * values HR has since tuned.
 */
class HrProvisioner
{
    public function provisionFor(Org $org): void
    {
        CurrentOrg::runAs($org->id, function (): void {
            $this->taxTable();
            $this->contributions();
            $this->payrollSettings();
            $this->leaveDefaults();
            $this->workSchedules();
            $this->holidays();

            // The attendance-policy singleton row, with the shipped defaults
            // (all deduction switches at their no-op values). Resolved fresh
            // inside runAs so its per-instance memo cannot cross tenants.
            // AttendancePolicyService::asOf() falls back to this live row when
            // no version exists, so no opening version row is needed.
            app(AttendancePolicyService::class)->get();
        });
    }

    /**
     * The opening BIR withholding table. Without it TaxService::bandsAsOf()
     * returns [] and every payslip silently withholds ₱0.
     */
    private function taxTable(): void
    {
        $version = TaxVersion::firstOrCreate(
            ['effective_from' => PhStatutoryDefaults::OPENING_DATE],
            ['note' => PhStatutoryDefaults::TAX_NOTE],
        );

        // Only on first creation — a re-run must not duplicate bands, and must
        // not overwrite a table HR has since corrected on the tax screen.
        if ($version->brackets()->doesntExist()) {
            foreach (PhStatutoryDefaults::TAX_BRACKETS as [$min, $base, $rate]) {
                TaxBracket::create([
                    'tax_version_id' => $version->id,
                    'min_annual' => $min,
                    'base_tax' => $base,
                    'rate_on_excess' => $rate,
                ]);
            }
        }
    }

    /**
     * SSS / PhilHealth / Pag-IBIG: the active-type registry rows, the opening
     * (pre-2025) versions old cycles reprice against, and the current 2025
     * schedules — including the 61 published SSS MSC bands.
     */
    private function contributions(): void
    {
        foreach (PhStatutoryDefaults::CONTRIBUTION_SETTINGS as $type => $config) {
            ContributionSetting::firstOrCreate(
                ['type' => $type],
                ['config' => $config, 'is_active' => true],
            );

            ContributionVersion::firstOrCreate(
                ['type' => $type, 'effective_from' => PhStatutoryDefaults::OPENING_DATE],
                [
                    'basis' => 'percentage',
                    'employee_rate' => $config['rate'] ?? 0,
                    'employer_rate' => $config['employer_rate'] ?? 0,
                    'salary_floor' => $config['salary_floor'] ?? null,
                    'salary_cap' => $config['salary_cap'] ?? null,
                    'employee_amount_cap' => $config['cap'] ?? null,
                    'employer_amount_cap' => $config['employer_cap'] ?? null,
                    /*
                     * The Pag-IBIG low-income employee tier is DELIBERATELY not written.
                     * kps carries `low_income_threshold` / `low_income_employee_rate`
                     * columns; this repo has neither, and nothing here reads them, so
                     * writing them would fail on an unknown column and adding them would
                     * only create data no payroll path consults. If this repo's payroll
                     * ever adopts the tier, port 2026_08_19_000002 and restore these two.
                     */
                    'note' => $config['note'] ?? 'Opening version — provisioned defaults.',
                ],
            );
        }

        foreach (PhStatutoryDefaults::CONTRIBUTION_VERSIONS_2025 as $type => $attributes) {
            $version = ContributionVersion::firstOrCreate(
                ['type' => $type, 'effective_from' => PhStatutoryDefaults::CURRENT_DATE],
                $attributes,
            );

            // SSS is bracket-based: write the published bands, once. Guarded the
            // same way the seeder guards them — never duplicate, never replace a
            // table HR has since corrected through the import screen.
            if ($type === 'sss' && $version->brackets()->doesntExist()) {
                foreach (PhStatutoryDefaults::SSS_2025_BANDS as [$min, $msc, $ec]) {
                    ContributionBracket::create([
                        'contribution_version_id' => $version->id,
                        'min_salary' => $min,
                        'msc' => $msc,
                        'employer_add_on' => $ec,
                    ]);
                }
            }
        }
    }

    /**
     * The payroll-settings row (created with its shipped defaults, including the
     * statutory ₱90,000 13th-month exemption) and an OPENING version — without
     * one, PayrollSetting::asOf() falls back to the live row, which re-prices
     * old cycles whenever the settings change (the exact FIN-025 failure).
     */
    private function payrollSettings(): void
    {
        PayrollSetting::current();

        // Guarded on "no versions yet", not updateOrCreate: recordVersion would
        // otherwise overwrite a version HR has since amended for this date.
        if (PayrollSettingVersion::query()->doesntExist()) {
            PayrollSetting::recordVersion(PhStatutoryDefaults::OPENING_DATE);
        }
    }

    /**
     * The current year's PH holiday set, loaded from Nager.Date — without one,
     * every holiday premium silently prices at ₱0 until HR thinks to load it.
     *
     * BEST-EFFORT, unlike everything above: the dates come from a third-party
     * call, and onboarding must never fail (or hang an org half-provisioned)
     * because date.nager.at was down. If the fetch fails, nothing is created —
     * HR loads the set later from the Holidays screen, exactly as before.
     */
    private function holidays(): void
    {
        $year = now(config('app.timezone_local'))->year;

        // Idempotent: a re-run (or an org whose HR already loaded the year)
        // must not touch the existing set — createSet would refuse it anyway,
        // and that refusal must not be reported as a provisioning failure.
        if (HolidaySet::where('country_code', HolidayService::HOME_COUNTRY)->where('year', $year)->exists()) {
            return;
        }

        try {
            app(HolidayService::class)->createSet(HolidayService::HOME_COUNTRY, $year);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Leave types, their per-status starting allotments, and the leave-settings row. */
    private function leaveDefaults(): void
    {
        LeaveSetting::current();

        foreach (PhStatutoryDefaults::LEAVE_TYPES as $type) {
            $leaveType = LeaveType::firstOrCreate(['code' => $type['code']], $type);

            foreach (PhStatutoryDefaults::LEAVE_ALLOTMENT_FACTORS as $status => $factor) {
                // Allotments are whole days only — round to the nearest day.
                // firstOrCreate, NOT updateOrCreate: a re-run must not undo
                // per-status figures HR has tuned since onboarding.
                LeaveTypeAllotment::firstOrCreate(
                    ['leave_type_id' => $leaveType->id, 'employment_status' => $status],
                    ['days_per_year' => (int) round((float) $type['days_per_year'] * $factor)],
                );
            }
        }
    }

    /**
     * The default schedules. Without a default row every employee resolves to
     * no schedule at all — zero scheduled workdays, so payroll prices nothing.
     */
    private function workSchedules(): void
    {
        foreach (PhStatutoryDefaults::WORK_SCHEDULES as $schedule) {
            WorkSchedule::firstOrCreate(['name' => $schedule['name']], $schedule);
        }
    }
}
