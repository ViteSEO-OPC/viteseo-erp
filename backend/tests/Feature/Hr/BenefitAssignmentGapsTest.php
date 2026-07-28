<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\BenefitProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Slice B5 — the assignment and validation gaps, batched.
 *
 * None of these were costing money on the live data. They are the kind of gap that costs money the
 * first time someone uses the feature as designed: an eligibility rule that only half applies, a
 * dropdown typo that enrols someone on nothing, an allowance saved without an amount.
 *
 * One targeted assertion each.
 */
class BenefitAssignmentGapsTest extends HrTestCase
{
    use RefreshDatabase;

    private const TIERS = [
        ['name' => 'Individual', 'monthly_premium' => 1500, 'employee_share' => 0],
        ['name' => 'Family', 'monthly_premium' => 3000, 'employee_share' => 500],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Department::create(['name' => 'Ops', 'code' => 'OPS']);
    }

    private function employee(string $no, string $status = 'regular'): Employee
    {
        return Employee::create([
            'user_id' => User::factory()->create()->id,
            'department_id' => Department::first()->id,
            'employee_no' => $no, 'job_title' => 'Staff', 'hire_date' => '2020-01-01',
            'base_salary' => 30000, 'pay_frequency' => 'semi_monthly',
            'is_management' => false, 'employment_status' => $status,
        ]);
    }

    private function hmo(string $eligibility = 'all'): BenefitProgram
    {
        return BenefitProgram::create([
            'name' => 'HMO', 'category' => 'health', 'kind' => 'company',
            'tiers' => self::TIERS, 'eligibility' => $eligibility,
            'deduction_cadence' => 'monthly', 'is_active' => true,
        ]);
    }

    // --- 1. the eligibility rule must apply to company plans too ---------------------

    public function test_a_regular_only_plan_cannot_be_assigned_to_a_probationary_employee(): void
    {
        $probationary = $this->employee('E-PROB', 'probationary');
        $hmo = $this->hmo('regular');

        app(BenefitProgramService::class)->assign([
            'employee_ids' => [$probationary->id],
            'hmo' => ['program_id' => $hmo->id, 'tiers' => [$probationary->id => 'Family']],
        ]);

        $this->assertSame(0, $hmo->employees()->count(), 'the eligibility rule was ignored for HMOs');
    }

    public function test_an_eligible_employee_is_still_assigned(): void
    {
        $regular = $this->employee('E-REG', 'regular');
        $hmo = $this->hmo('regular');

        app(BenefitProgramService::class)->assign([
            'employee_ids' => [$regular->id],
            'hmo' => ['program_id' => $hmo->id, 'tiers' => [$regular->id => 'Family']],
        ]);

        $this->assertSame(1, $hmo->employees()->count());
    }

    // --- 2. a mistyped tier must not enrol anyone on a null share --------------------

    public function test_an_unknown_tier_enrols_nobody(): void
    {
        $employee = $this->employee('E-001');
        $hmo = $this->hmo();

        app(BenefitProgramService::class)->assign([
            'employee_ids' => [$employee->id],
            // "Familly" — a typo. Before B5 this enrolled them with employee_share = NULL,
            // i.e. the company silently paying the whole premium.
            'hmo' => ['program_id' => $hmo->id, 'tiers' => [$employee->id => 'Familly']],
        ]);

        $this->assertSame(0, $hmo->employees()->count());
    }

    public function test_a_valid_tier_carries_its_share(): void
    {
        $employee = $this->employee('E-001');
        $hmo = $this->hmo();

        app(BenefitProgramService::class)->assign([
            'employee_ids' => [$employee->id],
            'hmo' => ['program_id' => $hmo->id, 'tiers' => [$employee->id => 'Family']],
        ]);

        $pivot = $hmo->employees()->first()->pivot;
        $this->assertSame('Family', $pivot->tier);
        $this->assertSame('500.00', $pivot->employee_share);
    }

    // --- 4. an allowance needs an amount --------------------------------------------

    public function test_an_allowance_cannot_be_created_without_an_amount(): void
    {
        $this->hrPost('/api/v1/hr/benefit-programs', [
            'name' => 'Meal', 'kind' => 'allowance', 'category' => 'allowance', 'amount' => null,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_a_company_plan_may_have_no_amount(): void
    {
        $this->hrPost('/api/v1/hr/benefit-programs', [
            'name' => 'HMO', 'kind' => 'company', 'category' => 'health', 'amount' => null,
            'tiers' => self::TIERS,
        ])->assertCreated();
    }

    /** Clearing the amount of an existing allowance must fail too — `kind` is absent on a PATCH. */
    public function test_an_existing_allowances_amount_cannot_be_cleared(): void
    {
        $allowance = BenefitProgram::create([
            'name' => 'Meal', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 2000, 'frequency' => 'monthly', 'is_active' => true,
        ]);

        $this->actingAsHr()
            ->patchJson("/api/v1/hr/benefit-programs/{$allowance->id}", ['amount' => null])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    // --- 5. the audit trail must say who ---------------------------------------------

    public function test_the_enrolment_log_records_the_employee_ids(): void
    {
        $a = $this->employee('E-001');
        $b = $this->employee('E-002');
        $allowance = BenefitProgram::create([
            'name' => 'Meal', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 2000, 'frequency' => 'monthly', 'eligibility' => 'all', 'is_active' => true,
        ]);

        app(BenefitProgramService::class)->assign([
            'employee_ids' => [$a->id, $b->id], 'allowance_ids' => [$allowance->id],
        ]);

        $log = AuditLog::where('action', 'enrolled')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString("ids: {$a->id}, {$b->id}", $log->description);
    }

    /** The old log counted employees SELECTED, not enrolled — with a rule in play they differ. */
    public function test_the_log_does_not_claim_more_enrolments_than_happened(): void
    {
        $regular = $this->employee('E-REG', 'regular');
        $probationary = $this->employee('E-PROB', 'probationary');
        $allowance = BenefitProgram::create([
            'name' => 'Rice', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 2000, 'frequency' => 'monthly', 'eligibility' => 'regular', 'is_active' => true,
        ]);

        app(BenefitProgramService::class)->assign([
            'employee_ids' => [$regular->id, $probationary->id], 'allowance_ids' => [$allowance->id],
        ]);

        $log = AuditLog::where('action', 'enrolled')->latest('id')->first();
        $this->assertStringContainsString('1 of 2 selected', $log->description);
    }

    // --- 6. dropping and re-adding must not destroy the pivot ------------------------

    /**
     * Latent today (the only caller is unreachable from the UI) and real the moment it is wired up.
     */
    public function test_editing_a_programme_keeps_the_pivot_of_someone_who_stays(): void
    {
        $stays = $this->employee('E-STAY');
        $goes = $this->employee('E-GO');
        $hmo = $this->hmo();
        $hmo->employees()->attach([
            $stays->id => ['tier' => 'Family', 'employee_share' => 500],
            $goes->id => ['tier' => 'Individual', 'employee_share' => 0],
        ]);

        app(BenefitProgramService::class)->update($hmo, ['employee_ids' => [$stays->id]]);

        $this->assertSame('Family', $hmo->employees()->first()->pivot->tier);
        $this->assertSame(1, $hmo->employees()->count(), 'the dropped employee is still removed');
    }

    /** The employee is re-added on the programme default, not silently on someone else's tier. */
    public function test_a_re_added_employee_comes_back_cleanly(): void
    {
        $employee = $this->employee('E-001');
        $hmo = $this->hmo();
        $hmo->employees()->attach([$employee->id => ['tier' => 'Family', 'employee_share' => 500]]);

        $service = app(BenefitProgramService::class);
        $service->update($hmo, ['employee_ids' => []]);
        $service->update($hmo, ['employee_ids' => [$employee->id]]);

        $this->assertSame(1, $hmo->employees()->count());
    }
}
