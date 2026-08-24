<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Models\Violation;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\HalfDayRequestService;
use App\Modules\Hr\Services\ManagementAttendanceService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\ViolationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * The two Company → Settings switches behind the management exemption:
 *
 *  - `management_auto_attendance` — off, the exemption stops existing and management uses the
 *    time clock like everyone else. Their PAY is fixed either way.
 *  - `management_holiday_premium` — on, a holiday they worked pays the statutory premium.
 */
class ManagementCompanyRulesTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tz = config('app.timezone_local');
        $this->travelTo(Carbon::parse('2026-03-16 12:00', $this->tz));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5], 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_minutes' => 0, 'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:00',
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->manager = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $dept->id,
            'employee_no' => 'M-001', 'job_title' => 'Head', 'hire_date' => '2020-01-01',
            'base_salary' => 90000, 'pay_frequency' => 'semi_monthly',
            'is_management' => true, 'management_since' => '2020-01-01',
            'work_schedule_id' => WorkSchedule::first()->id,
        ]);
    }

    /**
     * Write the rule straight to the row. Each `app(...)` below resolves a fresh service (and
     * so a fresh policy memo), which is exactly how a later HTTP request would read it.
     */
    private function setRule(string $field, bool $value): void
    {
        app(AttendancePolicyService::class)->get(); // the singleton row exists from here on
        AttendancePolicy::query()->update([$field => $value]);
    }

    private function holiday(string $date, string $type = 'regular'): void
    {
        $set = HolidaySet::firstOrCreate(['country_code' => 'PH', 'year' => 2026], ['source' => 'manual']);
        Holiday::create([
            'holiday_set_id' => $set->id, 'name' => 'Test Holiday',
            'date' => $date, 'type' => $type, 'source' => 'manual',
        ]);
    }

    private function worked(string $date): void
    {
        Attendance::create([
            'employee_id' => $this->manager->id, 'work_date' => $date,
            'time_in' => Carbon::parse("{$date} 08:00", $this->tz)->utc(),
            'time_out' => Carbon::parse("{$date} 17:00", $this->tz)->utc(),
            'hours_worked' => 8, 'status' => 'present', 'lunch_minutes' => 60,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    // --- auto time in / out -----------------------------------------------------------

    public function test_with_the_rule_on_management_cannot_clock_in(): void
    {
        $this->expectExceptionMessage('exempt from attendance');

        app(AttendanceService::class)->clockIn($this->manager);
    }

    public function test_with_the_rule_off_management_clocks_in_like_everyone_else(): void
    {
        $this->setRule('management_auto_attendance', false);

        $row = app(AttendanceService::class)->clockIn($this->manager->fresh());

        $this->assertNotNull($row->time_in);
        $this->assertNotSame(Attendance::SOURCE_SYSTEM, $row->source, 'They punched it themselves.');
    }

    public function test_with_the_rule_off_the_generator_writes_nothing(): void
    {
        $this->setRule('management_auto_attendance', false);

        $stats = app(ManagementAttendanceService::class)->generate('2026-03-09', '2026-03-13');

        $this->assertSame(0, $stats['created']);
        $this->assertStringContainsString('switched off', $stats['notes'][0]);
        $this->assertSame(0, Attendance::count());
    }

    public function test_with_the_rule_off_a_missing_day_is_an_absence_again(): void
    {
        $this->setRule('management_auto_attendance', false);

        $roster = collect(app(AttendanceService::class)->allForDate('2026-03-11'))
            ->firstWhere('employee_id', $this->manager->id);

        $this->assertSame('absent', $roster['status']);

        app(ViolationService::class)->recompute('2026-03');

        $this->assertGreaterThan(0, Violation::where('employee_id', $this->manager->id)->count());
    }

    public function test_with_the_rule_off_they_may_file_the_requests_again(): void
    {
        $this->setRule('management_auto_attendance', false);

        $req = app(HalfDayRequestService::class)->create($this->manager->fresh(), [
            'work_date' => '2026-03-17', 'period' => 'AM', 'reason' => 'Clinic',
        ]);

        $this->assertSame('pending', $req->status);
    }

    public function test_the_rule_never_changes_management_pay(): void
    {
        $this->setRule('management_auto_attendance', false);

        // A whole week absent, with the exemption off.
        $unpaid = app(AttendanceService::class)
            ->unpaidDaysFor($this->manager->fresh(), '2026-03-02', '2026-03-06');

        $this->assertSame(0.0, $unpaid, 'Management is salaried on a fixed basis either way.');
    }

    // --- holiday premium --------------------------------------------------------------

    public function test_by_default_a_worked_holiday_pays_management_no_premium(): void
    {
        $this->holiday('2026-03-11');
        $this->worked('2026-03-11');

        $run = app(PayrollService::class)->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());
        $slip = $run->payslips()->firstOrFail();

        $this->assertSame(0.0, (float) $slip->holiday_premium);
    }

    public function test_with_the_premium_rule_on_a_worked_holiday_pays_management_too(): void
    {
        $this->holiday('2026-03-11');
        $this->worked('2026-03-11');
        $this->setRule('management_holiday_premium', true);

        $run = app(PayrollService::class)->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());
        $slip = $run->payslips()->firstOrFail();

        // A regular holiday on a workday pays the +100% add-on: one daily rate on top.
        $this->assertSame(round((float) $slip->daily_rate, 2), (float) $slip->holiday_premium);
    }

    public function test_the_premium_rule_does_not_leak_into_the_other_premiums(): void
    {
        $this->holiday('2026-03-11');
        $this->worked('2026-03-11');
        // A Sunday — a rest day they also worked, and a late arrival on a workday.
        Attendance::create([
            'employee_id' => $this->manager->id, 'work_date' => '2026-03-08',
            'time_in' => Carbon::parse('2026-03-08 08:00', $this->tz)->utc(),
            'time_out' => Carbon::parse('2026-03-08 20:00', $this->tz)->utc(),
            'hours_worked' => 11, 'status' => 'present', 'lunch_minutes' => 60,
            'source' => Attendance::SOURCE_MANUAL,
        ]);
        $this->setRule('management_holiday_premium', true);

        $run = app(PayrollService::class)->run('2026-03-01', '2026-03-15', 'semi_monthly', User::factory()->create());
        $slip = $run->payslips()->firstOrFail();

        $this->assertGreaterThan(0, (float) $slip->holiday_premium);
        $this->assertSame(0.0, (float) $slip->rest_day_premium, 'Only the holiday rule was switched on.');
        $this->assertSame(0.0, (float) $slip->overtime_pay);
        $this->assertSame(0.0, (float) $slip->night_diff_pay);
    }

    // --- the API surface ---------------------------------------------------------------

    public function test_hr_can_read_and_save_both_rules(): void
    {
        $this->actingAsHr()
            ->getJson('/api/v1/hr/attendance-policy')
            ->assertOk()
            ->assertJsonPath('data.management_auto_attendance', true)
            ->assertJsonPath('data.management_holiday_premium', false);

        $this->actingAsHr()
            ->patchJson('/api/v1/hr/attendance-policy', [
                'management_auto_attendance' => false,
                'management_holiday_premium' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.management_auto_attendance', false)
            ->assertJsonPath('data.management_holiday_premium', true);
    }
}
