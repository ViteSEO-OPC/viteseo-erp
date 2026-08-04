<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\AttendancePolicyVersion;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Company → Rules remembers what it used to say.
 *
 * THE GAP THIS CLOSES. Salary, the statutory rates and allowances were each given dated versions
 * in their own slice (13c, B3a, B11). The attendance policy — the divisor, the late-deduction
 * modes, the premium rates — was the fourth pricing input and the only one still read live at
 * compute time, so re-running any cycle that was not locked or disbursed repriced it with today's
 * rules. `policy_snapshot` did not prevent that: it is rewritten on every run, so it recorded the
 * drift rather than stopping it.
 *
 * THE TWO DIRECTIONS, and both matter:
 *   forward   a change dated today must not reach a cycle that already ended
 *   backward  HR must still be able to correct a cycle that already ran, by back-dating
 *
 * Without the second, versioning would have broken the ordinary "run payroll, spot a wrong
 * divisor, fix it, re-run" workflow — trading one trap for another.
 */
class AttendancePolicyHistoryTest extends HrTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        WorkSchedule::create([
            'name' => 'Office', 'workdays' => [1, 2, 3, 4, 5],
            'start_time' => '08:00', 'end_time' => '17:00', 'grace_minutes' => 15,
            'unpaid_break_minutes' => 60, 'paid_break_minutes' => 20, 'is_default' => true,
        ]);
        app(AttendancePolicyService::class)->get();

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS']);
        Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 20000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /** Save the rules the way the Rules page does — through the service, which dates the version. */
    private function saveRules(array $fields, ?string $effectiveFrom = null): void
    {
        app(AttendancePolicyService::class)->update(
            $effectiveFrom === null ? $fields : $fields + ['effective_from' => $effectiveFrom],
        );
    }

    /** The divisor the run was actually priced under, read back off its own snapshot. */
    private function divisorUsedBy(string $cycleStart, string $cycleEnd): int
    {
        return app(PayrollService::class)
            ->run($cycleStart, $cycleEnd, 'semi_monthly', User::factory()->create())
            ->policy_snapshot['attendance_policy']['annual_divisor'];
    }

    // --- forward: a change must not reach a cycle that already closed -------------------

    /** THE BUG: re-running March used to pick up a divisor changed in August. */
    public function test_a_cycle_that_already_ended_keeps_the_rules_it_was_run_under(): void
    {
        $this->saveRules(['annual_divisor' => 261], '2026-03-01');
        $this->assertSame(261, $this->divisorUsedBy('2026-03-01', '2026-03-15'));

        // HR changes the divisor months later, dated the day they typed it.
        $this->saveRules(['annual_divisor' => 313], '2026-08-04');

        $this->assertSame(
            261,
            $this->divisorUsedBy('2026-03-01', '2026-03-15'),
            'Re-running March must still use the divisor March was run under.',
        );
        $this->assertSame(
            313,
            $this->divisorUsedBy('2026-08-01', '2026-08-15'),
            'August is on or after the change, so it gets the new divisor.',
        );
    }

    /**
     * The mid-cycle case, the same promise allowances make: pricing resolves at the CYCLE END,
     * so a change dated any day within the cycle prices the WHOLE cycle. Not split at the date
     * it was saved — a payslip must not depend on the hour HR clicked Save.
     */
    public function test_a_change_dated_inside_the_open_cycle_prices_the_whole_cycle(): void
    {
        $this->saveRules(['annual_divisor' => 261], '2026-07-01');

        // Saved on the 29th, three days before the 16–31 cutoff ends.
        $this->saveRules(['annual_divisor' => 313], '2026-07-29');

        $this->assertSame(
            313,
            $this->divisorUsedBy('2026-07-16', '2026-07-31'),
            'A change dated inside the cycle applies to the whole cycle, not just from its date.',
        );
        $this->assertSame(
            261,
            $this->divisorUsedBy('2026-07-01', '2026-07-15'),
            'The earlier cutoff closed before the change and must keep the old divisor.',
        );
    }

    // --- backward: HR can still correct a cycle that already ran -------------------------

    /**
     * THE WORKFLOW VERSIONING WOULD OTHERWISE HAVE BROKEN, and the reason the Rules page has an
     * "Effective from" field at all: run payroll, notice the divisor is wrong, fix it, re-run.
     * The fix is typed AFTER the cycle ended, so dating it today could never reach it.
     */
    public function test_back_dating_lets_hr_correct_a_cycle_that_already_ran(): void
    {
        $this->saveRules(['annual_divisor' => 261], '2026-06-01');
        $this->assertSame(261, $this->divisorUsedBy('2026-06-16', '2026-06-30'));

        // Typed on 1 July — after the cycle closed — but back-dated into it deliberately.
        $this->saveRules(['annual_divisor' => 313], '2026-06-16');

        $this->assertSame(
            313,
            $this->divisorUsedBy('2026-06-16', '2026-06-30'),
            'A back-dated correction must reach the cycle it was back-dated into.',
        );
    }

    // --- how versions are written --------------------------------------------------------

    /** The Rules page saves one card at a time, so a day's work writes the same date repeatedly. */
    public function test_saving_twice_on_one_date_corrects_that_version(): void
    {
        $this->saveRules(['annual_divisor' => 261], '2026-05-01');
        $this->saveRules(['annual_divisor' => 313], '2026-05-01');

        $this->assertSame(
            1,
            AttendancePolicyVersion::where('effective_from', '2026-05-01')->count(),
            'A second save on the same date corrects that version rather than stacking one.',
        );
        $this->assertSame(313, $this->divisorUsedBy('2026-05-01', '2026-05-15'));
    }

    /** Omitting the date means "from now", which is what the Rules page sends by default. */
    public function test_a_save_with_no_date_is_dated_today(): void
    {
        $this->saveRules(['annual_divisor' => 313]);

        $this->assertDatabaseHas('hr_attendance_policy_versions', [
            'effective_from' => now(config('app.timezone_local'))->toDateString(),
        ]);
    }

    /** A version carries the WHOLE policy, so one card's save cannot orphan another's. */
    public function test_a_version_carries_every_policy_field(): void
    {
        $this->saveRules(['annual_divisor' => 313], '2026-05-01');

        $payload = AttendancePolicyVersion::where('effective_from', '2026-05-01')->first()->payload;

        foreach (array_keys(AttendancePolicyService::DEFAULTS) as $field) {
            $this->assertArrayHasKey($field, $payload, "{$field} is missing from the version payload");
        }
    }

    // --- what must NOT become dated -------------------------------------------------------

    /**
     * Everything live still reads today's rules. A dated policy would be actively wrong here:
     * "can I clock in right now?" is not a question about any pay cycle, and answering it from
     * an old version would gate today's clock-in on a rule HR has already replaced.
     */
    public function test_live_readers_still_see_todays_rules(): void
    {
        $this->saveRules(['early_clock_in_mode' => 'block'], '2026-08-04');

        $this->assertSame(
            'block',
            app(AttendancePolicyService::class)->get()->early_clock_in_mode,
            'get() must always be the live singleton, never a version.',
        );
    }

    /** asOf(null) is the same question as get(), and must give the same answer. */
    public function test_reading_without_a_date_uses_the_current_rules(): void
    {
        $this->saveRules(['annual_divisor' => 313], '2026-08-04');

        $this->assertSame(313, app(AttendancePolicyService::class)->asOf()->annual_divisor);
    }

    /**
     * Rehydrating a version must be indistinguishable from reading the live row — the payload is
     * stored in DATABASE shape, so a cast applied on the way in as well as out would hand payroll
     * a double-encoded array and a divisor that is a string.
     */
    public function test_a_rehydrated_version_casts_exactly_like_the_live_row(): void
    {
        $this->saveRules(['annual_divisor' => 313], '2026-05-01');

        $version = app(AttendancePolicyService::class)->asOf('2026-05-31');
        $live = app(AttendancePolicyService::class)->get();
        $keys = array_keys(AttendancePolicyService::DEFAULTS);

        $this->assertSame($live->only($keys), $version->only($keys));
        $this->assertIsInt($version->annual_divisor);
        $this->assertIsBool($version->geofence_enabled);
        $this->assertIsArray($version->violation_rules);
    }

    /** With no version on or before the date, pricing falls back to today — the old behaviour. */
    public function test_a_date_before_every_version_falls_back_to_the_live_rules(): void
    {
        $this->saveRules(['annual_divisor' => 313], '2026-05-01');

        $this->assertSame(313, app(AttendancePolicyService::class)->asOf('1999-01-01')->annual_divisor);
    }
}
