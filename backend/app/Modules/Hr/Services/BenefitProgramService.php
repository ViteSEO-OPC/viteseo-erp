<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

/**
 * Company → Benefits: the flexible benefit-programs catalogue and per-employee
 * enrolment. Separate from ContributionService (payroll-linked government
 * contributions) — programs here are display-only perks HR authors.
 */
class BenefitProgramService
{
    public function __construct(private readonly AuditService $audit) {}

    /** All programs (HR management list), with the enrolled employee ids. */
    public function list(): Collection
    {
        return BenefitProgram::with('employees:id')->orderBy('name')->get();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): BenefitProgram
    {
        $program = BenefitProgram::create($this->attributes($data));
        $ids = $data['employee_ids'] ?? [];
        $program->employees()->sync($ids);

        $this->audit->log('created', 'Benefits', "Created benefit '{$program->name}'", $program);

        if (! empty($ids)) {
            $this->audit->log('enrolled', 'Benefits', 'Enrolled ' . count($ids) . " employee(s) in '{$program->name}'", $program);
        }

        return $program->load('employees:id');
    }

    /** @param array<string, mixed> $data */
    public function update(BenefitProgram $program, array $data): BenefitProgram
    {
        $program->update($this->attributes($data, $program));

        // Only touch enrolment when the caller sent the list (keeps PATCHes safe).
        if (array_key_exists('employee_ids', $data)) {
            $program->employees()->sync($data['employee_ids'] ?? []);
        }

        $this->audit->log('updated', 'Benefits', "Updated benefit '{$program->name}'", $program);

        return $program->load('employees:id');
    }

    public function delete(BenefitProgram $program): void
    {
        $program->delete(); // soft delete; pivot rows stay but are unreachable.

        $this->audit->log('removed', 'Benefits', "Removed benefit '{$program->name}'", $program);
    }

    /**
     * Assign benefits to a set of employees (the "Add a Plan" modal). Allowances
     * attach to every ELIGIBLE selected employee with the allowance's amount; an HMO
     * plan attaches with a per-employee tier + the tier's employee share.
     *
     * @param  array<string, mixed>  $data
     */
    public function assign(array $data): void
    {
        $employeeIds = array_map('intval', $data['employee_ids'] ?? []);
        if (empty($employeeIds)) {
            return;
        }

        $names = [];

        // Allowances → each allowance to each eligible selected employee.
        foreach ($data['allowance_ids'] ?? [] as $allowanceId) {
            $allowance = BenefitProgram::where('kind', 'allowance')->find($allowanceId);
            if (! $allowance) {
                continue;
            }
            $eligible = array_values(array_intersect($employeeIds, $this->eligibleEmployeeIds($allowance)));
            $pivot = [];
            foreach ($eligible as $eid) {
                $pivot[$eid] = ['amount' => $allowance->amount];
            }
            if ($pivot) {
                $allowance->employees()->syncWithoutDetaching($pivot);
                $names[] = $allowance->name;
            }
        }

        // HMO / company plan → per-employee tier + share.
        if (! empty($data['hmo']['program_id'])) {
            $hmo = BenefitProgram::where('kind', 'company')->find($data['hmo']['program_id']);
            if ($hmo) {
                $shares = collect($hmo->tiers ?? [])->keyBy('name');
                $pivot = [];
                foreach ($data['hmo']['tiers'] ?? [] as $eid => $tierName) {
                    if (! in_array((int) $eid, $employeeIds, true)) {
                        continue;
                    }
                    $pivot[(int) $eid] = [
                        'tier' => $tierName,
                        'employee_share' => $shares[$tierName]['employee_share'] ?? null,
                    ];
                }
                if ($pivot) {
                    $hmo->employees()->syncWithoutDetaching($pivot);
                    $names[] = $hmo->name;
                }
            }
        }

        $this->audit->log(
            'enrolled',
            'Benefits',
            'Assigned ' . implode(', ', $names ?: ['benefits']) . ' to ' . count($employeeIds) . ' employee(s)',
        );
    }

    /**
     * The employee ids eligible for a program per its eligibility rule. 'regular'
     * restricts to regular employees; 'all' and 'custom' leave the pick to HR.
     *
     * @return array<int, int>
     */
    public function eligibleEmployeeIds(BenefitProgram $program): array
    {
        $q = Employee::query();
        if ($program->eligibility === 'regular') {
            $q->where('employment_status', 'regular');
        }

        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** The active programs this employee is enrolled in — their "My Benefits" list. */
    public function forEmployee(Employee $employee): Collection
    {
        return $employee->benefitPrograms()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Map validated input to model attributes, preserving existing values on PATCH.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?BenefitProgram $existing = null): array
    {
        return [
            'name' => $data['name'] ?? $existing?->name,
            'category' => $data['category'] ?? $existing?->category ?? 'general',
            'kind' => $data['kind'] ?? $existing?->kind ?? 'allowance',
            'amount' => array_key_exists('amount', $data) ? $data['amount'] : $existing?->amount,
            'description' => array_key_exists('description', $data) ? $data['description'] : $existing?->description,
            'color' => $data['color'] ?? $existing?->color,
            // Allowance config.
            'frequency' => $data['frequency'] ?? $existing?->frequency ?? 'monthly',
            'tax_treatment' => $data['tax_treatment'] ?? $existing?->tax_treatment ?? 'de_minimis',
            'eligibility' => $data['eligibility'] ?? $existing?->eligibility ?? 'all',
            // HMO / company-plan config.
            'deduction_cadence' => $data['deduction_cadence'] ?? $existing?->deduction_cadence,
            'tiers' => array_key_exists('tiers', $data) ? $data['tiers'] : $existing?->tiers,
            'status' => $data['status'] ?? $existing?->status ?? 'Enrolled',
            'details' => $data['details'] ?? $existing?->details ?? [],
            // progress is nullable and intentionally clearable, so respect an explicit key.
            'progress' => array_key_exists('progress', $data)
                ? ($data['progress'] ?: null)
                : $existing?->progress,
            'footer_label' => array_key_exists('footer_label', $data)
                ? $data['footer_label']
                : $existing?->footer_label,
            'is_active' => $data['is_active'] ?? $existing?->is_active ?? true,
        ];
    }
}
