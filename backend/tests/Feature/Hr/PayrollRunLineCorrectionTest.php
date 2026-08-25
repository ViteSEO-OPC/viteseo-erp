<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * ONE LINE, SET TO ONE FIGURE, ACROSS A WHOLE PERIOD.
 *
 * The correction that is one decision rather than N identical ones — a charge the system applied
 * to everybody through an outage, a benefit share priced wrong for the payroll. It reaches every
 * payslip in the run, or only the people named, and it reports whose payslip it could not touch
 * rather than counting them as done.
 */
class PayrollRunLineCorrectionTest extends HrTestCase
{
    use RefreshDatabase;

    /** @var list<Employee> */
    private array $employees = [];

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
        $tz = config('app.timezone_local');

        foreach (['E-001', 'E-002', 'E-003'] as $no) {
            $employee = Employee::create([
                'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
                'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
                'employment_status' => 'regular',
                'base_salary' => 40000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
            ]);

            // Everyone absent on the 4th — the shape a period-wide waiver exists for.
            for ($day = Carbon::parse('2026-08-01', $tz); $day->day <= 15; $day->addDay()) {
                if ($day->isWeekend() || $day->day === 4) {
                    continue;
                }

                Attendance::create([
                    'employee_id' => $employee->id,
                    'work_date' => $day->toDateString(),
                    'time_in' => $day->copy()->setTime(8, 0)->utc(),
                    'time_out' => $day->copy()->setTime(17, 0)->utc(),
                    'status' => 'present',
                ]);
            }

            $this->employees[] = $employee;
        }
    }

    private function payrollRun(): PayrollRun
    {
        return app(PayrollService::class)->run('2026-08-01', '2026-08-15', 'semi_monthly', $this->hrUser());
    }

    /** @return array{applied: int, skipped: list<string>} */
    private function correctRun(PayrollRun $run, array $payload): array
    {
        $response = $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payroll/runs/{$run->id}/line-corrections", $payload)
            ->assertOk();

        return ['applied' => $response->json('meta.applied'), 'skipped' => $response->json('meta.skipped')];
    }

    /** What one payslip's Absences row reads. */
    private function absences(Payslip $slip): float
    {
        $rows = $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/payslips/{$slip->id}")
            ->assertOk()
            ->json('data.deductions_breakdown');

        return (float) collect($rows)->firstWhere('key', 'absent')['amount'];
    }

    /** THE WAIVER: one charge, taken off everybody's payslip in one decision. */
    public function test_a_correction_reaches_every_payslip_in_the_period(): void
    {
        $run = $this->payrollRun();

        foreach ($run->payslips as $slip) {
            $this->assertGreaterThan(0, $this->absences($slip));
        }

        $result = $this->correctRun($run, [
            'target_key' => 'absent',
            'amount' => 0,
            'reason' => 'Office closed on 4 August — nobody was absent',
        ]);

        $this->assertSame(3, $result['applied']);
        $this->assertSame([], $result['skipped']);

        foreach ($run->fresh()->payslips as $slip) {
            $this->assertSame(0.0, $this->absences($slip));
            $this->assertGreaterThan(0, (float) $slip->net_pay);
        }
    }

    /** NAMING PEOPLE touches those payslips and no others. */
    public function test_naming_employees_corrects_only_their_payslips(): void
    {
        $run = $this->payrollRun();
        $chosen = $this->employees[0];
        $untouched = $run->payslips->firstWhere('employee_id', $this->employees[1]->id);
        $before = $this->absences($untouched);

        $result = $this->correctRun($run, [
            'target_key' => 'absent',
            'amount' => 0,
            'reason' => 'Approved leave filed late for this one employee',
            'employee_ids' => [$chosen->id],
        ]);

        $this->assertSame(1, $result['applied']);
        $this->assertSame(0.0, $this->absences($run->fresh()->payslips->firstWhere('employee_id', $chosen->id)));
        $this->assertSame($before, $this->absences($untouched->fresh()));
    }

    /**
     * A PAYSLIP THE CORRECTION COULD NOT TOUCH IS REPORTED, not counted as done.
     *
     * "Corrected 1 payslip" reads as "corrected everyone" when it was 1 of 3, and the two it
     * could not reach are the ones that need a person.
     */
    public function test_payslips_without_the_line_are_reported_rather_than_swallowed(): void
    {
        $run = $this->payrollRun();

        // A named deduction only ONE employee carries — the others have no such line at all.
        $only = $run->payslips->first();
        $only->update([
            'other_deductions' => 750.0,
            'other_deductions_breakdown' => [['name' => 'HMO Share', 'amount' => 750.0]],
            'engine_snapshot' => null,
        ]);

        $result = $this->correctRun($run, [
            'target_key' => 'other:hmo-share',
            'amount' => 500.0,
            'reason' => 'HMO share was billed at the old rate',
        ]);

        $this->assertSame(1, $result['applied']);
        $this->assertCount(2, $result['skipped']);
        $this->assertStringContainsString('no such line', implode(' ', $result['skipped']));
        $this->assertSame(500.0, round((float) $only->fresh()->other_deductions_breakdown[0]['amount'], 2));
    }

    /** A correction that corrects nothing is refused, rather than reporting a run it did not change. */
    public function test_a_correction_that_changes_nothing_is_refused(): void
    {
        $run = $this->payrollRun();

        $this->actingAs($this->hrUser())
            ->postJson("/api/v1/hr/payroll/runs/{$run->id}/line-corrections", [
                'target_key' => 'late',
                'amount' => 0,
                'reason' => 'Nobody was late this period',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_key');
    }

    /** The wording alone, with every figure left exactly as it was. */
    public function test_a_label_only_correction_renames_the_line_across_the_period(): void
    {
        $run = $this->payrollRun();
        $before = $this->absences($run->payslips->first());

        $result = $this->correctRun($run, [
            'target_key' => 'absent',
            'label' => 'Unpaid Days',
            'reason' => 'Payroll calls these unpaid days, not absences',
        ]);

        $this->assertSame(3, $result['applied']);

        $rows = $this->actingAs($this->hrUser())
            ->getJson("/api/v1/hr/payslips/{$run->payslips->first()->id}")
            ->assertOk()
            ->json('data.deductions_breakdown');

        $row = collect($rows)->firstWhere('key', 'absent');

        $this->assertSame('Unpaid Days', $row['name']);
        $this->assertSame('Absences', $row['original_name']);
        $this->assertSame($before, (float) $row['amount']);
    }
}
