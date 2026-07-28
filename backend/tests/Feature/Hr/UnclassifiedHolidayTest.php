<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\HolidaySet;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\HolidayService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Slice 2 (2026-07-28 audit): an unclassified holiday must not be able to kill a payroll run.
 *
 * `hr_holidays.type` is nullable on purpose — the classifier only asserts a type it is 100%
 * sure of and leaves the rest for HR. But holidayPremiumMultiplier() was typed `string`, so
 * one un-typed holiday inside a cycle threw a TypeError and failed the entire run. Verified
 * on live data: 64 of 74 holidays had type = NULL.
 *
 * Two layers now: the pricing helper tolerates null, and the run refuses to start while any
 * home-country holiday in the cycle is unclassified.
 */
class UnclassifiedHolidayTest extends HrTestCase
{
    use RefreshDatabase;

    private AttendancePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = app(AttendancePolicyService::class)->get();
    }

    private function holiday(string $date, ?string $type, string $country = 'PH'): void
    {
        $set = HolidaySet::firstOrCreate(
            ['country_code' => $country, 'year' => (int) Carbon::parse($date)->format('Y')],
            ['source' => 'manual'],
        );

        Holiday::create([
            'holiday_set_id' => $set->id,
            'name' => $type === null ? 'Unclassified Day' : "Test {$type}",
            'date' => $date,
            'type' => $type,
            'source' => 'manual',
            'needs_review' => $type === null,
        ]);
    }

    /** THE CRASH: this threw TypeError before — argument #1 must be of type string, null given. */
    public function test_premium_multiplier_tolerates_an_unclassified_holiday(): void
    {
        $this->assertSame(0.0, PayrollService::holidayPremiumMultiplier(null, $this->policy));
    }

    /** The classified types keep their DOLE-default premiums. */
    public function test_classified_types_are_unchanged(): void
    {
        $this->assertSame(1.0, PayrollService::holidayPremiumMultiplier('regular', $this->policy));
        $this->assertSame(0.3, PayrollService::holidayPremiumMultiplier('special_non_working', $this->policy));
        $this->assertSame(0.0, PayrollService::holidayPremiumMultiplier('special_working', $this->policy));
    }

    public function test_unclassified_holidays_in_a_range_are_reported(): void
    {
        $this->holiday('2026-03-02', null);
        $this->holiday('2026-03-10', 'regular');

        $found = app(HolidayService::class)
            ->unclassifiedBetween(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31'));

        $this->assertSame(['2026-03-02' => 'Unclassified Day'], $found);
    }

    /** Only the home country matters — payroll never reads another country's calendar. */
    public function test_a_foreign_unclassified_holiday_is_ignored(): void
    {
        $this->holiday('2026-03-02', null, 'US');

        $this->assertSame([], app(HolidayService::class)
            ->unclassifiedBetween(Carbon::parse('2026-03-01'), Carbon::parse('2026-03-31')));
    }

    /** The run is blocked while a holiday in the cycle has no type. */
    public function test_payroll_run_is_blocked_by_an_unclassified_holiday(): void
    {
        $this->holiday('2026-03-02', null);

        $this->hrPost('/api/v1/hr/payroll/run', [
            'frequency' => 'semi_monthly',
            'cycle_start' => '2026-03-01',
            'cycle_end' => '2026-03-15',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cycle_start')
            ->assertSee('2026-03-02', escape: false);
    }

    /** Classify it and the same run is accepted. */
    public function test_classifying_the_holiday_unblocks_the_run(): void
    {
        $this->holiday('2026-03-02', 'special_non_working');

        $this->hrPost('/api/v1/hr/payroll/run', [
            'frequency' => 'semi_monthly',
            'cycle_start' => '2026-03-01',
            'cycle_end' => '2026-03-15',
        ])->assertStatus(201);
    }

    /** A holiday outside the cycle never blocks it. */
    public function test_an_unclassified_holiday_outside_the_cycle_does_not_block(): void
    {
        $this->holiday('2026-03-20', null);

        $this->hrPost('/api/v1/hr/payroll/run', [
            'frequency' => 'semi_monthly',
            'cycle_start' => '2026-03-01',
            'cycle_end' => '2026-03-15',
        ])->assertStatus(201);
    }
}
