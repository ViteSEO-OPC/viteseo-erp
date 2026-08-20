<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\RemoteCheckin;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\RemoteCheckinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use App\Modules\Hr\Services\PrivateFileService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Off-site time-ins. */
class RemoteCheckinTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $hr;

    private RemoteCheckinService $svc;

    /** Shift starts 09:00 with 15 minutes' grace — so 09:16+ is late. */
    private const SUBMITTED_ON_TIME = '2026-07-01 08:58';

    private const SUBMITTED_LATE = '2026-07-01 10:30';

    private const APPROVED_AT = '2026-07-01 14:00';

    protected function setUp(): void
    {
        parent::setUp();
        // check-in selfies live on the PRIVATE disk. They were on `public`, which the
        // webserver serves with no authentication: a photo of an employee, at a location, at a
        // time, readable by anyone who guessed or was forwarded the URL.
        Storage::fake(PrivateFileService::DISK);

        WorkSchedule::create([
            'name' => 'Day', 'workdays' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 15, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Field Agent', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
        $this->hr = User::factory()->create();

        $this->svc = app(RemoteCheckinService::class);
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

    private function submit(?float $lat = null, ?float $lng = null): RemoteCheckin
    {
        return $this->svc->submit(
            $this->employee,
            UploadedFile::fake()->image('selfie.jpg'),
            $lat,
            $lng,
        );
    }

    // --- The invariant --------------------------------------------------------------

    public function test_approval_stamps_the_submission_time_not_the_approval_time(): void
    {
        $this->atManila(self::SUBMITTED_ON_TIME);
        $checkin = $this->submit();
        $submittedAt = $checkin->created_at;

        // Five hours pass before HR gets to it.
        $this->atManila(self::APPROVED_AT);
        $this->svc->approve($checkin, $this->hr);

        $att = Attendance::where('employee_id', $this->employee->id)->firstOrFail();

        $this->assertTrue(
            $att->time_in->equalTo($submittedAt),
            'time_in must be the moment of submission, never the moment of approval.',
        );
        $this->assertSame(Attendance::LOCATION_REMOTE_APPROVED, $att->location_verified_by);
    }

    /** The corollary: HR's delay must not turn an on-time arrival into a late one. */
    public function test_hr_review_delay_does_not_make_an_on_time_employee_late(): void
    {
        $this->atManila(self::SUBMITTED_ON_TIME); // 08:58, inside grace
        $checkin = $this->submit();

        $this->atManila(self::APPROVED_AT); // 14:00 — hours "late", if you used now()
        $this->svc->approve($checkin, $this->hr);

        $this->assertSame('present', Attendance::where('employee_id', $this->employee->id)->first()->status);
    }

    /** But a genuinely late submission is still late — the rule isn't disabled, just dated. */
    public function test_a_late_submission_is_still_marked_late(): void
    {
        $this->atManila(self::SUBMITTED_LATE); // 10:30
        $checkin = $this->submit();

        $this->atManila(self::APPROVED_AT);
        $this->svc->approve($checkin, $this->hr);

        $this->assertSame('late', Attendance::where('employee_id', $this->employee->id)->first()->status);
    }

    /**
     * work_date follows the Manila day the shift STARTED, like every other punch — an
     * 11pm field time-in approved the next morning belongs to the night it began.
     */
    public function test_work_date_is_the_manila_day_of_submission(): void
    {
        $this->atManila('2026-07-01 23:10');
        $checkin = $this->submit();

        $this->atManila('2026-07-02 09:00');
        $this->svc->approve($checkin, $this->hr);

        $this->assertSame(
            '2026-07-01',
            Attendance::where('employee_id', $this->employee->id)->first()->work_date->toDateString(),
        );
    }

    // --- Submission guards ----------------------------------------------------------

    public function test_the_photo_is_stored_and_the_row_starts_pending(): void
    {
        $this->atManila(self::SUBMITTED_ON_TIME);
        $checkin = $this->submit(14.62, 121.05);

        $this->assertSame(RemoteCheckin::STATUS_PENDING, $checkin->status);
        $this->assertTrue($checkin->hasLocation());
        Storage::disk(PrivateFileService::DISK)->assertExists($checkin->photo_path);
    }

    /** GPS is optional — a denied permission must not stop someone timing in. */
    public function test_a_submission_without_a_location_is_accepted(): void
    {
        $this->atManila(self::SUBMITTED_ON_TIME);
        $checkin = $this->submit();

        $this->assertFalse($checkin->hasLocation());
        $this->assertSame(RemoteCheckin::STATUS_PENDING, $checkin->status);
    }

    public function test_a_second_submission_while_one_is_pending_is_refused(): void
    {
        $this->atManila(self::SUBMITTED_ON_TIME);
        $this->submit();

        $this->expectException(ValidationException::class);
        $this->submit();
    }

    public function test_submitting_after_already_clocking_in_is_refused(): void
    {
        $this->atManila(self::SUBMITTED_ON_TIME);
        app(\App\Modules\Hr\Services\AttendanceService::class)->clockIn($this->employee);

        $this->expectException(ValidationException::class);
        $this->submit();
    }

    // --- Rejection ------------------------------------------------------------------

    public function test_rejection_writes_no_attendance_row_and_allows_a_resubmission(): void
    {
        $this->atManila(self::SUBMITTED_ON_TIME);
        $checkin = $this->submit();

        $this->svc->reject($checkin, $this->hr, 'Too dark to identify you.');

        $this->assertSame(0, Attendance::where('employee_id', $this->employee->id)->whereNotNull('time_in')->count());
        $this->assertSame('Too dark to identify you.', $checkin->fresh()->rejection_note);

        // The whole point of a rejection: they can try again immediately.
        $retry = $this->submit();
        $this->assertSame(RemoteCheckin::STATUS_PENDING, $retry->status);
    }

    // --- Decision guards ------------------------------------------------------------

    public function test_a_decided_checkin_cannot_be_decided_again(): void
    {
        $this->atManila(self::SUBMITTED_ON_TIME);
        $checkin = $this->submit();
        $this->svc->approve($checkin, $this->hr);

        $this->expectException(ValidationException::class);
        // Would otherwise try to write a second attendance row for the same day.
        $this->svc->approve($checkin->fresh(), $this->hr);
    }

    public function test_a_rejected_checkin_cannot_then_be_approved(): void
    {
        $this->atManila(self::SUBMITTED_ON_TIME);
        $checkin = $this->submit();
        $this->svc->reject($checkin, $this->hr, 'Not you.');

        $this->expectException(ValidationException::class);
        $this->svc->approve($checkin->fresh(), $this->hr);
    }

    /**
     * Approving when the day already has a punch is refused rather than silently
     * overwriting it — two time-ins for one day is a data error whoever creates it.
     */
    public function test_approving_a_day_that_already_has_a_punch_is_refused(): void
    {
        $this->atManila(self::SUBMITTED_ON_TIME);
        $checkin = $this->submit();

        // HR records the day by hand while the selfie is still in the queue.
        $this->atManila('2026-07-01 09:30');
        app(\App\Modules\Hr\Services\AttendanceService::class)->clockIn($this->employee);

        $this->expectException(ValidationException::class);
        $this->svc->approve($checkin, $this->hr);
    }

    // --- The queue ------------------------------------------------------------------

    public function test_the_queue_shows_pending_oldest_first_and_drops_decided_rows(): void
    {
        $this->atManila('2026-07-01 08:00');
        $first = $this->submit();
        $this->svc->reject($first, $this->hr, 'Retake please.');

        $this->atManila('2026-07-01 08:30');
        $second = $this->submit();

        $pending = $this->svc->list(RemoteCheckin::STATUS_PENDING);
        $this->assertCount(1, $pending);
        $this->assertSame($second->id, $pending->first()->id);

        // History is still reachable — a decision isn't a deletion.
        $this->assertCount(2, $this->svc->list(null));
    }

    public function test_latest_for_returns_the_newest_submission_whatever_its_state(): void
    {
        $this->atManila('2026-07-01 08:00');
        $first = $this->submit();
        $this->svc->reject($first, $this->hr, 'Retake please.');

        $this->atManila('2026-07-01 08:30');
        $second = $this->submit();

        $this->assertSame($second->id, $this->svc->latestFor($this->employee)?->id);
    }
}
