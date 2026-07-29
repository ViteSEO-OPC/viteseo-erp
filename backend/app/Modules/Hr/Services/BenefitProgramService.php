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
        $ids = array_map('intval', $data['employee_ids'] ?? []);
        $this->syncEnrolment($program, $ids);

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
            $this->syncEnrolment($program, array_map('intval', $data['employee_ids'] ?? []));
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
     * Assign benefits to a set of employees (the "Add a Plan" modal). Allowances attach to every
     * ELIGIBLE selected employee and FOLLOW the programme amount; an HMO plan attaches with a
     * per-employee tier + the tier's employee share.
     *
     * Slice B6 — this used to copy the programme's amount onto the pivot, which made every
     * newly-assigned employee deaf to the next edit of that allowance. That is the same freeze the
     * B1 backfill applied to all 56 existing enrolments, regenerated on every assignment. There is
     * no per-employee allowance amount any more: an enrolment means "paid what the programme says",
     * which is what new hires already got (`AllowanceService::defaultProgrammeIdsForNewHire()`).
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
        $enrolled = [];

        // Allowances → each allowance to each eligible selected employee.
        foreach ($data['allowance_ids'] ?? [] as $allowanceId) {
            $allowance = BenefitProgram::where('kind', 'allowance')->find($allowanceId);
            if (! $allowance) {
                continue;
            }
            $eligible = array_values(array_intersect($employeeIds, $this->eligibleEmployeeIds($allowance)));
            if ($eligible) {
                // No pivot attributes: the enrolment follows the programme amount (B6). Anyone
                // already enrolled keeps whatever they have — `syncWithoutDetaching` with plain
                // ids leaves an existing row alone, so re-assigning cannot silently re-freeze
                // someone HR has just handed back to the programme.
                $allowance->employees()->syncWithoutDetaching($eligible);
                $names[] = $allowance->name;
                $enrolled = array_merge($enrolled, $eligible);
            }
        }

        // HMO / company plan → per-employee tier + share.
        if (! empty($data['hmo']['program_id'])) {
            $hmo = BenefitProgram::where('kind', 'company')->find($data['hmo']['program_id']);
            if ($hmo) {
                $shares = collect($hmo->tiers ?? [])->keyBy('name');
                // Slice B5.1 — the eligibility rule applied to allowances but not here, so a
                // `regular`-only plan could be assigned to a probationary employee. The rule is a
                // property of the programme, not of the kind of programme.
                $eligibleForHmo = $this->eligibleEmployeeIds($hmo);
                $pivot = [];
                foreach ($data['hmo']['tiers'] ?? [] as $eid => $tierName) {
                    if (! in_array((int) $eid, $employeeIds, true)) {
                        continue;
                    }
                    if (! in_array((int) $eid, $eligibleForHmo, true)) {
                        continue;
                    }
                    // Slice B5.2 — a tier name that isn't on the programme used to enrol the
                    // employee with a NULL share, i.e. the company silently paying their whole
                    // premium. A name we don't recognise is a mistake, so enrol nobody on it.
                    if (! $shares->has($tierName)) {
                        continue;
                    }
                    $pivot[(int) $eid] = [
                        'tier' => $tierName,
                        'employee_share' => $shares[$tierName]['employee_share'],
                    ];
                }
                if ($pivot) {
                    $hmo->employees()->syncWithoutDetaching($pivot);
                    $names[] = $hmo->name;
                    $enrolled = array_merge($enrolled, array_keys($pivot));
                }
            }
        }

        // Slice B5.5 — the trail said "Assigned X to 5 employee(s)" and nothing more, so there was
        // no way to answer "who was put on this, and when?". `count($employeeIds)` was also the
        // number SELECTED, not the number actually enrolled — with an eligibility rule in play
        // those differ, and the log claimed more than happened.
        $enrolled = array_values(array_unique($enrolled));
        $this->audit->log(
            'enrolled',
            'Benefits',
            'Assigned ' . implode(', ', $names ?: ['benefits']) . ' to ' . count($enrolled) . ' of '
                . count($employeeIds) . ' selected employee(s)'
                . ($enrolled ? ' [ids: ' . implode(', ', $enrolled) . ']' : ''),
        );
    }

    /**
     * Set a programme's enrolment list WITHOUT destroying the pivot of anyone who stays (B5.6).
     *
     * `sync()` leaves an existing row alone, so editing a programme never wiped the amount or tier
     * of someone still in the list. But `sync()` DELETES the row of anyone dropped — so removing an
     * employee and re-adding them later loses their per-employee amount, tier and share, and they
     * come back on the programme default with nothing to say they were ever different.
     *
     * `syncWithoutDetaching()` plus an explicit detach makes the removal the only destructive act,
     * which is what HR actually asked for when they dropped someone.
     *
     * @param  array<int, int>  $ids
     */
    private function syncEnrolment(BenefitProgram $program, array $ids): void
    {
        // The ids to drop are worked out here rather than as a `whereNotIn` on the relation:
        // `detach()` builds its own pivot query and honours only `wherePivot*` constraints, so a
        // where on the related table is silently ignored and EVERY row is detached — which is the
        // exact data loss this method exists to prevent.
        $current = $program->employees()->pluck('hr_employees.id')->map(fn ($id) => (int) $id)->all();

        $remove = array_values(array_diff($current, $ids));
        if ($remove !== []) {
            $program->employees()->detach($remove);
        }
        if ($ids !== []) {
            $program->employees()->syncWithoutDetaching($ids);
        }
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
