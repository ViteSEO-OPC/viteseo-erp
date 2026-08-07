<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\PayrollSettingVersion;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * FIN-025 — payroll settings are effective-dated, so re-running an old cycle cannot reprice it.
 *
 * WHY THIS TEST EXISTS SEPARATELY. Slice 7 asks for effective-date tests across tax versions,
 * payroll settings and contribution versions. Two of those were already covered —
 * `TaxVersionTest::test_an_older_cycle_uses_the_older_table` and
 * `ContributionVersionTest::test_an_older_period_uses_the_older_rate`. Payroll settings were not:
 * `ScheduledPayDateTest` covers `pay_delay_days`, which affects the date printed on a payslip but
 * not a single peso of it. Nothing asserted that a setting which MOVES MONEY stays put.
 *
 * `deduction_timing` is the one chosen because it is the sharpest. Under `every`, SSS is split
 * across both cutoffs of a month; under `last`, the whole amount lands on the second one. Flip it
 * and re-run an earlier cutoff without dating, and that cutoff's statutory deduction changes — on a
 * payslip that has already been reviewed, for a period nothing about which has changed.
 */
class PayrollSettingsEffectiveDateTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $this->worked('2026-03-01', '2026-03-15');
    }

    private function worked(string $from, string $to): void
    {
        $tz = config('app.timezone_local');
        for ($d = Carbon::parse($from, $tz); $d->lte(Carbon::parse($to, $tz)); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }
            Attendance::create([
                'employee_id' => $this->employee->id, 'work_date' => $d->toDateString(),
                'time_in' => Carbon::parse("{$d->toDateString()} 08:00", $tz)->utc(),
                'time_out' => Carbon::parse("{$d->toDateString()} 17:00", $tz)->utc(),
                'status' => 'present', 'lunch_minutes' => 60, 'break_minutes' => 0,
                'source' => Attendance::SOURCE_MANUAL,
            ]);
        }
    }

    private function runMarchFirstHalf(): void
    {
        app(PayrollService::class)->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());
    }

    private function marchSss(): float
    {
        return (float) Payslip::where('employee_id', $this->employee->id)
            ->whereHas('payrollRun', fn ($q) => $q->where('cycle_start', '2026-03-01'))
            ->firstOrFail()
            ->sss;
    }

    /** @param array<string, mixed> $values */
    private function settingsEffective(string $date, array $values): void
    {
        PayrollSetting::effectiveFrom($date);
        PayrollSetting::current()->update($values);
    }

    // --- the assertion that was missing -----------------------------------------------------

    /**
     * THE ONE THAT MATTERS. Change a money-moving setting with a LATER effective date, re-run the
     * earlier cycle, and it must price exactly as it did.
     */
    public function test_a_later_setting_change_does_not_reprice_an_earlier_cycle(): void
    {
        $this->settingsEffective('2026-01-01', ['deduction_timing' => 'every']);
        $this->runMarchFirstHalf();

        $before = $this->marchSss();
        $this->assertGreaterThan(0, $before, 'precondition: the first cutoff carries a share of SSS');

        // A change from June onward — nothing to do with March.
        $this->settingsEffective('2026-06-01', ['deduction_timing' => 'last']);

        $this->runMarchFirstHalf();

        $this->assertSame($before, $this->marchSss(), 'the March cutoff repriced under a June setting');
    }

    /** And the mirror: a change dated BEFORE the cycle must apply to it. */
    public function test_a_setting_change_dated_before_the_cycle_does_apply(): void
    {
        $this->settingsEffective('2026-01-01', ['deduction_timing' => 'every']);
        $this->runMarchFirstHalf();
        $split = $this->marchSss();

        // Under `last`, the whole month's SSS lands on the SECOND cutoff — so the first carries none.
        $this->settingsEffective('2026-02-01', ['deduction_timing' => 'last']);
        $this->runMarchFirstHalf();

        $this->assertSame(0.0, $this->marchSss(), 'a change in force for March should have applied');
        $this->assertGreaterThan(0, $split, 'and the earlier setting genuinely differed');
    }

    // --- the mechanism the above depends on --------------------------------------------------

    /** Every write records a version. Without this the change would not take effect at all. */
    public function test_every_settings_write_records_a_version(): void
    {
        $before = PayrollSettingVersion::count();

        PayrollSetting::current()->update(['deduction_timing' => 'first']);

        $this->assertGreaterThan($before, PayrollSettingVersion::count());
    }

    /** Correcting a setting twice on one date amends that day's version rather than racing it. */
    public function test_two_changes_on_one_date_collapse_into_one_version(): void
    {
        $this->settingsEffective('2026-05-01', ['deduction_timing' => 'first']);
        $count = PayrollSettingVersion::whereDate('effective_from', '2026-05-01')->count();

        $this->settingsEffective('2026-05-01', ['deduction_timing' => 'last']);

        $this->assertSame($count, PayrollSettingVersion::whereDate('effective_from', '2026-05-01')->count());
        $this->assertSame('last', PayrollSetting::asOf('2026-05-02')->deduction_timing);
    }

    /** Resolution takes the latest version on or before the date, not the newest overall. */
    public function test_resolution_takes_the_latest_version_on_or_before_the_date(): void
    {
        $this->settingsEffective('2026-01-01', ['deduction_timing' => 'every']);
        $this->settingsEffective('2026-06-01', ['deduction_timing' => 'last']);

        $this->assertSame('every', PayrollSetting::asOf('2026-03-15')->deduction_timing);
        $this->assertSame('last', PayrollSetting::asOf('2026-07-15')->deduction_timing);
    }
}
