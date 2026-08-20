<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Item 11 — "overtime starts after your shift ends", on a shift that ends the next morning.
 *
 * `assertAfterShift` was the one place the overnight roll from item 1 never reached. It compared
 * raw minute-of-day, and on a 22:00–06:00 schedule the end (360) is a SMALLER number than the
 * start (1320), so the comparison answered nonsense: a 23:00 filing — squarely inside the shift —
 * passed, and so did a 19:00 one three hours before the employee had even clocked in.
 *
 * WHY IT MATTERED RATHER THAN JUST LOOKING WRONG. Such a request was then approved and paid
 * NOTHING, because item 2 only pays the overlap between the approved window and the punches
 * actually recorded. The employee ended up holding an approved request worth zero pesos with
 * nothing anywhere to explain why.
 *
 * The fix places a bare `HH:MM` on the alignment NEAREST THE SHIFT END — the same choice
 * `bestWindowOverlap()` makes when payroll matches the request against the punches later. A filing
 * rule that resolved times differently from the engine would keep accepting what the engine will
 * not pay, which is the whole failure being fixed.
 *
 * The clock is parked at Thu 2026-08-20, a scheduled workday, so requests can be filed for "today".
 */
class OvernightOvertimeFilingTest extends HrTestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-08-20';

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 12:00', config('app.timezone_local')));

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 0,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 0, 'is_default' => true,
        ]);

        app(AttendancePolicyService::class)->get()->update(['overtime_mode' => 'approved_only']);

        $department = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $department->id,
            'employee_no' => 'E-ONF-100',
            'job_title' => 'Agent',
            'hire_date' => '2020-01-01',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);
    }

    private function schedule(string $start, string $end): void
    {
        WorkSchedule::firstOrFail()->update(['start_time' => $start, 'end_time' => $end]);
    }

    private function file(string $from, string $to, string $date = self::TODAY): TestResponse
    {
        Sanctum::actingAs($this->employee->user);

        return $this->postJson('/api/v1/hr/overtime-requests', [
            'work_date' => $date,
            'time_from' => $from,
            'time_to' => $to,
            'reason' => 'Month-end close',
        ]);
    }

    // --- the graveyard shift, where the old comparison was meaningless -----------------------------

    /** THE REGRESSION: 23:00 is an hour INTO a 22:00–06:00 shift, and it used to be accepted. */
    public function test_a_filing_inside_an_overnight_shift_is_refused(): void
    {
        $this->schedule('22:00', '06:00');

        $this->file('23:00', '01:00')
            ->assertStatus(422)
            ->assertJsonValidationErrors('time_from');
    }

    /** And 02:00 is inside it too, on the far side of midnight. */
    public function test_a_filing_after_midnight_but_still_inside_the_shift_is_refused(): void
    {
        $this->schedule('22:00', '06:00');

        $this->file('02:00', '04:00')
            ->assertStatus(422)
            ->assertJsonValidationErrors('time_from');
    }

    /** The doc's example: three hours before the employee has even clocked in. */
    public function test_a_filing_before_an_overnight_shift_starts_is_refused(): void
    {
        $this->schedule('22:00', '06:00');

        $this->file('19:00', '21:00')
            ->assertStatus(422)
            ->assertJsonValidationErrors('time_from');
    }

    /** What SHOULD be allowed: staying on past the 06:00 end. */
    public function test_overtime_immediately_after_an_overnight_shift_is_allowed(): void
    {
        $this->schedule('22:00', '06:00');

        $this->file('06:00', '08:00')->assertCreated();
    }

    public function test_overtime_later_that_morning_is_allowed(): void
    {
        $this->schedule('22:00', '06:00');

        $this->file('07:00', '09:00')->assertCreated();
    }

    // --- the day shift must not regress -----------------------------------------------------------

    public function test_a_day_shift_still_refuses_a_filing_before_its_end(): void
    {
        $this->file('06:00', '08:00')
            ->assertStatus(422)
            ->assertJsonValidationErrors('time_from');
    }

    public function test_a_day_shift_allows_overtime_from_its_end(): void
    {
        $this->file('17:00', '19:00')->assertCreated();
    }

    /**
     * Overtime that runs past midnight after a DAY shift. 01:00 is nearer the following morning
     * than it is to the work date's own small hours, and reading it the other way would refuse a
     * perfectly ordinary late night.
     */
    public function test_a_day_shift_allows_overtime_that_runs_past_midnight(): void
    {
        $this->file('01:00', '03:00')->assertCreated();
    }

    // --- no shift, no restriction -----------------------------------------------------------------

    /** A rest day has no shift to be "beyond", so any window is coherent. */
    public function test_a_rest_day_filing_is_unrestricted(): void
    {
        // Sat 2026-08-22 — outside workdays [1..5].
        $this->file('09:00', '13:00', '2026-08-22')->assertCreated();
    }

    // --- the same rule guards HR's retroactive path ------------------------------------------------

    public function test_hrs_correction_form_uses_the_same_overnight_rule(): void
    {
        $this->schedule('22:00', '06:00');

        // Mon 2026-08-17, three days back — a date only HR can file for.
        $this->actingAsHr()->postJson('/api/v1/hr/overtime-requests/for-employee', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-17',
            'time_from' => '23:00',
            'time_to' => '01:00',
            'reason' => 'Mid-shift window, must be refused for HR too.',
        ])->assertStatus(422)->assertJsonValidationErrors('time_from');

        $this->actingAsHr()->postJson('/api/v1/hr/overtime-requests/for-employee', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-17',
            'time_from' => '06:00',
            'time_to' => '08:00',
            'reason' => 'Stayed on past the end of the graveyard shift.',
        ])->assertCreated();
    }

    /** The form's live classifier now reports the start too, so the UI can explain the window. */
    public function test_classify_reports_the_shift_start(): void
    {
        $this->schedule('22:00', '06:00');
        Sanctum::actingAs($this->employee->user);

        $this->getJson('/api/v1/hr/overtime-requests/classify?date='.self::TODAY)
            ->assertOk()
            ->assertJsonPath('data.schedule_start', '22:00')
            ->assertJsonPath('data.schedule_end', '06:00');
    }
}
