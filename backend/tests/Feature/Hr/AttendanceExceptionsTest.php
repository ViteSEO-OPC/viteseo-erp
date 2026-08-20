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

/** Item 12 — the attendance problems a cycle carries, asked before payroll turns them into money. */
class AttendanceExceptionsTest extends HrTestCase
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

        app(AttendancePolicyService::class)->get()->update(['overtime_mode' => 'approved_only']);

        $department = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => $department->id,
            'employee_no' => 'E-AEX-100',
            'job_title' => 'Agent',
            'hire_date' => '2020-01-01',
            'base_salary' => 30000,
            'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);
    }

    private function worked(string $date, string $timeIn, ?string $timeOut): void
    {
        $tz = config('app.timezone_local');
        $clockIn = Carbon::parse("{$date} {$timeIn}", $tz);
        $clockOut = $timeOut === null ? null : Carbon::parse("{$date} {$timeOut}", $tz);
        if ($clockOut !== null && $clockOut->lte($clockIn)) {
            $clockOut->addDay();
        }

        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_in' => $clockIn->utc(),
            'time_out' => $clockOut?->utc(),
            'status' => 'present',
            'source' => Attendance::SOURCE_MANUAL,
        ]);
    }

    private function approved(string $date, string $from, string $to): void
    {
        OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_from' => $from,
            'time_to' => $to,
            'hours' => 2,
            'reason' => 'Month-end close',
            'status' => 'approved',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function exceptions(): array
    {
        return app(AttendanceService::class)->exceptionsForCycle('2026-08-01', '2026-08-19');
    }

    /** @return array<string, int> key => count */
    private function counts(): array
    {
        $out = [];
        foreach ($this->exceptions() as $e) {
            $out[$e['key']] = $e['count'];
        }

        return $out;
    }

    // --- a clean cycle says nothing ----------------------------------------------------------------

    /** Five permanent zeroes is how a panel teaches people to stop reading it. */
    public function test_a_clean_cycle_reports_nothing_at_all(): void
    {
        $this->worked('2026-08-17', '08:00', '17:00');

        $this->assertSame([], $this->exceptions());
    }

    // --- approved overtime with no punches behind it -----------------------------------------------

    /**
     * THE SILENT ONE. Approved, visible as approved on both sides, pays nothing — because item 2
     * only pays the overlap with punches actually recorded.
     */
    public function test_an_approval_the_punches_cannot_back_is_reported(): void
    {
        $this->worked('2026-08-17', '08:00', '17:00'); // left exactly on time
        $this->approved('2026-08-17', '17:00', '19:00');

        $this->assertSame(1, $this->counts()['approved_without_punch']);
    }

    public function test_an_approval_the_punches_do_back_is_not_reported(): void
    {
        $this->worked('2026-08-17', '08:00', '19:00');
        $this->approved('2026-08-17', '17:00', '19:00');

        $this->assertArrayNotHasKey('approved_without_punch', $this->counts());
    }

    /** Partial evidence is still evidence — an hour of the two was clocked, so it pays something. */
    public function test_a_partly_backed_approval_is_not_reported(): void
    {
        $this->worked('2026-08-17', '08:00', '18:00');
        $this->approved('2026-08-17', '17:00', '19:00');

        $this->assertArrayNotHasKey('approved_without_punch', $this->counts());
    }

    public function test_a_rejected_request_is_not_reported(): void
    {
        $this->worked('2026-08-17', '08:00', '17:00');
        $this->approved('2026-08-17', '17:00', '19:00');
        OvertimeRequest::firstOrFail()->update(['status' => 'rejected']);

        $this->assertArrayNotHasKey('approved_without_punch', $this->counts());
    }

    public function test_a_cancelled_approval_is_not_reported(): void
    {
        $this->worked('2026-08-17', '08:00', '17:00');
        $this->approved('2026-08-17', '17:00', '19:00');
        OvertimeRequest::firstOrFail()->update(['cancelled_at' => now()]);

        $this->assertArrayNotHasKey('approved_without_punch', $this->counts());
    }

    /** A legacy hours-only row has no window to compare, and its capacity is punch-bounded already. */
    public function test_a_legacy_hours_only_approval_is_not_reported(): void
    {
        $this->worked('2026-08-17', '08:00', '17:00');
        OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-17',
            'time_from' => null,
            'time_to' => null,
            'hours' => 2,
            'reason' => 'Legacy approval',
            'status' => 'approved',
        ]);

        $this->assertArrayNotHasKey('approved_without_punch', $this->counts());
    }

    // --- unusually long closed shifts ---------------------------------------------------------------

    /** 08:00 → 02:00 is 18 hours. The record is complete, so nothing else in the system objects. */
    public function test_a_closed_shift_over_sixteen_hours_is_reported(): void
    {
        $this->worked('2026-08-17', '08:00', '02:00');

        $this->assertSame(1, $this->counts()['long_shifts']);
    }

    public function test_a_long_but_plausible_shift_is_not_reported(): void
    {
        $this->worked('2026-08-17', '08:00', '22:00'); // 14 hours

        $this->assertArrayNotHasKey('long_shifts', $this->counts());
    }

    /** An OPEN long punch belongs to item 6's queue; reporting it here would double-count the day. */
    public function test_an_open_punch_is_not_counted_as_a_long_shift(): void
    {
        $this->worked('2026-08-17', '08:00', null);

        $this->assertArrayNotHasKey('long_shifts', $this->counts());
    }

    public function test_management_is_excluded(): void
    {
        $this->employee->update(['is_management' => true]);
        $this->worked('2026-08-17', '08:00', '02:00');

        $this->assertArrayNotHasKey('long_shifts', $this->counts());
    }

    // --- the summary itself --------------------------------------------------------------------------

    /** Ordered by what inaction costs: unpriceable days lead, merely-odd ones trail. */
    public function test_blocking_entries_come_before_advisory_ones(): void
    {
        $this->worked('2026-08-16', '09:00', null);   // Sunday, open — cannot be priced
        $this->worked('2026-08-17', '08:00', '02:00'); // 18 hours — odd, but priced

        $keys = array_column($this->exceptions(), 'key');

        $this->assertSame(['unpriced_days', 'long_shifts'], $keys);
        $this->assertTrue($this->exceptions()[0]['blocking']);
        $this->assertFalse($this->exceptions()[1]['blocking']);
    }

    public function test_dates_outside_the_cycle_are_not_counted(): void
    {
        $this->worked('2026-08-25', '08:00', '02:00'); // after the 19th

        $this->assertSame([], $this->exceptions());
    }

    public function test_the_endpoint_returns_the_summary_for_hr(): void
    {
        $this->worked('2026-08-17', '08:00', '02:00');

        $this->actingAsHr()
            ->getJson('/api/v1/hr/payroll/attendance-exceptions?start=2026-08-01&end=2026-08-19')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'long_shifts')
            ->assertJsonPath('data.0.count', 1)
            ->assertJsonPath('data.0.blocking', false);
    }

    public function test_an_ordinary_employee_cannot_read_the_summary(): void
    {
        // It names colleagues' problem days across the whole company.
        Sanctum::actingAs($this->employee->user);

        $this->getJson('/api/v1/hr/payroll/attendance-exceptions?start=2026-08-01&end=2026-08-19')
            ->assertForbidden();
    }

    public function test_the_endpoint_rejects_an_inverted_range(): void
    {
        $this->actingAsHr()
            ->getJson('/api/v1/hr/payroll/attendance-exceptions?start=2026-08-19&end=2026-08-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('end');
    }
}
