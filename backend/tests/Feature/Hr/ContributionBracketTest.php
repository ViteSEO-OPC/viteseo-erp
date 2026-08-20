<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** SSS charges on the bracket's Monthly Salary Credit, not on actual salary. */
class ContributionBracketTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    /** A cut-down MSC table: enough bands to prove the lookup without transcribing all 30+. */
    private const BANDS = [
        ['min_salary' => 0, 'msc' => 5000],
        ['min_salary' => 5250, 'msc' => 5500],
        ['min_salary' => 19750, 'msc' => 20000],
        ['min_salary' => 20250, 'msc' => 20500],
        ['min_salary' => 34750, 'msc' => 35000],
    ];

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
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /** @param array<int, array<string, float>> $bands */
    private function bracketVersion(array $bands = self::BANDS): ContributionVersion
    {
        $version = ContributionVersion::create([
            'type' => 'sss', 'effective_from' => '2026-01-01', 'basis' => 'bracket',
            'employee_rate' => 0.05, 'employer_rate' => 0.10,
        ]);
        $version->brackets()->createMany($bands);

        return $version;
    }

    private function sssFor(float $salary): float
    {
        $this->employee->update(['base_salary' => $salary]);

        return app(PayrollService::class)->payslipFor(
            employee: $this->employee->fresh(),
            reimb: 0.0, unpaidDays: 0.0, periodsPerYear: 24, workingDaysInPeriod: 11,
            // count: 1 puts the whole monthly contribution on the slip, so the figures below
            // read as the published monthly amounts.
            dist: ['index' => 0, 'count' => 1, 'deduction_timing' => 'every', 'tax_timing' => 'every', 'cycle_end' => '2026-03-15'],
        )['sss'];
    }

    /** THE POINT: a salary between bands is charged on the band's MSC, not on itself. */
    public function test_a_salary_inside_a_band_is_charged_on_the_bands_msc(): void
    {
        $this->bracketVersion();

        // ₱20,100 sits in the band starting at ₱19,750, whose MSC is ₱20,000.
        $this->assertSame(1000.0, $this->sssFor(20100), '5% of the ₱20,000 MSC, not of ₱20,100');
        // Percentage-of-salary would have charged 5% of ₱20,100 = ₱1,005.
        $this->assertNotSame(1005.0, $this->sssFor(20100));
    }

    public function test_each_band_is_charged_on_its_own_msc(): void
    {
        $this->bracketVersion();

        $this->assertSame(250.0, $this->sssFor(1000), 'lowest band → MSC ₱5,000');
        $this->assertSame(275.0, $this->sssFor(6000), 'MSC ₱5,500');
        $this->assertSame(1025.0, $this->sssFor(20300), 'MSC ₱20,500');
    }

    /** A salary exactly on a boundary belongs to the band that starts there. */
    public function test_a_salary_on_a_boundary_takes_the_higher_band(): void
    {
        $this->bracketVersion();

        $this->assertSame(1000.0, $this->sssFor(19750), 'the ₱19,750 band, MSC ₱20,000');
        $this->assertSame(1025.0, $this->sssFor(20250), 'the next band up');
    }

    /** Above the top band, the top MSC applies — it is the ceiling. */
    public function test_above_the_top_band_the_top_msc_applies(): void
    {
        $this->bracketVersion();

        $this->assertSame(1750.0, $this->sssFor(95000), '5% of the ₱35,000 top MSC');
    }

    /** An unfinished table must not silently wipe the deduction. */
    public function test_a_bracket_version_with_no_bands_falls_back_to_percentage(): void
    {
        ContributionVersion::create([
            'type' => 'sss', 'effective_from' => '2026-01-01', 'basis' => 'bracket',
            'employee_rate' => 0.05, 'employer_rate' => 0.10, 'salary_cap' => 35000,
        ]);

        $this->assertSame(1000.0, $this->sssFor(20000), 'percentage of salary, not ₱0');
    }

    /** Bands belong to a version, so they are date-effective for free. */
    public function test_an_older_period_uses_the_older_table(): void
    {
        $this->bracketVersion();
        $this->employee->update(['base_salary' => 20100]);

        $slip = fn ($end) => app(PayrollService::class)->payslipFor(
            employee: $this->employee->fresh(),
            reimb: 0.0, unpaidDays: 0.0, periodsPerYear: 24, workingDaysInPeriod: 11,
            dist: ['index' => 0, 'count' => 1, 'deduction_timing' => 'every', 'tax_timing' => 'every', 'cycle_end' => $end],
        )['sss'];

        $this->assertSame(1000.0, $slip('2026-03-15'), 'the bracket table');
        // 2024 predates BOTH this table and the published 2025 schedule the seeder carries,
        // so the opening percentage version (4.5%, ₱30k cap) still applies.
        $this->assertSame(904.5, $slip('2024-06-30'), '4.5% of ₱20,100');
    }

    // --- the endpoint ----------------------------------------------------------------

    public function test_hr_can_import_a_bracket_table(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'sss', 'effective_from' => '2026-01-01', 'basis' => 'bracket',
            'employee_rate' => 0.05, 'employer_rate' => 0.10,
            'brackets' => self::BANDS,
        ])->assertCreated();

        $this->assertSame(1000.0, $this->sssFor(20100));
    }

    /** Re-importing replaces the table wholesale — stale bands must not survive. */
    public function test_re_importing_replaces_the_whole_table(): void
    {
        $payload = [
            'type' => 'sss', 'effective_from' => '2026-01-01', 'basis' => 'bracket',
            'employee_rate' => 0.05, 'employer_rate' => 0.10,
        ];
        $this->hrPost('/api/v1/hr/contribution-versions', [...$payload, 'brackets' => self::BANDS])->assertCreated();

        // A shorter table: one band covering everything.
        $this->hrPost('/api/v1/hr/contribution-versions', [
            ...$payload, 'brackets' => [['min_salary' => 0, 'msc' => 10000]],
        ])->assertCreated();

        // This test's own version, by date: the seeder also ships a bracket version now, so
        // "the only bracket one" is no longer a way to find it.
        $version = ContributionVersion::where('type', 'sss')
            ->whereDate('effective_from', '2026-01-01')->firstOrFail();
        $this->assertSame(1, $version->brackets()->count());
        $this->assertSame(500.0, $this->sssFor(20100), 'the single ₱10,000 band');
    }

    public function test_a_bracket_version_without_bands_is_rejected(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'sss', 'effective_from' => '2026-01-01', 'basis' => 'bracket',
            'employee_rate' => 0.05, 'employer_rate' => 0.10,
        ])->assertStatus(422)->assertJsonValidationErrors('brackets');
    }

    /** Bands must start at 0 or the lowest earners fall through with no contribution. */
    public function test_a_table_not_starting_at_zero_is_rejected(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'sss', 'effective_from' => '2026-01-01', 'basis' => 'bracket',
            'employee_rate' => 0.05, 'employer_rate' => 0.10,
            'brackets' => [['min_salary' => 5000, 'msc' => 5000]],
        ])->assertStatus(422)->assertJsonValidationErrors('brackets');
    }

    public function test_duplicate_band_starts_are_rejected(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'sss', 'effective_from' => '2026-01-01', 'basis' => 'bracket',
            'employee_rate' => 0.05, 'employer_rate' => 0.10,
            'brackets' => [['min_salary' => 0, 'msc' => 5000], ['min_salary' => 0, 'msc' => 6000]],
        ])->assertStatus(422)->assertJsonValidationErrors('brackets');
    }

    /** Bands on a percentage version would never be read — say so rather than store them. */
    public function test_bands_on_a_percentage_version_are_rejected(): void
    {
        $this->hrPost('/api/v1/hr/contribution-versions', [
            'type' => 'philhealth', 'effective_from' => '2026-01-01',
            'employee_rate' => 0.025, 'employer_rate' => 0.025,
            'brackets' => self::BANDS,
        ])->assertStatus(422)->assertJsonValidationErrors('brackets');
    }
}
