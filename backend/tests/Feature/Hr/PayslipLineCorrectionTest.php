<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayslipAmendment;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * CORRECTING A LINE THAT IS ALREADY ON A PAYSLIP, end to end.
 *
 * The correction has two halves that have to agree: the service moves money into a payslip column,
 * and the document splits that column back out into rows. Every day-costing attendance charge
 * shares ONE column, so a correction that the split cannot attribute lands on whichever row the
 * attendance ledger happens to weight — which is the bug these tests exist to hold shut.
 */
class PayslipLineCorrectionTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);

        $this->travelTo(Carbon::parse('2026-08-20 09:00', config('app.timezone_local')));

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
            'employment_status' => 'regular',
            'base_salary' => 40000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /** Present every workday of the cutoff except the dates named. */
    private function attend(array $absentOn = []): void
    {
        $tz = config('app.timezone_local');

        for ($day = Carbon::parse('2026-08-01', $tz); $day->day <= 15; $day->addDay()) {
            if ($day->isWeekend() || in_array($day->toDateString(), $absentOn, true)) {
                continue;
            }

            Attendance::create([
                'employee_id' => $this->employee->id,
                'work_date' => $day->toDateString(),
                'time_in' => $day->copy()->setTime(8, 0)->utc(),
                'time_out' => $day->copy()->setTime(17, 0)->utc(),
                'status' => 'present',
            ]);
        }
    }

    private function payrollRun(): PayrollRun
    {
        return app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
    }

    /**
     * The Deductions table as HR sees it, straight off the API.
     *
     * @return array<string, float>
     */
    private function rows(Payslip $slip): array
    {
        $response = $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/payslips/{$slip->id}")
            ->assertOk();

        return collect($response->json('data.deductions_breakdown'))
            ->mapWithKeys(fn (array $row) => [$row['key'] => (float) $row['amount']])
            ->all();
    }

    /**
     * The Compensation table as HR sees it, straight off the API.
     *
     * @return array<string, array<string, mixed>>
     */
    private function earnings(Payslip $slip): array
    {
        $response = $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/payslips/{$slip->id}")
            ->assertOk();

        return collect($response->json('data.earnings_breakdown'))
            ->keyBy('key')
            ->all();
    }

    private function correct(Payslip $slip, string $key, float $amount): Payslip
    {
        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payslips/{$slip->id}/line-corrections", [
                'target_key' => $key,
                'amount' => $amount,
                'reason' => 'Suspension the attendance ledger never recorded',
            ])
            ->assertOk();

        return $slip->fresh();
    }

    /**
     * THE REPORTED BUG. A charge with no attendance lines behind it is still a row on the document
     * and still correctable — and the figure has to print on the row HR typed it against.
     */
    public function test_a_correction_lands_on_the_row_it_names_and_not_on_absences(): void
    {
        $this->attend(absentOn: ['2026-08-04']);

        $slip = $this->payrollRun()->payslips()->first();
        $absenceCharge = $this->rows($slip)['absent'];

        $this->assertGreaterThan(0, $absenceCharge, 'the fixture needs a real absence to be a test');

        $rows = $this->rows($this->correct($slip, 'suspended_unpaid', 1000.0));

        $this->assertSame(1000.0, $rows['suspended_unpaid']);
        // Before the fix this read $absenceCharge + 1000: the suspension charge went into the
        // shared column and was apportioned straight onto the only row carrying days.
        $this->assertSame($absenceCharge, $rows['absent']);
    }

    /** The same on a payslip with nothing charged at all, which takes the legacy fallback path. */
    public function test_a_correction_on_a_clean_payslip_does_not_become_an_absence(): void
    {
        $this->attend();

        $slip = $this->payrollRun()->payslips()->first();
        $this->assertSame(0.0, $this->rows($slip)['absent']);

        $rows = $this->rows($this->correct($slip, 'half_day', 800.0));

        $this->assertSame(800.0, $rows['half_day']);
        $this->assertSame(0.0, $rows['absent']);
    }

    /** The correction is real money, not a relabelling: the payslip has to actually pay less. */
    public function test_the_corrected_charge_comes_off_the_net(): void
    {
        $this->attend();

        $slip = $this->payrollRun()->payslips()->first();
        $before = (float) $slip->net_pay;

        $after = $this->correct($slip, 'suspended_unpaid', 1000.0);

        $this->assertLessThan($before, (float) $after->net_pay);
        $this->assertSame(round((float) $slip->absence_deduction + 1000.0, 2), round((float) $after->absence_deduction, 2));
    }

    /**
     * A period that paid no overtime still offers an Overtime row, keyed — which is the whole of
     * what makes it correctable. Only Basic Pay used to arrive with a key, so it was the only
     * line on the Compensation table HR could click.
     */
    public function test_an_unpaid_earning_row_is_still_a_keyed_line_hr_can_correct(): void
    {
        $this->attend();

        $slip = $this->payrollRun()->payslips()->first();
        $lines = $this->earnings($slip);

        foreach (['overtime', 'night_diff', 'rest_day', 'holiday'] as $key) {
            $this->assertArrayHasKey($key, $lines, "{$key} should be correctable even at zero");
            $this->assertSame(0.0, (float) $lines[$key]['amount']);
        }

        $gross = (float) $slip->gross;
        $corrected = $this->correct($slip, 'overtime', 900.0);

        $this->assertSame(900.0, (float) $this->earnings($corrected)['overtime']['amount']);
        $this->assertSame(round($gross + 900.0, 2), round((float) $corrected->gross, 2));
    }

    /** Correcting a figure down to zero must not delete the line it was corrected on. */
    public function test_an_earning_corrected_down_to_zero_stays_visible_and_revertible(): void
    {
        $this->attend();

        // A real claim the run folds in, so the line under test is one the ENGINE produced —
        // a hand-written figure has no engine value to be corrected away from.
        Reimbursement::create([
            'employee_id' => $this->employee->id,
            'category' => 'transport',
            'expense_date' => '2026-08-04',
            'amount' => 450.00,
            'description' => 'Taxi to the client site',
            'status' => 'approved',
        ]);

        $slip = $this->payrollRun()->payslips()->first();

        $this->assertSame(450.0, (float) $this->earnings($slip)['reimbursement']['amount']);

        $line = $this->earnings($this->correct($slip, 'reimbursement', 0.0))['reimbursement'];

        $this->assertSame(0.0, (float) $line['amount']);
        // The struck-through original, and with it the way back to what payroll computed.
        $this->assertSame(450.0, (float) $line['original_amount']);
    }

    /**
     * A REASON IS REQUIRED; ITS LENGTH IS NOT. A floor cannot tell a real reason from a padded
     * one, so all it did was refuse the shortest true ones.
     */
    public function test_a_short_reason_is_accepted_but_an_empty_one_is_not(): void
    {
        $this->attend();

        $slip = $this->payrollRun()->payslips()->first();

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payslips/{$slip->id}/line-corrections", [
                'target_key' => 'overtime', 'amount' => 500.0, 'reason' => 'typo',
            ])
            ->assertOk();

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payslips/{$slip->id}/line-corrections", [
                'target_key' => 'night_diff', 'amount' => 200.0, 'reason' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    /**
     * TWO ADDED DEDUCTIONS UNDER THE SAME LABEL ARE TWO LINES.
     *
     * Every other row in the deductions breakdown is keyed by a slug of its name, so two
     * corrections entered under one label used to slug to a single key: correcting either edited
     * the first and marked both as corrected.
     */
    public function test_two_added_deductions_with_the_same_label_are_separately_correctable(): void
    {
        $this->attend();

        $run = $this->payrollRun();
        $slip = $run->payslips()->first();

        foreach ([300.0, 450.0] as $amount) {
            $this->actingAs($this->hrUser())
                ->postJson("/api/v1/hr/payroll/runs/{$run->id}/amendments", [
                    'kind' => 'deduction',
                    'reason' => 'Uniform not deducted this cutoff',
                    'lines' => [[
                        'employee_id' => $this->employee->id,
                        'amount' => $amount,
                        'note' => 'Uniform',
                    ]],
                ])
                ->assertOk();
        }

        $rows = collect($this->rows($slip->fresh()))
            ->filter(fn (float $amount, string $key) => str_starts_with($key, 'other:'));

        $this->assertCount(2, $rows, 'the two corrections must be two distinct rows');
        $this->assertEqualsCanonicalizing([300.0, 450.0], array_values($rows->all()));

        // Keyed by the amendment that made each one. Slugging the label collapsed both onto
        // `other:correction-uniform`, so the document had two rows answering to one key.
        foreach ($rows->keys() as $key) {
            $this->assertMatchesRegularExpression('/^other:amend-\d+$/', (string) $key);
        }
    }

    /**
     * AN ADDED LINE IS NOT A CORRECTABLE LINE — on either side of the document.
     *
     * A correction replaces a figure the ENGINE produced, and an added line has no engine figure
     * by definition: it is not in the payslip's engine snapshot, which is what a correction is
     * measured against. Both refusals are stated here so the frontend's decision not to offer the
     * control on these rows stays tied to what the API will actually accept.
     */
    public function test_a_line_an_amendment_added_cannot_be_corrected_in_place(): void
    {
        $this->attend();

        $run = $this->payrollRun();
        $slip = $run->payslips()->first();

        foreach ([['pay', 'amendment'], ['deduction', 'other']] as [$kind, $side]) {
            $this->actingAs($this->hrUser())
                ->postJson("/api/v1/hr/payroll/runs/{$run->id}/amendments", [
                    'kind' => $kind,
                    'reason' => 'A line the engine never priced',
                    'lines' => [['employee_id' => $this->employee->id, 'amount' => 250.0, 'note' => "Extra {$side}"]],
                ])
                ->assertOk();
        }

        $fresh = $slip->fresh();

        $earningKey = (string) collect($this->earnings($fresh))->keys()
            ->first(fn (string $key) => str_starts_with($key, 'amendment:'));
        $deductionKey = (string) collect($this->rows($fresh))->keys()
            ->first(fn (string $key) => str_starts_with($key, 'other:amend-'));

        foreach ([$earningKey, $deductionKey] as $key) {
            $this->assertNotSame('', $key);

            $this->actingAs($this->hrUser())
                ->postJson("/api/v1/hr/payslips/{$fresh->id}/line-corrections", [
                    'target_key' => $key, 'amount' => 100.0, 'reason' => 'nope',
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('target_key');
        }
    }

    /**
     * WHEN THE ENGINE CHANGES ITS MIND UNDERNEATH A CORRECTION, THE RECOMPUTE SAYS SO.
     *
     * The correction is absolute and still wins, so nothing on the payslip looks wrong — which is
     * precisely why it has to be said. It used to reach nobody but the audit log.
     */
    public function test_a_recompute_reports_a_correction_the_engine_no_longer_agrees_with(): void
    {
        $this->attend(absentOn: ['2026-08-04']);

        $run = $this->payrollRun();
        $slip = $run->payslips()->first();

        // HR overrules the absence charge the engine computed…
        $this->correct($slip, 'absent', 1500.0);

        // …and the attendance behind it then changes, so a recompute prices that line differently.
        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-04',
            'time_in' => Carbon::parse('2026-08-04 08:00', config('app.timezone_local'))->utc(),
            'time_out' => Carbon::parse('2026-08-04 17:00', config('app.timezone_local'))->utc(),
            'status' => 'present',
        ]);

        $notes = $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payroll/runs/{$run->id}/rerun", [
                'reason' => 'Attendance corrected for 4 August',
            ])
            ->assertOk()
            ->json('data.override_notes');

        $this->assertNotEmpty($notes, 'the recompute must report what it found under the correction');
        $this->assertStringContainsString('Absences', implode(' ', $notes));

        // And the correction still stands — this is a message, not a rollback.
        $this->assertSame(1500.0, $this->rows($slip->fresh())['absent']);
    }

    /**
     * ACKNOWLEDGING A DRIFTED CORRECTION SILENCES IT — and only it.
     *
     * The drift note cannot tell "reviewed and still right" from "nobody looked", so it repeats on
     * every run until HR says which. Confirming must move NO money: the correction was already
     * winning, and the payslip is expected to come out of this byte-identical.
     */
    public function test_acknowledging_a_drifted_correction_stops_it_being_reported(): void
    {
        $this->attend(absentOn: ['2026-08-04']);

        $run = $this->payrollRun();
        $slip = $run->payslips()->first();

        $this->correct($slip, 'absent', 1500.0);

        Attendance::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-04',
            'time_in' => Carbon::parse('2026-08-04 08:00', config('app.timezone_local'))->utc(),
            'time_out' => Carbon::parse('2026-08-04 17:00', config('app.timezone_local'))->utc(),
            'status' => 'present',
        ]);

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payroll/runs/{$run->id}/rerun", ['reason' => 'Attendance corrected'])
            ->assertOk();

        $override = $slip->fresh()->amendments()->whereNotNull('target_key')->firstOrFail();
        $this->assertTrue($override->hasDrifted(), 'the run must have left this line drifted');

        $netBefore = (float) $slip->fresh()->net_pay;

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payslips/{$slip->id}/line-corrections/{$override->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.amendments.0.has_drifted', false);

        // The money is untouched, and HR's figure is still the one on the line.
        $this->assertSame($netBefore, (float) $slip->fresh()->net_pay);
        $this->assertSame(1500.0, $this->rows($slip->fresh())['absent']);

        // And the next run no longer reports what HR has now seen.
        $notes = $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payroll/runs/{$run->id}/rerun", ['reason' => 'Routine recompute'])
            ->assertOk()
            ->json('data.override_notes');

        $this->assertSame([], $notes);
    }

    /**
     * An ADDED line is not a correction to one, so there is no engine figure under it to have
     * drifted — acknowledging one is refused rather than silently writing a null baseline.
     */
    public function test_acknowledging_something_that_is_not_a_line_correction_is_refused(): void
    {
        $this->attend();

        $slip = $this->payrollRun()->payslips()->first();

        $added = PayslipAmendment::create([
            'payslip_id' => $slip->id,
            'kind' => 'pay',
            'target_key' => null, // an added line, not an override of a computed one
            'amount' => 500.0,
            'tax' => 0,
            'reason' => 'Referral bonus',
            'issued_on' => '2026-08-15',
        ]);

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payslips/{$slip->id}/line-corrections/{$added->id}/acknowledge")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('override');
    }

    /** A recompute that finds nothing says nothing — an empty list, not a reassuring sentence. */
    public function test_a_clean_recompute_reports_no_drift(): void
    {
        $this->attend();

        $run = $this->payrollRun();

        $notes = $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payroll/runs/{$run->id}/rerun", ['reason' => 'Routine recompute'])
            ->assertOk()
            ->json('data.override_notes');

        $this->assertSame([], $notes);
    }

    /**
     * THE AGGREGATE ALLOWANCE LINE IS A LINE. A payslip from before the allowance split has no
     * itemised components, so the document prints its `allowances` column as one row — and that
     * row used to be clickable but unsaveable, refused with "this payslip no longer has that
     * line" about a line plainly on the payslip.
     */
    public function test_the_aggregate_allowance_line_on_a_legacy_payslip_can_be_corrected(): void
    {
        $this->attend();

        $slip = $this->payrollRun()->payslips()->first();

        // A legacy shape: a total, nothing itemised behind it, and no engine snapshot — which is
        // exactly the payslip this fallback exists for.
        $slip->update([
            'allowances' => 3000.0,
            'gross' => round((float) $slip->gross + 3000.0, 2),
            'allowances_breakdown' => [],
            'engine_snapshot' => null,
        ]);

        $this->assertSame(3000.0, (float) $this->earnings($slip->fresh())['allowance:allowances']['amount']);

        $corrected = $this->correct($slip->fresh(), 'allowance:allowances', 2500.0);
        $line = $this->earnings($corrected)['allowance:allowances'];

        $this->assertSame(2500.0, (float) $line['amount']);
        $this->assertSame(3000.0, (float) $line['original_amount']);
        // The column IS the line, so the correction has to land on it.
        $this->assertSame(2500.0, round((float) $corrected->allowances, 2));
    }

    /** And an ITEMISED allowance still resolves by name, which is the case that always worked. */
    public function test_an_itemised_allowance_component_is_still_corrected_by_name(): void
    {
        $this->attend();

        $slip = $this->payrollRun()->payslips()->first();
        $slip->update([
            'allowances' => 2000.0,
            'gross' => round((float) $slip->gross + 2000.0, 2),
            'allowances_breakdown' => [['name' => 'Rice Subsidy', 'amount' => 2000.0]],
            'engine_snapshot' => null,
        ]);

        $corrected = $this->correct($slip->fresh(), 'allowance:rice-subsidy', 1500.0);

        $this->assertSame(1500.0, (float) $this->earnings($corrected)['allowance:rice-subsidy']['amount']);
        // The named component itself moved, not just the total above it.
        $this->assertSame(1500.0, round((float) $corrected->allowances_breakdown[0]['amount'], 2));
        $this->assertSame(1500.0, round((float) $corrected->allowances, 2));
    }

    /** Putting it back restores both the row and the money. */
    public function test_removing_the_correction_puts_the_row_and_the_net_back(): void
    {
        $this->attend();

        $slip = $this->payrollRun()->payslips()->first();
        $before = (float) $slip->net_pay;

        $corrected = $this->correct($slip, 'suspended_unpaid', 1000.0);
        $override = $corrected->amendments()->whereNotNull('target_key')->firstOrFail();

        $this->actingAs($this->hrUser())
            ->deleteJson("/api/v1/hr/payslips/{$corrected->id}/line-corrections/{$override->id}")
            ->assertOk();

        $restored = $slip->fresh();

        $this->assertSame($before, (float) $restored->net_pay);
        $this->assertSame(0.0, $this->rows($restored)['suspended_unpaid']);
    }
}
