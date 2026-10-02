<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\Candidate;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmploymentHistory;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\Requisition;
use App\Modules\Hr\Models\RetroFlag;
use App\Modules\Hr\Models\SalaryHistory;
use App\Modules\Hr\Models\SharedLeaveAllotment;
use App\Modules\Hr\Models\SharedLeaveBalance;
use App\Modules\Hr\Services\EmployeeService;
use App\Modules\Hr\Services\RecruitmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * An internship ending in a job.
 *
 * Two halves, and the first is what made the second necessary. Hiring off the OJT board used to
 * produce a PROBATIONARY employee — the stage flow said intern, the record it handed over said
 * new hire — so nobody in HR could tell an intern from anyone else, and the leave allotment
 * keyed on employment status was the wrong one from the first day.
 *
 * The second half is the conversion itself. It is deliberately NOT "edit Employment status on
 * the Job tab", because that field writes a status and nothing else: the internship would never
 * close on the employment timeline, the first real salary would never open the salary history,
 * and the credits an intern is seeded with — zero, by allotment — would stay zero for a regular
 * employee. Each of those is asserted below.
 */
class InternConversionTest extends HrTestCase
{
    use RefreshDatabase;

    private Department $dept;

    private LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-14 09:00', config('app.timezone_local')));

        // EmployeeService::create() assigns the 'hr_employee' role to every new hire, so the
        // roles table has to exist before anything here makes an employee.
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->dept = Department::create(['name' => 'Engineering', 'code' => 'ENG']);

        $this->vacation = LeaveType::create([
            'name' => 'Vacation', 'code' => 'VL', 'is_paid' => true, 'days_per_year' => 15,
        ]);
        // An intern is entitled to nothing; a regular employee to fifteen days. The whole
        // point of the top-up is the gap between these two rows.
        LeaveTypeAllotment::create([
            'leave_type_id' => $this->vacation->id, 'employment_status' => 'ojt', 'days_per_year' => 0,
        ]);
        LeaveTypeAllotment::create([
            'leave_type_id' => $this->vacation->id, 'employment_status' => 'regular', 'days_per_year' => 15,
        ]);
    }

    private function employees(): EmployeeService
    {
        return app(EmployeeService::class);
    }

    private function recruitment(): RecruitmentService
    {
        return app(RecruitmentService::class);
    }

    /** A requisition on either pipeline. Employment type is what decides which. */
    private function requisition(string $employmentType = 'ojt', int $openings = 1): Requisition
    {
        return $this->recruitment()->createRequisition([
            'title' => $employmentType === 'ojt' ? 'Software Engineer Intern' : 'Software Engineer',
            'employment_type' => $employmentType,
            'department_id' => $this->dept->id,
            'openings' => $openings,
            'stage' => 'active',
        ]);
    }

    private function candidate(Requisition $req, array $attrs = []): Candidate
    {
        return $this->recruitment()->createCandidate([
            'requisition_id' => $req->id,
            'name' => $attrs['name'] ?? 'Kim Reyes',
            'email' => $attrs['email'] ?? 'kim.reyes@example.com',
            'source' => 'direct',
            'school' => $attrs['school'] ?? 'Adamson University',
            'required_hours' => $attrs['required_hours'] ?? 486,
        ]);
    }

    /** An intern already on the payroll, without going through the board. */
    private function intern(array $attrs = []): Employee
    {
        return $this->employees()->create([
            'name' => $attrs['name'] ?? 'Lia Tan',
            'email' => $attrs['email'] ?? 'lia.tan@example.com',
            'department_id' => $this->dept->id,
            'job_title' => 'Software Engineer Intern',
            'employment_status' => $attrs['employment_status'] ?? 'ojt',
            'ojt_required_hours' => $attrs['ojt_required_hours'] ?? 486,
            'university' => 'Adamson University',
            'hire_date' => '2026-06-01',
            'base_salary' => 0,
        ]);
    }

    // --- the handoff ----------------------------------------------------------------------------

    /**
     * THE BUG THIS FIXES. `hire()` hardcoded 'probationary', so the OJT pipeline's own output
     * contradicted it — the board said intern, the employee record said probationary.
     */
    public function test_hiring_from_an_ojt_requisition_creates_an_intern(): void
    {
        $candidate = $this->candidate($this->requisition('ojt'));

        $employee = Employee::findOrFail($this->recruitment()->hire($candidate)->hired_employee_id);

        $this->assertSame('ojt', $employee->employment_status);
        // Carried off the application, so nobody retypes what the candidate already told us.
        $this->assertSame('Adamson University', $employee->university);
        $this->assertSame(486, $employee->ojt_required_hours);
    }

    /** The other pipeline is untouched: a standard hire is still a probationary employee. */
    public function test_hiring_from_a_standard_requisition_still_creates_a_probationary_employee(): void
    {
        $req = $this->requisition('regular');
        // School and hours are OJT paperwork — the service drops them for a standard role,
        // so there is nothing to carry over here.
        $candidate = $this->candidate($req, ['name' => 'Jon Park', 'email' => 'jon.park@example.com']);

        $employee = Employee::findOrFail($this->recruitment()->hire($candidate)->hired_employee_id);

        $this->assertSame('probationary', $employee->employment_status);
        $this->assertNull($employee->ojt_required_hours);
        $this->assertNull($employee->university);
    }

    /** Requisitions share the employee's type set, so a non-regular opening hires as that type. */
    public function test_hiring_from_a_typed_requisition_lands_on_that_type(): void
    {
        foreach (['fixed_term', 'project_based', 'seasonal'] as $i => $type) {
            $candidate = $this->candidate($this->requisition($type), [
                'name' => "Hire {$i}", 'email' => "hire{$i}@example.com",
            ]);

            $employee = Employee::findOrFail($this->recruitment()->hire($candidate)->hired_employee_id);

            $this->assertSame($type, $employee->employment_status);
        }
    }

    // --- the conversion -------------------------------------------------------------------------

    public function test_converting_an_intern_sets_the_new_status_salary_and_title(): void
    {
        $intern = $this->intern();

        $converted = $this->employees()->convertIntern($intern, [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-14',
            'base_salary' => 32000,
            'job_title' => 'Junior Software Engineer',
        ]);

        $this->assertSame('regular', $converted->employment_status);
        $this->assertSame('Junior Software Engineer', $converted->job_title);
        $this->assertEquals(32000, (float) $converted->base_salary);
        // The practicum requirement described the internship, not the employee it became.
        $this->assertNull($converted->ojt_required_hours);
    }

    /** Tenure is continuous: a conversion is not a rehire, so the hire date must not move. */
    public function test_conversion_leaves_the_hire_date_alone(): void
    {
        $intern = $this->intern();

        $converted = $this->employees()->convertIntern($intern, [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-14',
            'base_salary' => 32000,
        ]);

        $this->assertSame('2026-06-01', $converted->hire_date->toDateString());
    }

    /**
     * The timeline is the record of WHEN they stopped being an intern, and it is dated by the
     * effective date rather than the day someone got round to typing it in.
     */
    public function test_conversion_closes_the_internship_and_opens_the_new_role(): void
    {
        $intern = $this->intern();
        EmploymentHistory::where('employee_id', $intern->id)->update(['title' => 'Software Engineer Intern']);

        $this->employees()->convertIntern($intern, [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-01', // backdated on purpose
            'base_salary' => 32000,
            'job_title' => 'Junior Software Engineer',
        ]);

        $rows = EmploymentHistory::where('employee_id', $intern->id)->orderBy('id')->get();

        $closed = $rows->firstWhere('title', 'Software Engineer Intern');
        $this->assertNotNull($closed);
        $this->assertSame('2026-09-01', $closed->end_date->toDateString());
        $this->assertNotSame('current', $closed->status);

        $current = $rows->firstWhere('status', 'current');
        $this->assertNotNull($current);
        $this->assertSame('Junior Software Engineer', $current->title);
        $this->assertSame('2026-09-01', $current->start_date->toDateString());
        $this->assertNull($current->end_date);
    }

    /** Their first real salary opens the salary history at the date it actually takes effect. */
    public function test_conversion_appends_the_first_real_salary(): void
    {
        $intern = $this->intern();

        $this->employees()->convertIntern($intern, [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-01',
            'base_salary' => 32000,
        ]);

        $latest = SalaryHistory::where('employee_id', $intern->id)->orderByDesc('id')->first();

        $this->assertEquals(32000, (float) $latest->base_salary);
        $this->assertSame('2026-09-01', $latest->effective_date->toDateString());
        $this->assertStringContainsString('Internship converted', $latest->reason);
    }

    // --- leave ----------------------------------------------------------------------------------

    /**
     * THE ONE A PLAIN STATUS EDIT GETS WRONG. An intern is seeded at zero, and
     * `seedLeaveBalances` is firstOrCreate — on somebody who already has rows it does nothing at
     * all. Without the top-up a converted regular kept an intern's zero days forever.
     */
    public function test_conversion_raises_leave_credits_to_the_new_status(): void
    {
        $intern = $this->intern();

        $balance = LeaveBalance::where('employee_id', $intern->id)
            ->where('leave_type_id', $this->vacation->id)
            ->firstOrFail();
        $this->assertEquals(0.0, (float) $balance->entitled_days, 'an intern starts with nothing');

        $this->employees()->convertIntern($intern, [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-14',
            'base_salary' => 32000,
        ]);

        $this->assertEquals(15.0, (float) $balance->fresh()->entitled_days);
    }

    /** Raise only. HR can grant credits by hand, and a status change is no reason to claw them back. */
    public function test_conversion_never_lowers_credits_granted_by_hand(): void
    {
        $intern = $this->intern();

        LeaveBalance::where('employee_id', $intern->id)
            ->where('leave_type_id', $this->vacation->id)
            ->update(['entitled_days' => 20]);

        $this->employees()->convertIntern($intern, [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-14',
            'base_salary' => 32000,
        ]);

        $balance = LeaveBalance::where('employee_id', $intern->id)
            ->where('leave_type_id', $this->vacation->id)
            ->firstOrFail();

        $this->assertEquals(20.0, (float) $balance->entitled_days);
    }

    /** The company may run one pooled allowance instead of per-type rows. Same rule, other table. */
    public function test_conversion_raises_the_shared_pool_when_the_company_pools_leave(): void
    {
        LeaveSetting::current()->update(['allotment_mode' => LeaveSetting::MODE_SHARED]);
        // A migration already lays down a row per employment status, so these set the two
        // this test cares about rather than inserting duplicates.
        SharedLeaveAllotment::updateOrCreate(['employment_status' => 'ojt'], ['days_per_year' => 0]);
        SharedLeaveAllotment::updateOrCreate(['employment_status' => 'regular'], ['days_per_year' => 15]);

        $intern = $this->intern();

        $pool = SharedLeaveBalance::where('employee_id', $intern->id)->firstOrFail();
        $this->assertEquals(0.0, (float) $pool->entitled_days);

        $this->employees()->convertIntern($intern, [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-14',
            'base_salary' => 32000,
        ]);

        $this->assertEquals(15.0, (float) $pool->fresh()->entitled_days);
    }

    // --- the guards -----------------------------------------------------------------------------

    /** Only an intern has an internship to end. */
    public function test_a_non_intern_cannot_be_converted(): void
    {
        $employee = $this->intern(['employment_status' => 'probationary', 'ojt_required_hours' => null]);

        $this->expectException(ValidationException::class);

        $this->employees()->convertIntern($employee, [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-14',
            'base_salary' => 32000,
        ]);
    }

    /** Converting twice is the same question asked again — the second time there is no intern left. */
    public function test_an_intern_cannot_be_converted_twice(): void
    {
        $intern = $this->intern();
        $converted = $this->employees()->convertIntern($intern, [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-14',
            'base_salary' => 32000,
        ]);

        $this->expectException(ValidationException::class);

        $this->employees()->convertIntern($converted, [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-14',
            'base_salary' => 35000,
        ]);
    }

    /** Somebody who has left is not converted into staying. */
    public function test_a_separated_intern_cannot_be_converted(): void
    {
        $intern = $this->intern();
        $this->employees()->separate($intern, [
            'separation_type' => 'voluntary',
            'separation_date' => '2026-08-31',
        ]);

        $this->expectException(ValidationException::class);

        $this->employees()->convertIntern($intern->fresh(), [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-14',
            'base_salary' => 32000,
        ]);
    }

    // --- the endpoint ---------------------------------------------------------------------------

    public function test_the_endpoint_converts_and_returns_the_updated_record(): void
    {
        $intern = $this->intern();

        $this->hrPost("/api/v1/hr/employees/{$intern->id}/convert-intern", [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-14',
            'base_salary' => 32000,
            'job_title' => 'Junior Software Engineer',
        ])
            ->assertOk()
            ->assertJsonPath('data.employment_status', 'regular')
            ->assertJsonPath('data.job_title', 'Junior Software Engineer')
            ->assertJsonPath('data.ojt_required_hours', null);
    }

    /** Converting an intern into an intern is not a conversion. */
    public function test_the_endpoint_refuses_ojt_as_the_target_status(): void
    {
        $intern = $this->intern();

        $this->hrPost("/api/v1/hr/employees/{$intern->id}/convert-intern", [
            'employment_status' => 'ojt',
            'effective_date' => '2026-09-14',
            'base_salary' => 32000,
        ])->assertStatus(422)->assertJsonValidationErrors('employment_status');
    }

    /** A conversion without a salary is the half-done edit this action exists to replace. */
    public function test_the_endpoint_requires_a_salary_and_an_effective_date(): void
    {
        $intern = $this->intern();

        $this->hrPost("/api/v1/hr/employees/{$intern->id}/convert-intern", [
            'employment_status' => 'regular',
        ])->assertStatus(422)->assertJsonValidationErrors(['effective_date', 'base_salary']);
    }

    /** HR-only, like every other lifecycle action. */
    public function test_a_plain_employee_cannot_convert_anyone(): void
    {
        $intern = $this->intern();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/hr/employees/{$intern->id}/convert-intern", [
                'employment_status' => 'regular',
                'effective_date' => '2026-09-14',
                'base_salary' => 32000,
            ])
            ->assertForbidden();
    }

    /** Not to before they joined: there is no internship to close on days they were not here. */
    public function test_the_endpoint_refuses_an_effective_date_before_the_hire_date(): void
    {
        $intern = $this->intern(); // hired 2026-06-01

        $this->hrPost("/api/v1/hr/employees/{$intern->id}/convert-intern", [
            'employment_status' => 'regular',
            'effective_date' => '2026-05-31',
            'base_salary' => 32000,
        ])->assertStatus(422)->assertJsonValidationErrors('effective_date');

        $this->assertSame('ojt', $intern->fresh()->employment_status);
    }

    // --- back-dating into payroll that already ran ----------------------------------------------

    /** A completed regular run holding a payslip for this employee. */
    private function completedRunFor(Employee $employee, string $start, string $end, bool $locked = false): PayrollRun
    {
        $run = PayrollRun::create([
            'cycle_start' => $start, 'cycle_end' => $end, 'frequency' => 'semi_monthly',
            'type' => 'regular', 'status' => 'completed', 'run_at' => now(),
        ]);
        if ($locked) {
            $run->forceFill(['locked_at' => now()])->save();
        }
        Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'base_salary' => 0, 'gross' => 0,
            'total_deductions' => 0, 'net_pay' => 0, 'status' => 'computed',
        ]);

        return $run;
    }

    /**
     * THE DEAD END THIS FIXES. The refusal is a question the conversion screen answers by
     * resending with the acknowledgement — and it can only recognise the question on the field
     * it sent. It used to land on `salary_effective_date`, a field a conversion does not have.
     */
    public function test_a_conversion_backdated_into_completed_payroll_asks_on_the_effective_date(): void
    {
        $intern = $this->intern();
        $this->completedRunFor($intern, '2026-09-01', '2026-09-15');

        $response = $this->hrPost("/api/v1/hr/employees/{$intern->id}/convert-intern", [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-01',
            'base_salary' => 32000,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('effective_date');
        $this->assertStringContainsString(
            'Sep 1–Sep 15, 2026',
            $response->json('errors.effective_date.0'),
            'the question must name the period it would reprice',
        );
        // Asked before anything is written: no half-converted intern behind a refusal.
        $fresh = $intern->fresh();
        $this->assertSame('ojt', $fresh->employment_status);
        $this->assertEquals(0, (float) $fresh->base_salary);
    }

    public function test_an_acknowledged_backdated_conversion_goes_through(): void
    {
        $intern = $this->intern();
        $this->completedRunFor($intern, '2026-09-01', '2026-09-15');

        $this->hrPost("/api/v1/hr/employees/{$intern->id}/convert-intern", [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-01',
            'base_salary' => 32000,
            'acknowledge_repricing' => true,
        ])->assertOk()->assertJsonPath('data.employment_status', 'regular');

        $this->assertSame(
            '2026-09-01',
            SalaryHistory::where('employee_id', $intern->id)->latest('id')->first()->effective_date->toDateString(),
        );
    }

    /** Into a run that is locked, the difference is owed and must be flagged for an adjustment run. */
    public function test_an_acknowledged_conversion_into_a_locked_run_is_flagged_as_owed(): void
    {
        $intern = $this->intern();
        $this->completedRunFor($intern, '2026-09-01', '2026-09-15', locked: true);

        $this->hrPost("/api/v1/hr/employees/{$intern->id}/convert-intern", [
            'employment_status' => 'regular',
            'effective_date' => '2026-09-01',
            'base_salary' => 32000,
            'acknowledge_repricing' => true,
        ])->assertOk();

        $flag = RetroFlag::where('employee_id', $intern->id)->first();
        $this->assertNotNull($flag, 'a conversion back-dated into a locked run left no amount owed');
        $this->assertSame(RetroFlag::OWED, $flag->direction);
        $this->assertSame(SalaryHistory::class, $flag->flaggable_type);
    }
}
