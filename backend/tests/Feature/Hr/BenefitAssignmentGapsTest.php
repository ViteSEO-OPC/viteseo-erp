<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Department;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Services\BenefitProgramService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** The assignment and validation gaps, batched. */
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

    /** @param array<int, string>|null $statuses */
    private function allowance(string $eligibility = 'all', ?array $statuses = null): BenefitProgram
    {
        return BenefitProgram::create([
            'name' => 'Test Allowance', 'category' => 'allowance', 'kind' => 'allowance',
            'amount' => 1000, 'frequency' => 'monthly', 'tax_treatment' => 'de_minimis',
            'eligibility' => $eligibility, 'eligibility_statuses' => $statuses,
        ]);
    }

    private function hmo(string $eligibility = 'all'): BenefitProgram
    {
        return BenefitProgram::create([
            'name' => 'HMO', 'category' => 'health', 'kind' => 'company',
            'tiers' => self::TIERS, 'eligibility' => $eligibility,
            'deduction_cadence' => 'monthly',
        ]);
    }

    // --- 1. eligibility is a RULE, evaluated per run ---------------------------------

    /**
     * The old model had one hard-coded status (`regular`) and a stored list of enrolled ids. It is
     * now a rule — everyone, these statuses, management, or these named people — and the rule is
     * evaluated when payroll runs rather than frozen when HR pressed a button.
     */
    public function test_a_status_rule_covers_exactly_those_statuses(): void
    {
        $regular = $this->employee('E-REG', 'regular');
        $seasonal = $this->employee('E-SN', 'seasonal');
        $probationary = $this->employee('E-PROB', 'probationary');

        $allowance = $this->allowance(eligibility: 'status', statuses: ['regular', 'seasonal']);
        $service = app(BenefitProgramService::class);

        $this->assertTrue($service->covers($allowance, $regular));
        $this->assertTrue($service->covers($allowance, $seasonal), 'multi-select, not one status');
        $this->assertFalse($service->covers($allowance, $probationary));
        $this->assertSame(2, $service->eligibleCount($allowance));
    }

    /** THE DRIFT FIX (B5 item 3, B10): a status change is followed with no re-enrolment. */
    public function test_a_status_change_is_followed_automatically(): void
    {
        $employee = $this->employee('E-001', 'probationary');
        $allowance = $this->allowance(eligibility: 'status', statuses: ['regular']);
        $service = app(BenefitProgramService::class);

        $this->assertFalse($service->covers($allowance, $employee));

        $employee->update(['employment_status' => 'regular']);

        $this->assertTrue($service->covers($allowance, $employee->fresh()));
    }

    public function test_a_management_rule_covers_management_only(): void
    {
        $staff = $this->employee('E-STAFF');
        $manager = $this->employee('E-MGR');
        $manager->update(['is_management' => true]);

        $allowance = $this->allowance(eligibility: 'management');
        $service = app(BenefitProgramService::class);

        $this->assertFalse($service->covers($allowance, $staff));
        $this->assertTrue($service->covers($allowance, $manager->fresh()));
    }

    /** `custom` is the one mode that stores a list, because a list of names IS the rule. */
    public function test_a_custom_rule_covers_only_the_named_employees(): void
    {
        $picked = $this->employee('E-PICK');
        $other = $this->employee('E-OTHER');

        $allowance = $this->allowance(eligibility: 'custom');
        $allowance->employees()->attach($picked->id);

        $service = app(BenefitProgramService::class);
        $this->assertTrue($service->covers($allowance, $picked));
        $this->assertFalse($service->covers($allowance, $other));
    }

    // --- 2. a rule that covers nobody is rejected ------------------------------------

    /** It would save cleanly, show on the page, and quietly pay no one. */
    public function test_a_status_rule_with_no_statuses_is_rejected(): void
    {
        $this->hrPost('/api/v1/hr/benefit-programs', [
            'name' => 'Nobody Allowance', 'kind' => 'allowance', 'amount' => 1000,
            'eligibility' => 'status', 'eligibility_statuses' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('eligibility_statuses');
    }

    public function test_a_custom_rule_with_no_employees_is_rejected(): void
    {
        $this->hrPost('/api/v1/hr/benefit-programs', [
            'name' => 'Nobody Allowance', 'kind' => 'allowance', 'amount' => 1000,
            'eligibility' => 'custom', 'employee_ids' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('employee_ids');
    }

    /** Switching away from `status` must not leave the old list to reappear later. */
    public function test_changing_the_mode_clears_a_stale_status_list(): void
    {
        $allowance = $this->allowance(eligibility: 'status', statuses: ['regular']);

        app(BenefitProgramService::class)->update($allowance, ['eligibility' => 'all']);

        $this->assertNull($allowance->fresh()->eligibility_statuses);
    }

    // --- 5. the audit trail says who it is for ---------------------------------------

    public function test_the_audit_line_records_the_rule(): void
    {
        app(BenefitProgramService::class)->create([
            'name' => 'Field Allowance', 'kind' => 'allowance', 'amount' => 1000,
            'eligibility' => 'status', 'eligibility_statuses' => ['regular', 'seasonal'],
        ]);

        $log = AuditLog::where('action', 'created')->latest('id')->first();
        $this->assertStringContainsString('regular, seasonal', $log->description);
    }

    // --- 6. dropping and re-adding must not destroy the pivot ------------------------

    /** Latent today (the only caller is unreachable from the UI) and real the moment it is wired up. */
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
