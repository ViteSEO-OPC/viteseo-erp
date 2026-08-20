<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Http\Resources\EmployeeResource;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Models\WorkSchedule;
use App\Modules\Hr\Services\AttendancePolicyService;
use App\Modules\Hr\Services\ReimbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** And — two gaps that both come down to nobody being told. */
class DuplicateClaimAndMinimumWageTest extends HrTestCase
{
    use RefreshDatabase;

    private Employee $employee;

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
        $this->employee = Employee::create([
            'user_id' => User::factory()->create()->id, 'department_id' => $dept->id,
            'employee_no' => 'E-001', 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function claimData(array $overrides = []): array
    {
        return array_merge([
            'category' => 'transport',
            'amount' => 1500,
            'expense_date' => '2026-03-05',
            'description' => 'Client visit',
        ], $overrides);
    }

    // ---: duplicate claims ----------------------------------------------------------

    public function test_a_look_alike_claim_is_refused_until_confirmed(): void
    {
        app(ReimbursementService::class)->create($this->employee, $this->claimData());

        try {
            app(ReimbursementService::class)->create($this->employee, $this->claimData());
            $this->fail('the same claim was filed twice with no warning');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already on file', implode(' ', $e->validator->errors()->all()));
        }

        $this->assertSame(1, Reimbursement::count());
    }

    /** Two genuinely separate expenses on one day must still be possible. */
    public function test_a_confirmed_duplicate_is_accepted(): void
    {
        app(ReimbursementService::class)->create($this->employee, $this->claimData());

        app(ReimbursementService::class)->create(
            $this->employee,
            $this->claimData(['acknowledge_duplicate' => true]),
        );

        $this->assertSame(2, Reimbursement::count());
    }

    public function test_a_different_amount_or_date_is_not_a_duplicate(): void
    {
        app(ReimbursementService::class)->create($this->employee, $this->claimData());

        app(ReimbursementService::class)->create($this->employee, $this->claimData(['amount' => 1600]));
        app(ReimbursementService::class)->create($this->employee, $this->claimData(['expense_date' => '2026-03-06']));

        $this->assertSame(3, Reimbursement::count());
    }

    /** Re-filing after a rejection is the correct response to being rejected. */
    public function test_a_rejected_claim_does_not_block_a_resubmission(): void
    {
        $first = app(ReimbursementService::class)->create($this->employee, $this->claimData());
        $first->update(['status' => 'rejected']);

        app(ReimbursementService::class)->create($this->employee, $this->claimData());

        $this->assertSame(2, Reimbursement::count());
    }

    /** A ₱0 claim reimburses nothing and used to be accepted. */
    public function test_a_zero_amount_claim_is_rejected_by_the_request(): void
    {
        $this->actingAsHr()
            ->postJson('/api/v1/hr/reimbursements', $this->claimData(['amount' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    // ---: minimum wage ---------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function checkFor(Employee $employee): ?array
    {
        // withPayrollChecks() — made the check opt-in so the 200-row directory does not
        // derive 200 daily rates for a field it never shows.
        return (new EmployeeResource($employee))
            ->withPayrollChecks()
            ->toArray(Request::create('/'))['minimum_wage_check'];
    }

    /**
     * No floor configured warns about nothing — never a guessed legal threshold.: the
     * DAILY RATE is still reported, because that is the number nobody can work out from the
     * monthly figure they typed, and it is most needed before anyone has entered a wage order.
     */
    public function test_no_warning_when_no_minimum_is_configured(): void
    {
        $check = $this->checkFor($this->employee);

        $this->assertNotNull($check);
        $this->assertNull($check['minimum']);
        $this->assertFalse($check['below']);
        $this->assertGreaterThan(0, $check['daily_rate']);
    }

    public function test_a_sub_minimum_daily_rate_is_flagged(): void
    {
        PayrollSetting::effectiveFrom('2026-01-01');
        PayrollSetting::current()->update(['minimum_daily_wage' => 645]);

        // ₱30,000/month over a semi-monthly period of ~11 workdays is well above ₱645, so drop the
        // salary far enough that the DERIVED daily rate must fall under it.
        $this->employee->update(['base_salary' => 5000]);

        $check = $this->checkFor($this->employee->fresh());

        $this->assertNotNull($check);
        $this->assertTrue($check['below'], 'a sub-minimum daily rate was not flagged');
        $this->assertTrue($check['flagged']);
        $this->assertSame(645.0, $check['minimum']);
        $this->assertLessThan(645.0, $check['daily_rate']);
    }

    public function test_a_compliant_salary_is_not_flagged(): void
    {
        PayrollSetting::effectiveFrom('2026-01-01');
        PayrollSetting::current()->update(['minimum_daily_wage' => 645]);

        $check = $this->checkFor($this->employee->fresh());

        $this->assertNotNull($check);
        $this->assertFalse($check['below']);
        $this->assertGreaterThanOrEqual(645.0, $check['daily_rate']);
    }

    /** It informs; it must never block a save. That is the whole distinction from validation. */
    public function test_a_sub_minimum_salary_still_saves(): void
    {
        PayrollSetting::effectiveFrom('2026-01-01');
        PayrollSetting::current()->update(['minimum_daily_wage' => 645]);

        $this->employee->update(['base_salary' => 5000]);

        $this->assertSame(5000.0, (float) $this->employee->fresh()->base_salary);
    }
}
