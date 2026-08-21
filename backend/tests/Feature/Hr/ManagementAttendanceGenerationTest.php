<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\ManagementAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * The generator behind the management attendance exemption. Management never punches, so
 * these rows ARE their attendance record — every boundary it gets wrong (a notice period,
 * a half day, a day they were never employed for) is a wrong record nobody can correct by
 * clocking in.
 */
class ManagementAttendanceGenerationTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $manager;

    private Department $dept;

    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tz = config('app.timezone_local');

        // Frozen clock: mid-June 2026, so 06-01..06-16 are all past days.
        $this->travelTo(Carbon::parse('2026-06-17 12:00', $this->tz));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5], 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_minutes' => 0, 'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
            'am_start_time' => '08:00', 'am_end_time' => '12:00',
            'pm_start_time' => '13:00', 'pm_end_time' => '17:00',
        ]);

        $this->dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->manager = $this->manager('M-001');
    }

    private function manager(string $no, array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'department_id' => $this->dept->id,
            'employee_no' => $no,
            'job_title' => 'Head',
            'hire_date' => '2020-01-01',
            'base_salary' => 90000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => true,
            'work_schedule_id' => WorkSchedule::first()->id,
        ], $attributes));
    }

    private function generate(string $from = '2026-06-01', string $to = '2026-06-16', bool $dryRun = false): array
    {
        return app(ManagementAttendanceService::class)->generate($from, $to, $dryRun);
    }

    /** @return array<string, Attendance> work_date => row */
    private function rowsFor(Employee $employee): array
    {
        return Attendance::where('employee_id', $employee->id)
            ->orderBy('work_date')
            ->get()
            ->keyBy(fn (Attendance $r) => $r->work_date->toDateString())
            ->all();
    }

    private function localTime(?Carbon $utc): ?string
    {
        return $utc?->copy()->setTimezone($this->tz)->format('Y-m-d H:i');
    }

    public function test_it_writes_one_present_row_per_scheduled_workday(): void
    {
        $stats = $this->generate();

        $rows = $this->rowsFor($this->manager);

        // 06-01..06-16 holds 12 weekdays; the four weekend days are not workdays.
        $this->assertCount(12, $rows);
        $this->assertSame(12, $stats['created']);
        $this->assertArrayNotHasKey('2026-06-06', $rows, 'Saturday is not a workday.');

        $monday = $rows['2026-06-01'];
        $this->assertSame('present', $monday->status);
        $this->assertSame(Attendance::SOURCE_SYSTEM, $monday->source);
        $this->assertSame('2026-06-01 08:00', $this->localTime($monday->time_in));
        $this->assertSame('2026-06-01 17:00', $this->localTime($monday->time_out));
        $this->assertSame(60, $monday->lunch_minutes);
        $this->assertSame(8.0, (float) $monday->hours_worked, 'A 9h span less the unpaid hour.');
    }

    public function test_it_keeps_generating_through_a_notice_period_and_stops_after_the_last_day(): void
    {
        // HR files the separation on 06-02 with a last working day of 06-10 — which
        // soft-deletes the employee immediately. The days they are still coming in for
        // must keep generating; the days after their last one must not.
        $leaving = $this->manager('M-002', ['separation_date' => '2026-06-10', 'separation_type' => 'resigned']);
        $leaving->delete();
        Employee::withTrashed()->whereKey($leaving->id)->update([
            'deleted_at' => Carbon::parse('2026-06-10', $this->tz)->startOfDay(),
        ]);

        $this->generate();

        $dates = array_keys($this->rowsFor($leaving));

        $this->assertContains('2026-06-10', $dates, 'Their last working day is still a working day.');
        $this->assertContains('2026-06-05', $dates, 'The notice period is still worked.');
        $this->assertSame([], array_filter($dates, fn ($d) => $d > '2026-06-10'));
    }

    public function test_it_skips_days_before_the_hire_date(): void
    {
        $newHire = $this->manager('M-003', ['hire_date' => '2026-06-08']);

        $this->generate();

        $dates = array_keys($this->rowsFor($newHire));

        $this->assertSame('2026-06-08', $dates[0]);
        $this->assertSame([], array_filter($dates, fn ($d) => $d < '2026-06-08'));
    }

    public function test_a_half_day_leaves_the_other_half_as_a_worked_half_day(): void
    {
        $type = LeaveType::create(['name' => 'Vacation', 'code' => 'VL', 'is_paid' => true]);

        // PM off by leave on Wednesday → the MORNING is still worked.
        LeaveRequest::create([
            'employee_id' => $this->manager->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-06-03', 'end_date' => '2026-06-03',
            'is_half_day' => true, 'half_day_period' => 'PM', 'days' => 0.5,
            'reason' => 'Clinic', 'status' => 'approved',
        ]);

        // AM off by half-day REQUEST on Thursday → the AFTERNOON is still worked.
        HalfDayRequest::create([
            'employee_id' => $this->manager->id, 'work_date' => '2026-06-04',
            'period' => 'AM', 'reason' => 'Personal', 'status' => 'approved',
        ]);

        $this->generate();

        $rows = $this->rowsFor($this->manager);

        $morning = $rows['2026-06-03'];
        $this->assertSame('2026-06-03 08:00', $this->localTime($morning->time_in));
        $this->assertSame('2026-06-03 12:00', $this->localTime($morning->time_out));
        $this->assertSame(0, $morning->lunch_minutes, 'The meal break sits between the halves.');
        $this->assertSame(4.0, (float) $morning->hours_worked);
        $this->assertSame('present', $morning->status);

        $afternoon = $rows['2026-06-04'];
        $this->assertSame('2026-06-04 13:00', $this->localTime($afternoon->time_in));
        $this->assertSame('2026-06-04 17:00', $this->localTime($afternoon->time_out));
        $this->assertSame(4.0, (float) $afternoon->hours_worked);
    }

    public function test_a_whole_day_off_produces_no_row_however_it_was_filed(): void
    {
        $type = LeaveType::create(['name' => 'Vacation', 'code' => 'VL', 'is_paid' => true]);

        LeaveRequest::create([
            'employee_id' => $this->manager->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-06-08', 'end_date' => '2026-06-09',
            'days' => 2, 'reason' => 'Out of town', 'status' => 'approved',
        ]);

        // Both halves filed off separately is the same whole day.
        foreach (['AM', 'PM'] as $period) {
            HalfDayRequest::create([
                'employee_id' => $this->manager->id, 'work_date' => '2026-06-10',
                'period' => $period, 'reason' => 'Personal', 'status' => 'approved',
            ]);
        }

        $this->generate();

        $rows = $this->rowsFor($this->manager);

        $this->assertArrayNotHasKey('2026-06-08', $rows);
        $this->assertArrayNotHasKey('2026-06-09', $rows);
        $this->assertArrayNotHasKey('2026-06-10', $rows);
    }

    public function test_a_holiday_is_still_a_worked_day_for_management(): void
    {
        $set = HolidaySet::create(['country_code' => 'PH', 'year' => 2026]);
        Holiday::create([
            'holiday_set_id' => $set->id, 'name' => 'Independence Day',
            'date' => '2026-06-12', 'type' => 'regular',
        ]);

        $this->generate();

        // Management is timed in on a holiday like any other workday — the premium is what
        // they do not get, and that is priced in payroll, not here.
        $this->assertArrayHasKey('2026-06-12', $this->rowsFor($this->manager));
    }

    public function test_it_never_touches_a_row_a_person_put_there(): void
    {
        $manual = Attendance::create([
            'employee_id' => $this->manager->id, 'work_date' => '2026-06-01',
            'time_in' => Carbon::parse('2026-06-01 10:00', $this->tz)->utc(),
            'time_out' => Carbon::parse('2026-06-01 15:00', $this->tz)->utc(),
            'hours_worked' => 5, 'status' => 'late', 'source' => Attendance::SOURCE_MANUAL,
        ]);

        // A deleted row is a deliberate act, and its (employee, work_date) key is still taken.
        $deleted = Attendance::create([
            'employee_id' => $this->manager->id, 'work_date' => '2026-06-02',
            'hours_worked' => 0, 'status' => 'absent', 'source' => Attendance::SOURCE_SYSTEM,
        ]);
        $deleted->delete();

        $this->generate();

        $manual->refresh();
        $this->assertSame('late', $manual->status);
        $this->assertSame('2026-06-01 10:00', $this->localTime($manual->time_in));
        $this->assertSoftDeleted('hr_attendance', ['id' => $deleted->id]);
    }

    public function test_a_second_run_refreshes_rather_than_duplicates(): void
    {
        $this->generate();

        $second = $this->generate();

        $this->assertSame(0, $second['created']);
        $this->assertSame(12, $second['updated']);
        $this->assertCount(12, $this->rowsFor($this->manager));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $stats = $this->generate(dryRun: true);

        $this->assertSame(12, $stats['created']);
        $this->assertSame(0, Attendance::where('employee_id', $this->manager->id)->count());
    }

    public function test_an_overnight_shift_ends_the_next_morning(): void
    {
        $night = $this->manager('M-004', [
            'custom_start_time' => '22:00',
            'custom_end_time' => '06:00',
        ]);

        $this->generate();

        $row = $this->rowsFor($night)['2026-06-01'];

        $this->assertSame('2026-06-01 22:00', $this->localTime($row->time_in));
        $this->assertSame('2026-06-02 06:00', $this->localTime($row->time_out));
        $this->assertSame(7.0, (float) $row->hours_worked, 'An 8h span less the unpaid hour.');
    }

    public function test_a_half_day_whose_window_is_unconfigured_is_skipped_with_a_note(): void
    {
        // A schedule with no AM/PM split: the full day is known, the halves are not. Guessing
        // one would invent hours nobody worked, so the day is skipped and reported instead.
        $schedule = WorkSchedule::create([
            'name' => 'No halves', 'workdays' => [1, 2, 3, 4, 5], 'start_time' => '08:00', 'end_time' => '17:00',
            'grace_minutes' => 0, 'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0,
        ]);
        $unsplit = $this->manager('M-005', ['work_schedule_id' => $schedule->id]);

        HalfDayRequest::create([
            'employee_id' => $unsplit->id, 'work_date' => '2026-06-01',
            'period' => 'AM', 'reason' => 'Personal', 'status' => 'approved',
        ]);

        $stats = $this->generate();

        $rows = $this->rowsFor($unsplit);

        $this->assertArrayNotHasKey('2026-06-01', $rows);
        $this->assertArrayHasKey('2026-06-02', $rows, 'Their ordinary days still generate.');
        $this->assertNotEmpty($stats['notes']);
        $this->assertStringContainsString("Employee #{$unsplit->id}", $stats['notes'][0]);
        $this->assertStringContainsString('2026-06-01', $stats['notes'][0]);
    }
}
