<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\RemoteCheckin;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\RemoteCheckinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The Slice 5 walkthrough, as a test: feature off → on and passing → on and failing →
 * off-site selfie → HR decides → the kill switch.
 *
 * The per-rung behaviour is covered by GeofenceClockInTest and RemoteCheckinTest. What
 * this file protects is the SEQUENCE — that the pieces still compose after someone changes
 * one of them, and above all that the kill switch is a real escape hatch rather than a
 * setting that merely looks like one.
 */
class GeofenceWalkthroughTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $hr;

    private const OFFICE_LAT = 14.5995000;

    private const OFFICE_LNG = 120.9842000;

    private const OFFICE_IP = '203.0.113.10';

    private const HOME_IP = '198.51.100.7';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        WorkSchedule::create([
            'name' => 'Day', 'workdays' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 15, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Agent', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
        $this->hr = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function atManila(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, config('app.timezone_local')));
    }

    /** Re-resolved after every policy change — see the note in GeofenceClockInTest. */
    private function clock(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function policy(array $overrides): void
    {
        app(AttendancePolicyService::class)->update($overrides);
    }

    private function geofenceOn(): void
    {
        $this->policy([
            'geofence_enabled' => true,
            'office_lat' => self::OFFICE_LAT,
            'office_lng' => self::OFFICE_LNG,
            'office_radius_m' => 200,
            'office_ips' => [self::OFFICE_IP],
        ]);
    }

    private function clearDay(): void
    {
        Attendance::where('employee_id', $this->employee->id)->forceDelete();
    }

    /**
     * The whole script in order, on one day, as a person would actually walk it.
     */
    public function test_the_full_walkthrough(): void
    {
        // --- 1. Feature OFF: today's clock-in, untouched. -------------------------------
        $this->atManila('2026-07-01 08:55');
        $this->policy(['geofence_enabled' => false]);

        $att = $this->clock()->clockIn($this->employee);
        $this->assertSame('present', $att->status);
        $this->assertNull($att->location_verified_by, 'Off must not claim any verification.');

        $this->clearDay();

        // --- 2. Feature ON, at the office: passes, and says how. ------------------------
        $this->geofenceOn();
        $att = $this->clock()->clockIn($this->employee, self::OFFICE_LAT, self::OFFICE_LNG, 25.0, self::OFFICE_IP);
        $this->assertSame(Attendance::LOCATION_IP_GPS, $att->location_verified_by);

        $this->clearDay();

        // --- 3. Feature ON, at home: refused, and no row is written. --------------------
        try {
            $this->clock()->clockIn($this->employee, 14.70, 121.10, 25.0, self::HOME_IP);
            $this->fail('A clock-in from off the office network should have been refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('location', $e->errors());
        }
        $this->assertSame(0, Attendance::where('employee_id', $this->employee->id)->count());

        // --- 4. So they use the off-site path instead. ----------------------------------
        $checkin = app(RemoteCheckinService::class)->submit(
            $this->employee,
            UploadedFile::fake()->image('selfie.jpg'),
            14.70,
            121.10,
        );
        $submittedAt = $checkin->created_at;

        // Still not on the clock — that is the point of a pending row.
        $this->assertSame(0, Attendance::where('employee_id', $this->employee->id)->whereNotNull('time_in')->count());

        // --- 5. HR declines it; they re-submit. ----------------------------------------
        app(RemoteCheckinService::class)->reject($checkin, $this->hr, 'Too dark — please retake.');
        $this->assertSame(0, Attendance::where('employee_id', $this->employee->id)->whereNotNull('time_in')->count());

        $this->atManila('2026-07-01 09:05');
        $retry = app(RemoteCheckinService::class)->submit(
            $this->employee,
            UploadedFile::fake()->image('selfie.jpg'),
            null, // second try from indoors — no position this time, which must still work
            null,
        );
        $retrySubmittedAt = $retry->created_at;

        // --- 6. HR approves, hours later. The stamp is the submission. ------------------
        $this->atManila('2026-07-01 16:20');
        app(RemoteCheckinService::class)->approve($retry, $this->hr);

        $att = Attendance::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertTrue($att->time_in->equalTo($retrySubmittedAt));
        $this->assertFalse($att->time_in->equalTo($submittedAt), 'The rejected attempt must not be the stamp.');
        $this->assertSame(Attendance::LOCATION_REMOTE_APPROVED, $att->location_verified_by);
        // 09:05 is inside the 09:00 + 15 min grace, so a 16:20 approval must not mark late.
        $this->assertSame('present', $att->status);

        // --- 7. HR can still correct it by hand afterwards. -----------------------------
        $this->clock()->adjust($this->employee, '2026-07-01', ['time_in' => '09:00', 'time_out' => '18:00']);
        $att->refresh();
        $this->assertSame('09:00', $att->time_in->timezone(config('app.timezone_local'))->format('H:i'));
        $this->assertSame(
            Attendance::LOCATION_REMOTE_APPROVED,
            $att->location_verified_by,
            'Correcting the TIME must not erase how the day was verified.',
        );
    }

    // --- The kill switch ----------------------------------------------------------------

    /**
     * Mid-session, HR flips it off. The very next clock-in must behave as it did before the
     * feature existed — no deploy, no restart, no lingering enforcement.
     */
    public function test_toggling_off_restores_normal_clock_in_immediately(): void
    {
        $this->atManila('2026-07-01 08:55');
        $this->geofenceOn();

        // Proof the geofence is live: this exact call is refused.
        try {
            $this->clock()->clockIn($this->employee, 14.70, 121.10, 25.0, self::HOME_IP);
            $this->fail('Expected the geofence to refuse this.');
        } catch (ValidationException) {
            // expected
        }

        $this->policy(['geofence_enabled' => false]);

        // The identical call now succeeds.
        $att = $this->clock()->clockIn($this->employee, 14.70, 121.10, 25.0, self::HOME_IP);
        $this->assertNotNull($att->time_in);
        $this->assertNull($att->location_verified_by);
    }

    /**
     * Switched on but left unconfigured — the state a half-finished setup leaves behind.
     * It must fail OPEN and log, never block.
     */
    public function test_enabling_without_configuring_does_not_block_anyone(): void
    {
        $this->atManila('2026-07-01 08:55');
        $this->policy([
            'geofence_enabled' => true,
            'office_lat' => null,
            'office_lng' => null,
            'office_ips' => [],
        ]);

        $att = $this->clock()->clockIn($this->employee, null, null, null, self::HOME_IP);

        $this->assertNotNull($att->time_in, 'A half-configured geofence must never lock the company out.');
        $this->assertNull($att->location_verified_by);
    }

    // --- Edge cases the plan calls out ---------------------------------------------------

    /**
     * Approved long after the shift ended. The stamp is still the submission, so the day
     * reads as a normal late arrival rather than as whenever HR happened to look.
     */
    public function test_an_approval_after_the_shift_ended_still_uses_the_submission_time(): void
    {
        $this->atManila('2026-07-01 10:45'); // genuinely late
        $checkin = app(RemoteCheckinService::class)->submit(
            $this->employee, UploadedFile::fake()->image('selfie.jpg'), null, null,
        );

        $this->atManila('2026-07-02 09:00'); // reviewed the NEXT morning
        app(RemoteCheckinService::class)->approve($checkin, $this->hr);

        $att = Attendance::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('2026-07-01', $att->work_date->toDateString());
        $this->assertSame('late', $att->status);
        $this->assertSame(
            '10:45',
            $att->time_in->timezone(config('app.timezone_local'))->format('H:i'),
        );
    }

    /**
     * HR clearing a time-in must clear the location claim with it — otherwise the row
     * reads "absent, and we verified where they were", which is nonsense in an audit.
     */
    public function test_clearing_a_time_in_clears_the_location_claim(): void
    {
        $this->atManila('2026-07-01 08:55');
        $this->geofenceOn();
        $this->clock()->clockIn($this->employee, self::OFFICE_LAT, self::OFFICE_LNG, 25.0, self::OFFICE_IP);

        $this->clock()->adjust($this->employee, '2026-07-01', ['time_in' => null]);

        $att = Attendance::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('absent', $att->status);
        $this->assertNull($att->location_verified_by);
    }

    /**
     * An overnight shift is still running. Submitting a selfie would create a second row
     * and strand the first — refused at submission, not discovered at approval.
     */
    public function test_an_open_overnight_shift_blocks_a_new_off_site_submission(): void
    {
        $this->atManila('2026-07-01 22:00');
        $this->clock()->clockIn($this->employee);

        $this->atManila('2026-07-02 06:00'); // still inside the 18h open-shift window
        $this->expectException(ValidationException::class);
        app(RemoteCheckinService::class)->submit(
            $this->employee, UploadedFile::fake()->image('selfie.jpg'), null, null,
        );
    }

    /** Clocking OUT never consults any of this — people must always be able to leave. */
    public function test_clock_out_is_untouched_by_the_geofence(): void
    {
        $this->atManila('2026-07-01 08:55');
        $this->geofenceOn();
        $this->clock()->clockIn($this->employee, self::OFFICE_LAT, self::OFFICE_LNG, 25.0, self::OFFICE_IP);

        // Walked out of the building, off the office wifi, onto mobile data.
        $this->atManila('2026-07-01 18:05');
        $att = $this->clock()->clockOut($this->employee);

        $this->assertNotNull($att->time_out);
    }

    /** Breaks likewise — the geofence is a clock-IN rule and nothing more. */
    public function test_breaks_are_untouched_by_the_geofence(): void
    {
        $this->atManila('2026-07-01 08:55');
        $this->geofenceOn();
        $this->clock()->clockIn($this->employee, self::OFFICE_LAT, self::OFFICE_LNG, 25.0, self::OFFICE_IP);

        $this->atManila('2026-07-01 12:00');
        $att = $this->clock()->toggleBreak($this->employee, 'lunch');
        $this->assertNotNull($att->lunch_started_at);
    }

    /**
     * A pending selfie must not survive the kill switch as a blocker. With the feature off
     * the employee uses the normal clock, and the orphaned row is refused at approval with
     * a message HR can act on — rather than silently overwriting the real punch.
     */
    public function test_a_pending_selfie_does_not_block_the_normal_clock_after_kill_switch(): void
    {
        $this->atManila('2026-07-01 08:55');
        $this->geofenceOn();
        $checkin = app(RemoteCheckinService::class)->submit(
            $this->employee, UploadedFile::fake()->image('selfie.jpg'), null, null,
        );

        // HR kills the feature while that selfie is still queued.
        $this->policy(['geofence_enabled' => false]);

        $this->atManila('2026-07-01 09:02');
        $att = $this->clock()->clockIn($this->employee);
        $this->assertNotNull($att->time_in);

        // The stale row can't now double-punch the day.
        $this->expectException(ValidationException::class);
        app(RemoteCheckinService::class)->approve($checkin, $this->hr);
    }

    /**
     * A late field time-in approved AFTER the month turned must still raise its flag.
     *
     * Violations are computed lazily per month, and the default recompute covers the
     * current one — so a July row approved in August would otherwise stay unflagged until
     * somebody thought to open July.
     */
    public function test_a_late_field_time_in_approved_next_month_still_raises_its_flag(): void
    {
        $this->atManila('2026-07-31 10:45'); // late, on the last day of the month
        $checkin = app(RemoteCheckinService::class)->submit(
            $this->employee, UploadedFile::fake()->image('selfie.jpg'), null, null,
        );

        $this->atManila('2026-08-03 09:00'); // HR clears the queue days later, in August
        app(RemoteCheckinService::class)->approve($checkin, $this->hr);

        $this->assertDatabaseHas('hr_violations', [
            'employee_id' => $this->employee->id,
            'work_date' => '2026-07-31',
            'type' => 'late',
        ]);
    }

    /** The queue empties as decisions are made — HR's badge has to reach zero. */
    public function test_the_pending_queue_empties_as_decisions_are_made(): void
    {
        $this->atManila('2026-07-01 08:55');
        $svc = app(RemoteCheckinService::class);

        $checkin = $svc->submit($this->employee, UploadedFile::fake()->image('selfie.jpg'), null, null);
        $this->assertCount(1, $svc->list(RemoteCheckin::STATUS_PENDING));

        $svc->approve($checkin, $this->hr);
        $this->assertCount(0, $svc->list(RemoteCheckin::STATUS_PENDING));
    }
}
