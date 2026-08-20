<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Grace is judged to the MINUTE, not the second.
 *
 * `now()` carries real seconds, so an 08:15:05 punch against an 08:00 + 15 min cutoff used to
 * read `late` — while DeductionService, which works off whole minutes, charged it nothing. The
 * flag and the money now agree: the whole 08:15 minute is on time, 08:16 is late.
 */
class GraceMinutePrecisionTest extends HrTestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-24'; // a Monday

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    private function clockInAt(string $time): Attendance
    {
        $this->travelTo(Carbon::parse(self::DATE . " {$time}", config('app.timezone_local')));

        return app(AttendanceService::class)->clockIn($this->employee);
    }

    /** @return array<string, array{string, string}> */
    public static function arrivals(): array
    {
        return [
            'exactly on the cutoff' => ['08:15:00', 'present'],
            'seconds past the cutoff' => ['08:15:05', 'present'],
            'last second of the grace minute' => ['08:15:59', 'present'],
            'the next whole minute' => ['08:16:00', 'late'],
            'seconds into the next minute' => ['08:16:30', 'late'],
        ];
    }

    #[DataProvider('arrivals')]
    public function test_seconds_do_not_decide_lateness(string $time, string $expected): void
    {
        $this->assertSame($expected, $this->clockInAt($time)->status);
    }

    public function test_a_late_flag_always_carries_late_minutes(): void
    {
        $this->clockInAt('08:16:30');

        $late = app(AttendanceService::class)->lateMinutesFor($this->employee, self::DATE, self::DATE);

        // 16, not 16.5 rounded up — the same whole-minute reading DeductionService charges.
        $this->assertSame(16, $late[self::DATE] ?? 0);
    }

    public function test_an_on_time_flag_carries_no_late_minutes(): void
    {
        $this->clockInAt('08:15:05');

        $this->assertSame(
            [],
            app(AttendanceService::class)->lateMinutesFor($this->employee, self::DATE, self::DATE),
        );
    }
}
