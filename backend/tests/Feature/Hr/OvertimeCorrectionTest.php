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
use App\Modules\Hr\Services\OvertimeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Item 10 — filing overtime that the old rules made impossible to record correctly.
 *
 * TWO SEPARATE COMPLAINTS, both about FILING rather than arithmetic:
 *
 *   • One request per date. The pay engine has always merged several windows on a date, so this
 *     was a form restriction with no calculation behind it. A split evening — 17:00–19:00, dinner,
 *     back at 22:00–01:00 — could not be filed at all, and the employee's only recourse was to
 *     claim the whole span including the hours nobody worked. Replaced by an OVERLAP rule, which
 *     is what actually prevents the same minutes being paid twice.
 *
 *   • No way to correct a past date. Somebody works late, never files, and the cutoff closes.
 *     HR can now file it on their behalf; the request lands PENDING so the ordinary approve path
 *     — with its audit entry and its FIN-009 retro flag for periods already paid — still rules on
 *     it. `filed_by` keeps the row from masquerading as the employee's own.
 *
 * The schedule is 08:00–17:00, so every window below starts at or after 17:00.
 */
class OvertimeCorrectionTest extends HrTestCase
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

        // Requests are only meaningful in approved_only — the other modes reject filing outright.
        app(AttendancePolicyService::class)->get()->update([
            'overtime_mode' => 'approved_only',
            'daily_rate_basis' => 'fixed_divisor',
            'annual_divisor' => 261,
            'hours_per_day_divisor' => 8,
        ]);

        $department = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $department->id,
            'employee_no' => 'E-OTC-100',
            'job_title' => 'Agent',
            'hire_date' => '2020-01-01',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function file(array $overrides = []): TestResponse
    {
        Sanctum::actingAs($this->employee->user);

        return $this->postJson('/api/v1/hr/overtime-requests', array_merge([
            'work_date' => '2026-08-20', // today in Manila — the employee path forbids the past
            'time_from' => '17:00',
            'time_to' => '19:00',
            'reason' => 'Month-end close',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function fileForEmployee(array $overrides = []): TestResponse
    {
        return $this->actingAsHr()->postJson('/api/v1/hr/overtime-requests/for-employee', array_merge([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-17', // a Monday, three days back
            'time_from' => '17:00',
            'time_to' => '20:00',
            'reason' => 'Worked the release cutover, forgot to file it.',
        ], $overrides));
    }

    private function worked(string $date, string $timeIn, string $timeOut): void
    {
        $tz = config('app.timezone_local');
        $clockIn = Carbon::parse("{$date} {$timeIn}", $tz);
        $clockOut = Carbon::parse("{$date} {$timeOut}", $tz);
        if ($clockOut->lte($clockIn)) {
            $clockOut->addDay();
        }

        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_in' => $clockIn->utc(),
            'time_out' => $clockOut->utc(),
            'status' => 'present',
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    // --- 10a: several windows a day, as long as they do not overlap --------------------------------

    /** THE CASE THAT COULD NOT BE FILED: out for dinner, back afterwards. */
    public function test_two_non_overlapping_windows_can_be_filed_for_one_date(): void
    {
        $this->file(['time_from' => '17:00', 'time_to' => '19:00'])->assertCreated();
        $this->file(['time_from' => '22:00', 'time_to' => '23:30'])->assertCreated();

        $this->assertSame(2, OvertimeRequest::where('employee_id', $this->employee->id)->count());
    }

    public function test_an_overlapping_window_is_refused(): void
    {
        $this->file(['time_from' => '17:00', 'time_to' => '20:00'])->assertCreated();

        $this->file(['time_from' => '19:00', 'time_to' => '21:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('time_from');
    }

    /** Touching endpoints are not an overlap — 17:00–19:00 then 19:00–21:00 is one continuous stint. */
    public function test_windows_that_merely_touch_are_allowed(): void
    {
        $this->file(['time_from' => '17:00', 'time_to' => '19:00'])->assertCreated();
        $this->file(['time_from' => '19:00', 'time_to' => '21:00'])->assertCreated();
    }

    /**
     * The midnight case. 23:00→02:00 and 00:30→01:30 look disjoint as raw minute-of-day ranges
     * (1380–1560 vs 30–90) while describing the same real hour, which is why the check sweeps the
     * neighbouring day alignments.
     */
    public function test_an_overlap_across_midnight_is_caught(): void
    {
        $this->file(['time_from' => '23:00', 'time_to' => '02:00'])->assertCreated();

        $this->file(['time_from' => '00:30', 'time_to' => '01:30'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('time_from');
    }

    /** A refused request authorises nothing, so it cannot double-pay and must not block. */
    public function test_a_rejected_request_does_not_block_the_date(): void
    {
        $this->file(['time_from' => '17:00', 'time_to' => '20:00'])->assertCreated();
        OvertimeRequest::firstOrFail()->update(['status' => 'rejected']);

        $this->file(['time_from' => '17:00', 'time_to' => '20:00'])->assertCreated();
    }

    public function test_a_cancelled_request_does_not_block_the_date(): void
    {
        $this->file(['time_from' => '17:00', 'time_to' => '20:00'])->assertCreated();
        OvertimeRequest::firstOrFail()->update(['cancelled_at' => now()]);

        $this->file(['time_from' => '17:00', 'time_to' => '20:00'])->assertCreated();
    }

    /** The pay side already handled this; the point is that it is now reachable through the form. */
    public function test_both_windows_are_paid_once_approved(): void
    {
        $this->worked('2026-08-17', '08:00', '23:30');
        OvertimeRequest::query()->forceDelete();

        foreach ([['17:00', '19:00'], ['22:00', '23:30']] as [$from, $to]) {
            OvertimeRequest::create([
                'employee_id' => $this->employee->id,
                'work_date' => '2026-08-17',
                'time_from' => $from,
                'time_to' => $to,
                'hours' => OvertimeRequestService::hoursBetween($from, $to),
                'reason' => 'Split evening',
                'status' => 'approved',
            ]);
        }

        $map = app(AttendanceService::class)->approvedOvertimeMinutesFor(
            $this->employee,
            '2026-08-16',
            '2026-08-18',
        );

        // 120 + 90, kept apart rather than smeared across the 19:00–22:00 gap nobody worked.
        $this->assertSame(210, $map['2026-08-17']['minutes']);
    }

    public function test_the_overlap_rule_itself(): void
    {
        $this->assertTrue(OvertimeRequestService::windowsOverlap('17:00', '20:00', '19:00', '21:00'));
        $this->assertFalse(OvertimeRequestService::windowsOverlap('17:00', '19:00', '19:00', '21:00'), 'touching');
        $this->assertFalse(OvertimeRequestService::windowsOverlap('17:00', '19:00', '22:00', '23:00'));
        $this->assertTrue(OvertimeRequestService::windowsOverlap('23:00', '02:00', '00:30', '01:30'), 'across midnight');
        $this->assertTrue(OvertimeRequestService::windowsOverlap('17:00', '20:00', '17:00', '20:00'), 'identical');
    }

    // --- 10b: HR files for a date that has passed --------------------------------------------------

    public function test_hr_can_file_overtime_for_a_past_date(): void
    {
        $this->fileForEmployee()
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.work_date', '2026-08-17')
            ->assertJsonPath('data.hours', 3);

        $request = OvertimeRequest::firstOrFail();
        $this->assertNotNull($request->filed_by, 'the row must not masquerade as the employee\'s own');
    }

    /** PENDING, not approved: filing and approving stay two acts, both audited. */
    public function test_a_retroactive_filing_is_not_self_approved(): void
    {
        $this->fileForEmployee();

        $this->assertSame('pending', OvertimeRequest::firstOrFail()->status);
        $this->assertNull(OvertimeRequest::firstOrFail()->reviewed_at);
    }

    /**
     * The employee's own list must name the filer. Without it the row is indistinguishable from
     * one they submitted, which attributes a decision to the wrong person.
     */
    public function test_the_filer_is_reported_so_the_employee_can_see_who_filed_it(): void
    {
        $this->fileForEmployee();

        // Read the filer back from the row — hrUser() mints a fresh user on every call, so the
        // name has to come from the request that was actually created.
        $filerName = OvertimeRequest::firstOrFail()->filer->name;
        $this->assertNotNull($filerName);

        Sanctum::actingAs($this->employee->user);
        $this->getJson('/api/v1/hr/overtime-requests')
            ->assertOk()
            ->assertJsonPath('data.0.filed_by', $filerName);
    }

    /** An ordinary self-filed request reports no filer at all — null is the normal case. */
    public function test_a_self_filed_request_reports_no_filer(): void
    {
        $this->file()->assertCreated();

        $this->getJson('/api/v1/hr/overtime-requests')
            ->assertOk()
            ->assertJsonPath('data.0.filed_by', null);
    }

    public function test_an_employee_cannot_file_on_someone_elses_behalf(): void
    {
        Sanctum::actingAs($this->employee->user);

        $this->postJson('/api/v1/hr/overtime-requests/for-employee', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-17',
            'time_from' => '17:00',
            'time_to' => '20:00',
            'reason' => 'Trying to back-date my own overtime.',
        ])->assertForbidden();
    }

    /** The employee's own form still refuses the past — that rule is what this endpoint replaces. */
    public function test_the_employee_form_still_refuses_a_past_date(): void
    {
        $this->file(['work_date' => '2026-08-17'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('work_date');
    }

    public function test_hr_cannot_use_the_correction_form_for_a_future_date(): void
    {
        $this->fileForEmployee(['work_date' => '2026-08-25'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('work_date');
    }

    public function test_hr_cannot_reach_back_indefinitely(): void
    {
        $this->fileForEmployee(['work_date' => '2026-01-05'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('work_date');
    }

    /** A correction filed by someone other than the worker has to say why. */
    public function test_a_correction_needs_a_real_reason(): void
    {
        $this->fileForEmployee(['reason' => 'ot'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_correction_cannot_overlap_an_existing_request(): void
    {
        $this->fileForEmployee(['time_from' => '17:00', 'time_to' => '20:00'])->assertCreated();

        $this->fileForEmployee(['time_from' => '19:00', 'time_to' => '21:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('time_from');
    }

    /** Late is allowed; incoherent is not. Overtime still cannot begin before the shift ends. */
    public function test_a_correction_still_cannot_start_before_the_shift_ends(): void
    {
        $this->fileForEmployee(['time_from' => '15:00', 'time_to' => '18:00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('time_from');
    }

    public function test_a_correction_is_refused_when_overtime_requests_are_switched_off(): void
    {
        app(AttendancePolicyService::class)->get()->update(['overtime_mode' => 'none']);

        $this->fileForEmployee()->assertStatus(422);
    }

    /** Approving a correction pays it exactly as an ordinary approval would. */
    public function test_an_approved_correction_pays(): void
    {
        $this->worked('2026-08-17', '08:00', '20:00');
        $this->fileForEmployee(['time_from' => '17:00', 'time_to' => '20:00'])->assertCreated();

        $this->actingAsHr()
            ->postJson('/api/v1/hr/overtime-requests/'.OvertimeRequest::firstOrFail()->id.'/approve')
            ->assertOk();

        $map = app(AttendanceService::class)->approvedOvertimeMinutesFor(
            $this->employee,
            '2026-08-16',
            '2026-08-18',
        );

        $this->assertSame(180, $map['2026-08-17']['minutes']);
    }
}
