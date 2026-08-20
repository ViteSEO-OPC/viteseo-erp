<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/** The stale-attendance review queue (item 6). */
class OpenShiftReviewTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-20 12:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 0,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);

        $department = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $department->id,
            'employee_no' => 'E-OSR-100',
            'job_title' => 'Agent',
            'hire_date' => '2020-01-01',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);
    }

    /** An open punch: clocked in at $in Manila on $date, never out. */
    private function openPunch(string $date, string $in, ?Employee $employee = null): Attendance
    {
        return Attendance::create([
            'employee_id' => ($employee ?? $this->employee)->id,
            'work_date' => $date,
            'time_in' => Carbon::parse("{$date} {$in}", config('app.timezone_local'))->utc(),
            'status' => 'present',
        ]);
    }

    /** @return array<string, mixed>|null the queue row for a date, if it is listed at all */
    private function rowFor(string $date, ?string $from = null, ?string $to = null): ?array
    {
        $rows = app(AttendanceService::class)->openShiftReview($from ?? '2026-08-01', $to ?? '2026-08-31');

        foreach ($rows as $row) {
            if ($row['work_date'] === $date) {
                return $row;
            }
        }

        return null;
    }

    // --- the auto-closed half: pay settled, record untidy ---

    public function test_a_forgotten_punch_on_a_workday_is_listed_as_already_paid(): void
    {
        // Mon 2026-08-03, schedule 08:00–17:00.
        $this->openPunch('2026-08-03', '08:00');

        $row = $this->rowFor('2026-08-03');

        $this->assertNotNull($row);
        $this->assertSame('auto_closed', $row['status']);
        $this->assertSame('auto_closed', $row['reason']);
        // The queue reports the same end payroll used, so HR can see the day is already correct
        // rather than guessing at it.
        $this->assertSame('17:00', $row['resolved_time_out']);
        $this->assertFalse($row['resolved_next_day']);
        $this->assertNull($row['approved_overtime_hours']);
    }

    public function test_an_overnight_shift_reports_its_end_as_falling_the_next_day(): void
    {
        WorkSchedule::firstOrFail()->update(['start_time' => '22:00', 'end_time' => '06:00']);
        $this->openPunch('2026-08-03', '22:00');

        $row = $this->rowFor('2026-08-03');

        $this->assertNotNull($row);
        $this->assertSame('auto_closed', $row['status']);
        // 06:00 of the FOLLOWING morning. Without the flag the table would read "in 22:00, out
        // 06:00" and look like an eight-hour shift run backwards.
        $this->assertSame('06:00', $row['resolved_time_out']);
        $this->assertTrue($row['resolved_next_day']);
    }

    // --- the needs-fixing half: pay could not be worked out ---

    public function test_a_punch_on_a_rest_day_needs_fixing(): void
    {
        // Sun 2026-08-02 — not in the schedule's workdays, so there is no end to close against.
        $this->openPunch('2026-08-02', '09:00');

        $row = $this->rowFor('2026-08-02');

        $this->assertNotNull($row);
        $this->assertSame('needs_fix', $row['status']);
        $this->assertSame('rest_day', $row['reason']);
        $this->assertNull($row['resolved_time_out']);
    }

    public function test_a_punch_after_the_employees_own_shift_end_needs_fixing(): void
    {
        // In at 19:00 against an 08:00–17:00 day: no coherent shift can be derived from it.
        $this->openPunch('2026-08-04', '19:00');

        $row = $this->rowFor('2026-08-04');

        $this->assertNotNull($row);
        $this->assertSame('needs_fix', $row['status']);
        $this->assertSame('in_after_end', $row['reason']);
        $this->assertNull($row['resolved_time_out']);
    }

    public function test_an_employee_with_no_schedule_at_all_needs_fixing(): void
    {
        WorkSchedule::query()->delete(); // nothing left for the resolver to fall back to
        $this->openPunch('2026-08-03', '08:00');

        $row = $this->rowFor('2026-08-03');

        $this->assertNotNull($row);
        $this->assertSame('needs_fix', $row['status']);
        $this->assertSame('no_schedule', $row['reason']);
        $this->assertNull($row['scheduled_end']);
    }

    /** The one case where the engine is confident and a human still disagrees with it. */
    public function test_an_approved_overtime_request_promotes_an_auto_closed_day(): void
    {
        $this->openPunch('2026-08-03', '08:00');
        OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-03',
            'time_from' => '17:00',
            'time_to' => '20:00',
            'hours' => 3,
            'reason' => 'Release cutover',
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);

        $row = $this->rowFor('2026-08-03');

        $this->assertNotNull($row);
        $this->assertSame('needs_fix', $row['status']);
        // The reason still reports what the ENGINE did — the day IS priced. Only the status is
        // promoted, so the UI can say "paid to 17:00, but 3h were approved".
        $this->assertSame('auto_closed', $row['reason']);
        $this->assertSame('17:00', $row['resolved_time_out']);
        $this->assertSame(3.0, $row['approved_overtime_hours']);
    }

    public function test_a_cancelled_overtime_approval_does_not_promote_the_day(): void
    {
        $this->openPunch('2026-08-03', '08:00');
        OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-03',
            'time_from' => '17:00',
            'time_to' => '20:00',
            'hours' => 3,
            'reason' => 'Release cutover',
            'status' => 'approved',
            'reviewed_at' => now(),
            // HR already withdrew the permission, so there is no contradiction left to settle.
            'cancelled_at' => now(),
        ]);

        $row = $this->rowFor('2026-08-03');

        $this->assertNotNull($row);
        $this->assertSame('auto_closed', $row['status']);
        $this->assertNull($row['approved_overtime_hours']);
    }

    // --- what the queue deliberately leaves out ---

    public function test_a_shift_still_inside_its_allowance_is_not_listed(): void
    {
        // 17:00 end + the default 180-minute allowance = a 20:00 cutoff. At 19:00 the employee
        // may simply still be at work, and asking HR to chase them is the noise item 5 removed.
        $this->travelTo(Carbon::parse('2026-08-03 19:00', config('app.timezone_local')));
        $this->openPunch('2026-08-03', '08:00');

        $this->assertNull($this->rowFor('2026-08-03'));

        // One hour later the cutoff has passed and it becomes an ordinary auto-closed row.
        $this->travelTo(Carbon::parse('2026-08-03 20:30', config('app.timezone_local')));
        $this->assertSame('auto_closed', $this->rowFor('2026-08-03')['status']);
    }

    public function test_the_allowance_setting_moves_the_cutoff(): void
    {
        app(AttendancePolicyService::class)->get()->update([
            'missing_timeout_allowance_minutes' => 30,
        ]);
        // 17:00 + 30 min = 17:30. At 19:00 a 180-minute allowance would still be waiting.
        $this->travelTo(Carbon::parse('2026-08-03 19:00', config('app.timezone_local')));
        $this->openPunch('2026-08-03', '08:00');

        $this->assertSame('auto_closed', $this->rowFor('2026-08-03')['status']);
    }

    public function test_a_closed_shift_is_not_listed(): void
    {
        $row = $this->openPunch('2026-08-03', '08:00');
        $row->update([
            'time_out' => Carbon::parse('2026-08-03 17:00', config('app.timezone_local'))->utc(),
        ]);

        $this->assertNull($this->rowFor('2026-08-03'));
    }

    public function test_management_is_never_listed(): void
    {
        // Their rows are generated rather than punched, so an open one is not HR's to correct.
        $this->employee->update(['is_management' => true]);
        $this->openPunch('2026-08-03', '08:00');

        $this->assertNull($this->rowFor('2026-08-03'));
    }

    public function test_dates_outside_the_requested_range_are_not_listed(): void
    {
        $this->openPunch('2026-08-03', '08:00');

        $this->assertNull($this->rowFor('2026-08-03', '2026-08-05', '2026-08-31'));
    }

    // --- ordering and the second clock ---

    public function test_days_that_need_fixing_sort_above_tidy_ones(): void
    {
        $this->openPunch('2026-08-03', '08:00'); // auto-closed, and the OLDER of the two
        $this->openPunch('2026-08-09', '09:00'); // Sunday — needs fixing

        $rows = app(AttendanceService::class)->openShiftReview('2026-08-01', '2026-08-31');

        $this->assertCount(2, $rows);
        // Oldest-first would put the tidy row on top and bury the one costing money.
        $this->assertSame('2026-08-09', $rows[0]['work_date']);
        $this->assertSame('needs_fix', $rows[0]['status']);
        $this->assertSame('auto_closed', $rows[1]['status']);
    }

    /**
     * The allowance and the self-close limit are different clocks and must not be conflated:
     * one decides when PAY stops waiting, the other when the EMPLOYEE can no longer fix it.
     */
    public function test_the_self_close_limit_is_reported_separately_from_the_allowance(): void
    {
        // 08:00 in, and it is now 20:30 the same day: past the 20:00 pay cutoff (12.5h open,
        // so still under the 18-hour limit) — pay has moved on while the employee has not.
        $this->travelTo(Carbon::parse('2026-08-03 20:30', config('app.timezone_local')));
        $this->openPunch('2026-08-03', '08:00');

        $row = $this->rowFor('2026-08-03');
        $this->assertSame('auto_closed', $row['status']);
        $this->assertTrue($row['employee_can_fix']);
        $this->assertSame(12.5, $row['hours_open']);

        // Next morning the same row is past 18 hours and only HR can close it.
        $this->travelTo(Carbon::parse('2026-08-04 08:00', config('app.timezone_local')));
        $this->assertFalse($this->rowFor('2026-08-03')['employee_can_fix']);
    }

    public function test_correcting_a_day_removes_it_from_the_queue(): void
    {
        $this->openPunch('2026-08-02', '09:00'); // Sunday — needs fixing
        $this->assertNotNull($this->rowFor('2026-08-02'));

        app(AttendanceService::class)->adjust($this->employee, '2026-08-02', [
            'time_in' => '09:00',
            'time_out' => '18:00',
            'status' => 'rest_day',
        ]);

        $this->assertNull($this->rowFor('2026-08-02'));
    }

    // --- the endpoint ---

    public function test_the_endpoint_returns_the_queue_for_hr(): void
    {
        $this->openPunch('2026-08-02', '09:00');

        $this->actingAsHr()
            ->getJson('/api/v1/hr/attendance/open-shifts?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.0.work_date', '2026-08-02')
            ->assertJsonPath('data.0.status', 'needs_fix')
            ->assertJsonPath('data.0.reason', 'rest_day');
    }

    public function test_an_ordinary_employee_cannot_read_the_queue(): void
    {
        // The rows name colleagues and their unresolved days — an HR-officer view, not a staff one.
        Sanctum::actingAs($this->employee->user);

        $this->getJson('/api/v1/hr/attendance/open-shifts')->assertForbidden();
    }

    public function test_the_endpoint_rejects_a_malformed_date(): void
    {
        $this->actingAsHr()
            ->getJson('/api/v1/hr/attendance/open-shifts?from=last-tuesday')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }
}
