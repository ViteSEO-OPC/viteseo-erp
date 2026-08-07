<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * FIN-034 — half a day OF LEAVE, which the spec always asked for and the build never wired up.
 *
 * `POD 1 (Employee View) Features.md` puts a half-day toggle inside the Leave Request Form. What got
 * built was `HalfDayRequest`: a separate attendance-side record that is ALWAYS UNPAID and touches no
 * balance. Useful, but a different thing — it made "half a day of my vacation leave" unexpressible, so
 * an employee wanting a paid afternoon off had to burn a whole day's credit or take the unpaid half.
 *
 * The capability was already there and unreachable. `LeaveService::computeDays()` has always known how
 * to return 0.5, the `is_half_day` / `half_day_period` columns have always existed, and the balance
 * columns are decimal(5,1). One hardcoded `false` at the single call site was the whole gap.
 *
 * THE PAYROLL ASSERTIONS ARE THE ONES TO READ. The full-day leave branch is guarded on "no clock-in",
 * because clocking in proves the leave wasn't taken. On a half day that reasoning inverts — the
 * employee is supposed to come in and work the other half — so without a dedicated branch a half-day
 * leave would be silently voided while its balance had already been spent.
 */
class HalfDayLeaveTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private LeaveType $paid;

    private LeaveType $unpaid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-20 12:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);

        $this->paid = LeaveType::create(['name' => 'Vacation', 'code' => 'VL', 'is_paid' => true]);
        $this->unpaid = LeaveType::create(['name' => 'Leave without pay', 'code' => 'LWOP', 'is_paid' => false]);

        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->paid->id,
            'year' => 2026, 'entitled_days' => 10, 'used_days' => 0,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function file(array $data = []): LeaveRequest
    {
        return app(LeaveService::class)->create($this->employee, array_merge([
            'leave_type_id' => $this->paid->id,
            'start_date' => '2026-08-24', // a Monday
            'end_date' => '2026-08-24',
            'reason' => 'Afternoon off',
            'is_half_day' => true,
            'half_day_period' => 'PM',
        ], $data));
    }

    /** Unpaid days charged for the week containing 2026-08-24. */
    private function unpaidDays(): float
    {
        return (float) app(AttendanceService::class)
            ->unpaidDaysDetailFor($this->employee, '2026-08-24', '2026-08-28', app(AttendancePolicyService::class)->get())
            ->unpaidDays;
    }

    /** Clocked in for the morning only — the half they were meant to work. */
    private function workedMorning(string $date = '2026-08-24'): void
    {
        $tz = config('app.timezone_local');

        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => $date,
            'time_in' => Carbon::parse("{$date} 08:00", $tz)->utc(),
            'time_out' => Carbon::parse("{$date} 12:00", $tz)->utc(),
            'status' => 'present',
        ]);
    }

    // --- filing ---------------------------------------------------------------------------------

    public function test_a_half_day_leave_consumes_half_a_day(): void
    {
        $request = $this->file();

        $this->assertTrue((bool) $request->is_half_day);
        $this->assertSame('PM', $request->half_day_period);
        $this->assertSame(0.5, (float) $request->days);
    }

    public function test_approving_deducts_half_a_day_of_balance(): void
    {
        app(LeaveService::class)->approve($this->file(), $this->hrUser());

        $this->assertSame(
            0.5,
            (float) LeaveBalance::where('leave_type_id', $this->paid->id)->value('used_days'),
            'decimal(5,1) holds the half — nothing may round it to 0 or 1',
        );
    }

    /** The balance the employee is shown has to agree with what was actually spent. */
    public function test_the_reported_balance_shows_the_half(): void
    {
        app(LeaveService::class)->approve($this->file(), $this->hrUser());

        $this->actingAs($this->employee->user)
            ->getJson('/api/v1/hr/me/leave-balances')
            ->assertOk()
            ->assertJsonPath('data.0.used_days', 0.5)
            ->assertJsonPath('data.0.remaining_days', 9.5);
    }

    public function test_a_half_day_must_be_a_single_day(): void
    {
        $this->expectException(ValidationException::class);
        $this->file(['end_date' => '2026-08-25']);
    }

    public function test_the_period_is_required_over_http(): void
    {
        $this->actingAs($this->employee->user)
            ->postJson('/api/v1/hr/leave-requests', [
                'leave_type_id' => $this->paid->id,
                'start_date' => '2026-08-24',
                'end_date' => '2026-08-24',
                'reason' => 'Afternoon off',
                'is_half_day' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('half_day_period');
    }

    public function test_a_full_day_request_still_files_as_a_full_day(): void
    {
        $request = $this->file(['is_half_day' => false, 'half_day_period' => null]);

        $this->assertFalse((bool) $request->is_half_day);
        $this->assertNull($request->half_day_period);
        $this->assertSame(1.0, (float) $request->days);
    }

    /** Half a day still has to fit the remaining balance — and half of it is enough. */
    public function test_half_a_day_fits_a_half_day_balance(): void
    {
        LeaveBalance::where('leave_type_id', $this->paid->id)->update(['used_days' => 9.5]);

        $this->assertSame(0.5, (float) $this->file()->days);
    }

    // --- payroll --------------------------------------------------------------------------------

    /**
     * THE ONE THAT MATTERS. A paid half day costs the employee nothing in pay: the credit covers the
     * half they were off, and they worked the other half.
     */
    public function test_a_paid_half_day_docks_nothing(): void
    {
        app(LeaveService::class)->approve($this->file(), $this->hrUser());
        $this->workedMorning();

        $this->assertSame(0.0, $this->unpaidDays());
    }

    /** A half day of LWOP costs half a day's pay — not a whole one, and not nothing. */
    public function test_an_unpaid_half_day_docks_half_a_day(): void
    {
        app(LeaveService::class)->approve(
            $this->file(['leave_type_id' => $this->unpaid->id]),
            $this->hrUser(),
        );
        $this->workedMorning();

        $this->assertSame(0.5, $this->unpaidDays());
    }

    /**
     * The clock-in must not void the leave. Full-day leave is cancelled by a clock-in (proof it
     * wasn't taken); on a half day the clock-in is exactly what was expected, and treating it the
     * same way would spend the balance and dock nothing.
     */
    public function test_clocking_in_does_not_void_a_half_day_of_lwop(): void
    {
        app(LeaveService::class)->approve(
            $this->file(['leave_type_id' => $this->unpaid->id]),
            $this->hrUser(),
        );
        $this->workedMorning(); // came in for the morning, as the request said they would

        $this->assertSame(0.5, $this->unpaidDays(), 'the half off is still charged');
    }

    /** With no attendance at all it is still only half a day of LWOP, not a full absence. */
    public function test_an_unpaid_half_day_with_no_clock_in_is_still_half(): void
    {
        app(LeaveService::class)->approve(
            $this->file(['leave_type_id' => $this->unpaid->id]),
            $this->hrUser(),
        );

        $this->assertSame(0.5, $this->unpaidDays());
    }

    /** HR stamping the row `on_leave` must not double the charge. */
    public function test_marking_the_row_on_leave_still_charges_only_half(): void
    {
        app(LeaveService::class)->approve(
            $this->file(['leave_type_id' => $this->unpaid->id]),
            $this->hrUser(),
        );

        Attendance::create([
            'employee_id' => $this->employee->id, 'work_date' => '2026-08-24',
            'status' => 'on_leave',
        ]);

        $this->assertSame(0.5, $this->unpaidDays());
    }

    /** A pending request pays and deducts nothing until somebody approves it. */
    public function test_a_pending_half_day_changes_nothing(): void
    {
        $this->file(['leave_type_id' => $this->unpaid->id]);
        $this->workedMorning();

        $this->assertSame(0.0, $this->unpaidDays());
    }

    /** Cancelling returns the half — not a whole day, which would mint credit. */
    public function test_cancelling_returns_exactly_half_a_day(): void
    {
        $request = app(LeaveService::class)->approve($this->file(), $this->hrUser());

        app(LeaveService::class)->cancel($request, $this->hrUser(), 'Plans changed');

        $this->assertSame(
            0.0,
            (float) LeaveBalance::where('leave_type_id', $this->paid->id)->value('used_days'),
        );
    }
}
