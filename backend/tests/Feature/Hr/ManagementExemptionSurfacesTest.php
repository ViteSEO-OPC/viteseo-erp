<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Models\JobRun;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\EarlyOutRequestService;
use App\Modules\Hr\Services\HalfDayRequestService;
use App\Modules\Hr\Services\ManagementAttendanceService;
use App\Modules\Hr\Services\MyAttendanceReportService;
use App\Modules\Hr\Services\OvertimeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What the exemption looks like from outside the generator: the screens that classify a day,
 * the request forms that no longer apply, and the job-visibility surface HR reads to find out
 * whether the nightly ran at all.
 */
class ManagementExemptionSurfacesTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Department $dept;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tz = config('app.timezone_local');
        // 2026-06-12 is a Friday AND Independence Day — a regular holiday on a workday.
        $this->travelTo(Carbon::parse('2026-06-17 12:00', $this->tz));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5], 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_minutes' => 0, 'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $this->dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->manager = $this->employee('M-001', true);

        $set = HolidaySet::create(['country_code' => 'PH', 'year' => 2026]);
        Holiday::create([
            'holiday_set_id' => $set->id, 'name' => 'Independence Day',
            'date' => '2026-06-12', 'type' => 'regular',
        ]);
    }

    private function employee(string $no, bool $isManagement): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->dept->id,
            'employee_no' => $no,
            'job_title' => $isManagement ? 'Head' : 'Staff',
            'hire_date' => '2020-01-01',
            'base_salary' => $isManagement ? 90000 : 30000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => $isManagement,
            'management_since' => $isManagement ? '2020-01-01' : null,
            'work_schedule_id' => WorkSchedule::first()->id,
        ]);
    }

    /** @return array<string, string> date => status, from the employee's own month report */
    private function myReportStatuses(Employee $employee): array
    {
        $report = app(MyAttendanceReportService::class)->build($employee, ['month' => '2026-06']);

        return collect($report['days'])->pluck('status', 'date')->all();
    }

    public function test_a_holiday_reads_present_before_the_nightly_has_written_anything(): void
    {
        $roster = collect(app(AttendanceService::class)->allForDate('2026-06-12'))
            ->firstWhere('employee_id', $this->manager->id);

        // The row does not exist yet — the job runs at 23:55 — so this is the derived answer,
        // and it has to match the one the generated row gives tomorrow.
        $this->assertSame('present', $roster['status']);
        $this->assertSame('present', $this->myReportStatuses($this->manager)['2026-06-12']);
    }

    public function test_a_holiday_still_reads_holiday_for_everyone_else(): void
    {
        $staff = $this->employee('E-001', false);

        $roster = collect(app(AttendanceService::class)->allForDate('2026-06-12'))
            ->firstWhere('employee_id', $staff->id);

        $this->assertSame('holiday', $roster['status']);
        $this->assertSame('holiday', $this->myReportStatuses($staff)['2026-06-12']);
    }

    public function test_the_days_before_a_promotion_keep_the_record_the_employee_built(): void
    {
        $promoted = $this->employee('E-002', false);
        $promoted->update(['is_management' => true, 'management_since' => '2026-06-15']);

        Attendance::create([
            'employee_id' => $promoted->id, 'work_date' => '2026-06-10',
            'time_in' => Carbon::parse('2026-06-10 09:30', $this->tz)->utc(),
            'time_out' => Carbon::parse('2026-06-10 17:00', $this->tz)->utc(),
            'hours_worked' => 6.5, 'status' => 'late', 'source' => Attendance::SOURCE_MANUAL,
        ]);

        $statuses = $this->myReportStatuses($promoted);

        $this->assertSame('late', $statuses['2026-06-10'], 'A late before the promotion is still a late.');
        $this->assertSame('absent', $statuses['2026-06-11'], 'So is a day they simply did not come in.');
        $this->assertSame('present', $statuses['2026-06-15'], 'From the promotion onward they are exempt.');
    }

    public function test_the_generator_will_not_reach_back_past_the_promotion(): void
    {
        $promoted = $this->employee('E-003', false);
        $promoted->update(['is_management' => true, 'management_since' => '2026-06-15']);

        // The nightly covers a week, which here spans the promotion.
        app(ManagementAttendanceService::class)->generate('2026-06-08', '2026-06-16');

        $dates = Attendance::where('employee_id', $promoted->id)->pluck('work_date')
            ->map(fn ($d) => $d->toDateString())->all();

        $this->assertSame(['2026-06-15', '2026-06-16'], $dates);
    }

    public function test_the_request_forms_that_no_longer_apply_are_refused(): void
    {
        $overtime = app(OvertimeRequestService::class);
        $earlyOut = app(EarlyOutRequestService::class);
        $halfDay = app(HalfDayRequestService::class);

        $cases = [
            fn () => $overtime->create($this->manager, ['work_date' => '2026-06-16', 'time_from' => '18:00', 'time_to' => '20:00', 'reason' => 'Release']),
            fn () => $earlyOut->create($this->manager, ['work_date' => '2026-06-16', 'early_out_time' => '15:00', 'reason' => 'Clinic']),
            fn () => $halfDay->create($this->manager, ['work_date' => '2026-06-16', 'period' => 'AM', 'reason' => 'Clinic']),
        ];

        foreach ($cases as $i => $file) {
            try {
                $file();
                $this->fail("Case {$i}: management should not be able to file this.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('exempt from attendance', json_encode($e->errors()));
            }
        }
    }

    public function test_hr_cannot_hand_edit_a_generated_day_but_can_still_fix_a_pre_promotion_one(): void
    {
        $promoted = $this->employee('E-004', false);
        $promoted->update(['is_management' => true, 'management_since' => '2026-06-15']);

        $service = app(AttendanceService::class);

        // Before the promotion: an ordinary correction, still HR's to make.
        $fixed = $service->adjust($promoted, '2026-06-10', ['time_in' => '08:00', 'time_out' => '17:00']);
        $this->assertSame('present', $fixed->status);

        // After it: the day is generated, not entered.
        $this->expectException(ValidationException::class);
        $service->adjust($promoted, '2026-06-16', ['time_in' => '08:00', 'time_out' => '17:00']);
    }

    public function test_a_run_records_itself_and_a_silent_cron_reads_as_stale(): void
    {
        $service = app(ManagementAttendanceService::class);

        $this->assertTrue($service->status()['is_stale'], 'Never run at all is the loudest case.');
        $this->assertNull($service->status()['last_run']);

        $service->run(from: '2026-06-15', to: '2026-06-16');

        $status = $service->status();

        $this->assertFalse($status['is_stale']);
        $this->assertSame(1, $status['employees']);
        $this->assertSame('2026-06-15', $status['last_run']['range_start']);
        $this->assertSame(2, $status['last_run']['created']);
        $this->assertNull($status['last_run']['triggered_by'], 'Nobody pressed a button — this was the scheduler.');

        // Two days later with no further run, the nightly has clearly missed a night.
        $this->travel(2)->days();
        $this->assertTrue($service->status()['is_stale']);
    }

    public function test_a_dry_run_is_not_recorded_as_a_run(): void
    {
        app(ManagementAttendanceService::class)->run(from: '2026-06-15', to: '2026-06-16', dryRun: true);

        $this->assertSame(0, JobRun::count());
    }

    public function test_hr_can_read_the_job_status_and_run_it_from_the_api(): void
    {
        $this->actingAsHr()
            ->getJson('/api/v1/hr/attendance/management-generation')
            ->assertOk()
            ->assertJsonPath('data.is_stale', true)
            ->assertJsonPath('data.employees', 1);

        $this->actingAsHr()
            ->postJson('/api/v1/hr/attendance/management-generation', ['from' => '2026-06-15', 'to' => '2026-06-16'])
            ->assertOk()
            ->assertJsonPath('data.stats.created', 2)
            ->assertJsonPath('data.status.is_stale', false);

        // The button names its presser, so an unexpected row is traceable to a person.
        $this->assertNotNull(JobRun::latest('id')->first()->triggered_by);
    }

    public function test_the_regenerate_range_is_capped(): void
    {
        $this->actingAsHr()
            ->postJson('/api/v1/hr/attendance/management-generation', ['from' => '2020-01-01'])
            ->assertStatus(422);
    }
}
