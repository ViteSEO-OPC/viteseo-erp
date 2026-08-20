<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\GeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Geofenced clock-in — the verification ladder. */
class GeofenceClockInTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private AttendanceService $svc;

    /** The office pin every case is measured against. */
    private const OFFICE_LAT = 14.5995000;

    private const OFFICE_LNG = 120.9842000;

    /** The address the "server" sees in these tests. */
    private const OFFICE_IP = '203.0.113.10';

    private const OTHER_IP = '198.51.100.7';

    protected function setUp(): void
    {
        parent::setUp();

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
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false,
        ]);

        $this->svc = app(AttendanceService::class);

        // A fixed, unremarkable weekday morning — nothing here is testing lateness.
        Carbon::setTestNow(Carbon::parse('2026-07-01 09:05', config('app.timezone_local')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function policy(array $overrides): void
    {
        app(AttendancePolicyService::class)->update($overrides);

        // AttendancePolicyService memoizes the single policy row for the life of the
        // instance — correct in production, where HR's save and an employee's clock-in are
        // different requests with different containers. In one test process they are not, so
        // re-resolve the service to stand in for "the next request" and get a clean memo.
        $this->svc = app(AttendanceService::class);
    }

    /** The fully-configured happy-path policy. */
    private function configuredPolicy(int $radius = 200): void
    {
        $this->policy([
            'geofence_enabled' => true,
            'office_lat' => self::OFFICE_LAT,
            'office_lng' => self::OFFICE_LNG,
            'office_radius_m' => $radius,
            'office_ips' => [self::OFFICE_IP],
        ]);
    }

    /** Clear the punch so the next call in a test isn't "already clocked in". */
    private function resetDay(): void
    {
        Attendance::where('employee_id', $this->employee->id)->forceDelete();
    }

    // --- 1. Off is a genuine no-op -------------------------------------------------

    public function test_feature_off_ignores_location_entirely(): void
    {
        $this->policy(['geofence_enabled' => false]);

        // Coordinates in the middle of the Pacific, and an address that is not the office.
        $att = $this->svc->clockIn($this->employee, 0.0, -160.0, 5.0, self::OTHER_IP);

        $this->assertNotNull($att->time_in);
        $this->assertNull($att->location_verified_by, 'Off must record no verification at all.');
    }

    public function test_feature_off_still_accepts_a_clock_in_with_no_location(): void
    {
        $this->policy(['geofence_enabled' => false]);

        $att = $this->svc->clockIn($this->employee);

        $this->assertNotNull($att->time_in);
        $this->assertNull($att->location_verified_by);
    }

    // --- 2. Misconfiguration fails OPEN --------------------------------------------

    /**
     * Enabled but incomplete, in each of the three ways it can be incomplete. Every one
     * must let the punch through — the alternative is a company that cannot clock in
     * because someone half-filled a form.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function incompletePolicies(): array
    {
        return [
            'no pin, no IPs' => [['office_lat' => null, 'office_lng' => null, 'office_ips' => []]],
            'pin but no IPs' => [['office_lat' => self::OFFICE_LAT, 'office_lng' => self::OFFICE_LNG, 'office_ips' => []]],
            'IPs but no pin' => [['office_lat' => null, 'office_lng' => null, 'office_ips' => [self::OFFICE_IP]]],
            'IPs are blank strings' => [['office_lat' => self::OFFICE_LAT, 'office_lng' => self::OFFICE_LNG, 'office_ips' => ['', '  ']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('incompletePolicies')]
    public function test_enabled_but_unconfigured_fails_open(array $config): void
    {
        $this->policy(['geofence_enabled' => true] + $config);

        $att = $this->svc->clockIn($this->employee, null, null, null, self::OTHER_IP);

        $this->assertNotNull($att->time_in, 'A half-configured geofence must never block a clock-in.');
        $this->assertNull($att->location_verified_by, 'Nothing was checked, so nothing may be claimed.');
    }

    // --- 3. The office IP is mandatory ---------------------------------------------

    public function test_perfect_coordinates_cannot_substitute_for_the_office_network(): void
    {
        $this->configuredPolicy();

        $this->expectException(ValidationException::class);
        // Standing exactly on the office pin, with a 1 m fix — and off the network.
        $this->svc->clockIn($this->employee, self::OFFICE_LAT, self::OFFICE_LNG, 1.0, self::OTHER_IP);
    }

    public function test_a_missing_ip_is_refused_rather_than_waved_through(): void
    {
        $this->configuredPolicy();

        $this->expectException(ValidationException::class);
        $this->svc->clockIn($this->employee, self::OFFICE_LAT, self::OFFICE_LNG, 1.0, null);
    }

    public function test_a_refused_clock_in_writes_no_row(): void
    {
        $this->configuredPolicy();

        try {
            $this->svc->clockIn($this->employee, self::OFFICE_LAT, self::OFFICE_LNG, 1.0, self::OTHER_IP);
            $this->fail('Expected the clock-in to be refused.');
        } catch (ValidationException) {
            // The employee must be free to retry, or to switch to the Outside path.
            $this->assertSame(0, Attendance::where('employee_id', $this->employee->id)->count());
        }
    }

    // --- 4. Both signals present ----------------------------------------------------

    public function test_on_the_network_and_inside_the_fence_verifies_by_ip_and_gps(): void
    {
        $this->configuredPolicy();

        $att = $this->svc->clockIn($this->employee, self::OFFICE_LAT, self::OFFICE_LNG, 20.0, self::OFFICE_IP);

        $this->assertSame(Attendance::LOCATION_IP_GPS, $att->location_verified_by);
    }

    public function test_clearly_outside_the_fence_is_refused(): void
    {
        $this->configuredPolicy();

        $this->expectException(ValidationException::class);
        // ~16 km away, on a fix tight enough that the distance can't be explained away.
        $this->svc->clockIn($this->employee, 14.7, 121.1, 20.0, self::OFFICE_IP);
    }

    /**
     * A fuzzy fix must not reject someone who is genuinely inside. ~250 m from the pin on a
     * ±100 m reading is consistent with standing 150 m away — inside a 200 m fence.
     */
    public function test_the_readings_own_margin_of_error_is_forgiven(): void
    {
        $this->configuredPolicy();

        $att = $this->svc->clockIn($this->employee, 14.6017460, self::OFFICE_LNG, 100.0, self::OFFICE_IP);

        $this->assertSame(Attendance::LOCATION_IP_GPS, $att->location_verified_by);
    }

    /** The same distance, on a fix precise enough to be believed, is a refusal. */
    public function test_the_same_distance_on_a_precise_fix_is_refused(): void
    {
        $this->configuredPolicy();

        $this->expectException(ValidationException::class);
        $this->svc->clockIn($this->employee, 14.6026440, self::OFFICE_LNG, 20.0, self::OFFICE_IP);
    }

    // --- 5. The desktop case --------------------------------------------------------

    /**
     * The reason this ladder exists. An ethernet desktop resolves its position from its own
     * IP and reports a city-level guess — kilometres out, and NOT as an error. Trusting that
     * number would reject the office's own desk staff every morning.
     *
     * @return array<string, array{?float, ?float, ?float}>
     */
    public static function unusableFixes(): array
    {
        return [
            'permission denied — nothing at all' => [null, null, null],
            'city-level IP guess (5 km)' => [14.65, 121.02, 5000.0],
            'coordinates with no accuracy reported' => [self::OFFICE_LAT, self::OFFICE_LNG, null],
            'one metre past the cap' => [self::OFFICE_LAT, self::OFFICE_LNG, GeofenceService::MAX_ACCURACY_M + 1],
        ];
    }

    #[DataProvider('unusableFixes')]
    public function test_an_unusable_fix_falls_back_to_the_office_network(?float $lat, ?float $lng, ?float $accuracy): void
    {
        $this->configuredPolicy();

        $att = $this->svc->clockIn($this->employee, $lat, $lng, $accuracy, self::OFFICE_IP);

        $this->assertSame(
            Attendance::LOCATION_IP_ONLY,
            $att->location_verified_by,
            'On the office network with no usable fix, the network alone verifies — and says so.',
        );
    }

    /** Exactly at the cap is still trusted; the boundary is inclusive. */
    public function test_a_fix_exactly_at_the_accuracy_cap_is_still_used(): void
    {
        $this->configuredPolicy();

        $att = $this->svc->clockIn(
            $this->employee, self::OFFICE_LAT, self::OFFICE_LNG,
            (float) GeofenceService::MAX_ACCURACY_M, self::OFFICE_IP,
        );

        $this->assertSame(Attendance::LOCATION_IP_GPS, $att->location_verified_by);
    }

    /**
     * A junk fix is discarded, not merely tolerated: being 16 km away on a 5 km reading
     * still passes, because the reading was never worth consulting. The network is what
     * carries it — which is exactly why the network can't be optional.
     */
    public function test_a_junk_fix_is_discarded_rather_than_measured(): void
    {
        $this->configuredPolicy();

        $att = $this->svc->clockIn($this->employee, 14.7, 121.1, 5000.0, self::OFFICE_IP);

        $this->assertSame(Attendance::LOCATION_IP_ONLY, $att->location_verified_by);
    }

    // --- 6. Ordering against the existing guards ------------------------------------

    public function test_the_already_clocked_in_guard_still_fires_before_the_geofence(): void
    {
        $this->configuredPolicy();
        $this->svc->clockIn($this->employee, self::OFFICE_LAT, self::OFFICE_LNG, 20.0, self::OFFICE_IP);

        try {
            // Off-site AND already clocked in — the second must be the reported problem,
            // or an employee gets told to walk to the office over a punch they already made.
            $this->svc->clockIn($this->employee, 14.7, 121.1, 20.0, self::OTHER_IP);
            $this->fail('Expected the clock-in to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('time_in', $e->errors());
            $this->assertArrayNotHasKey('location', $e->errors());
        }
    }

    // --- 7. The distance maths ------------------------------------------------------

    /**
     * Haversine against a known pair. Manila City Hall → Rizal Park is ~1.1 km; the exact
     * figure matters less than the magnitude being right, since a units bug (degrees for
     * metres, or a radius in km) would be off by orders of magnitude, not percent.
     */
    public function test_haversine_returns_metres_of_the_expected_magnitude(): void
    {
        $geo = app(GeofenceService::class);

        $this->assertEqualsWithDelta(0.0, $geo->distanceMeters(14.5995, 120.9842, 14.5995, 120.9842), 0.001);

        // One degree of latitude is ~111.3 km, everywhere.
        $this->assertEqualsWithDelta(111_320.0, $geo->distanceMeters(14.0, 121.0, 15.0, 121.0), 500.0);

        // ~250 m north, the offset the fence tests above rely on.
        $this->assertEqualsWithDelta(250.0, $geo->distanceMeters(14.5995, 120.9842, 14.6017460, 120.9842), 5.0);
    }

    /** A wider fence admits a distance a tighter one refuses — the radius is really read. */
    public function test_the_configured_radius_is_what_decides(): void
    {
        $this->configuredPolicy(radius: 1000);

        $att = $this->svc->clockIn($this->employee, 14.6026440, self::OFFICE_LNG, 20.0, self::OFFICE_IP);
        $this->assertSame(Attendance::LOCATION_IP_GPS, $att->location_verified_by);

        $this->resetDay();
        $this->configuredPolicy(radius: 200);

        $this->expectException(ValidationException::class);
        $this->svc->clockIn($this->employee, 14.6026440, self::OFFICE_LNG, 20.0, self::OFFICE_IP);
    }
}
