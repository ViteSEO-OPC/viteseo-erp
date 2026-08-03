<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
        $this->allowanceMemo = null;
        $ids = array_map('intval', $data['employee_ids'] ?? []);
        $this->syncEnrolment($program, $ids);

        $this->audit->log(
            'created', 'Benefits',
            "Created benefit '{$program->name}' — " . $this->describeRule($program),
            $program,
        );

        return $program->load('employees:id');
    }

    /** @param array<string, mixed> $data */
    public function update(BenefitProgram $program, array $data): BenefitProgram
    {
        $program->update($this->attributes($data, $program));
        $this->allowanceMemo = null;

        // Only touch enrolment when the caller sent the list (keeps PATCHes safe).
        if (array_key_exists('employee_ids', $data)) {
            $this->syncEnrolment($program, array_map('intval', $data['employee_ids'] ?? []));
        }

        $this->audit->log(
            'updated', 'Benefits',
            "Updated benefit '{$program->name}' — " . $this->describeRule($program),
            $program,
        );

        return $program->load('employees:id');
    }

    /**
     * Delete a programme. Soft, so the row survives for anything that references it.
     *
     * PAST PAYSLIPS ARE SAFE. `PayrollService::allowanceBreakdown()` snapshots the lines onto each
     * payslip when the run happens, so a deleted allowance keeps appearing on the payslips that
     * actually paid it, named as it was named then.
     *
     * FUTURE PAY CHANGES, and that is the point: everyone the rule covered stops being paid it
     * from the next run. The count is in the audit line so the decision is legible afterwards.
     */
    public function delete(BenefitProgram $program): void
    {
        $covered = $this->eligibleCount($program);

        $program->delete();
        $this->allowanceMemo = null;

        $this->audit->log(
            'removed', 'Benefits',
            "Deleted benefit '{$program->name}'"
                . ($program->kind === 'allowance'
                    ? ' — ' . $covered . ' employee(s) stop being paid '
                        . number_format((float) ($program->amount ?? 0), 2) . '/' . $program->frequency
                    : ''),
            $program,
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
     * The employee ids a programme covers, per its rule.
     *
     * `custom` is the only mode that reads the enrolment pivot — for the others the rule IS the
     * membership, so the answer is always current rather than a snapshot of who HR once picked.
     *
     * @return array<int, int>
     */
    public function eligibleEmployeeIds(BenefitProgram $program): array
    {
        if ($program->eligibility === 'custom') {
            return $program->employees()->pluck('hr_employees.id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->roster()
            ->filter(fn (Employee $e) => $this->covers($program, $e))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The rule in words, for the audit trail. "Updated benefit 'Meal Allowance'" never said the
     * one thing that decides who gets paid.
     */
    private function describeRule(BenefitProgram $program): string
    {
        return match ($program->eligibility) {
            'status' => 'for ' . implode(', ', $program->eligibility_statuses ?: ['no statuses']),
            'management' => 'for management staff',
            'custom' => 'for ' . $program->employees()->count() . ' named employee(s)',
            default => 'for all employees',
        };
    }

    /** How many employees the rule covers right now — what the cards and dialogs report. */
    public function eligibleCount(BenefitProgram $program): int
    {
        return $program->eligibility === 'custom'
            ? $program->employees()->count()
            : $this->roster()->filter(fn (Employee $e) => $this->covers($program, $e))->count();
    }

    /** Does this programme's rule cover this employee right now? */
    public function covers(BenefitProgram $program, Employee $employee): bool
    {
        return match ($program->eligibility) {
            'custom' => $program->employees()->whereKey($employee->id)->exists(),
            'status' => in_array((string) $employee->employment_status, $program->eligibility_statuses ?? [], true),
            'management' => (bool) $employee->is_management,
            default => true, // 'all', and anything unrecognised — never narrower than intended
        };
    }

    /**
     * The active ALLOWANCE programmes this employee qualifies for right now.
     *
     * This is what payroll reads (via `AllowanceService`). Deliberately a fresh evaluation rather
     * than a stored list: an employee who moves from probationary to regular starts or stops
     * qualifying on their next payslip, with no enrolment to remember to update.
     *
     * COST. The catalogue is read ONCE per request and the rule is then evaluated in PHP — a
     * payroll run walks every employee, and a query each would be an N+1 by construction (the
     * performance test caught exactly that). The `custom` pivot is only read when a custom
     * allowance actually exists, which today is never.
     *
     * @return Collection<int, BenefitProgram>
     */
    public function allowancesFor(Employee $employee): Collection
    {
        $catalogue = $this->activeAllowances();

        if ($catalogue->isEmpty()) {
            return new Collection;
        }

        $custom = $catalogue->contains(fn ($p) => $p->eligibility === 'custom')
            ? $employee->benefitPrograms()->pluck('hr_benefit_programs.id')->all()
            : [];

        return $catalogue
            ->filter(fn (BenefitProgram $p) => $p->eligibility === 'custom'
                ? in_array((int) $p->id, array_map('intval', $custom), true)
                : $this->covers($p, $employee))
            ->values();
    }

    /**
     * Per-request memo of the allowance catalogue. Cleared by every write below, so it cannot go
     * stale within a request.
     *
     * @var Collection<int, BenefitProgram>|null
     */
    private ?Collection $allowanceMemo = null;

    /** @return Collection<int, BenefitProgram> */
    private function activeAllowances(): Collection
    {
        // "Active" is now exactly "not deleted" (B13 item 4) — the soft-delete scope does it.
        return $this->allowanceMemo ??= BenefitProgram::query()
            ->where('kind', 'allowance')
            ->orderBy('name')
            ->get();
    }

    /**
     * The active programs this employee has — their "My Benefits" list.
     *
     * Allowances come from the rule; company plans (HMO, retirement) come from the pivot, because
     * those carry a per-employee tier and share that only an enrolment can hold.
     */
    public function forEmployee(Employee $employee): Collection
    {
        $plans = $employee->benefitPrograms()
            ->where('kind', '!=', 'allowance')
            ->get();

        return $this->allowancesFor($employee)
            ->concat($plans)
            ->sortBy('name')
            ->values();
    }

    /**
     * This employee's deliberate per-allowance amounts (B14), as `program_id => amount`.
     *
     * Empty for almost everybody: an override exists only where somebody typed one, so the common
     * answer is `[]` and the employee is paid what each programme says.
     *
     * ONE QUERY FOR THE WHOLE RUN, not one per employee. Payroll walks every employee and asks
     * this for each; a query each is the N+1 the performance test already caught once in
     * `allowancesFor()`. Only rows that actually carry an amount are read, which today is a
     * handful at most.
     *
     * @return array<int, float>
     */
    public function overridesFor(Employee $employee): array
    {
        return $this->overrideMemo()[(int) $employee->id] ?? [];
    }

    /** @return array<int, array<int, float>> employee_id => [program_id => amount] */
    private function overrideMemo(): array
    {
        if ($this->overrideMemo !== null) {
            return $this->overrideMemo;
        }

        $map = [];

        foreach (
            DB::table('hr_benefit_enrollments')
                ->whereNotNull('amount')
                ->get(['employee_id', 'benefit_program_id', 'amount']) as $row
        ) {
            $map[(int) $row->employee_id][(int) $row->benefit_program_id] = (float) $row->amount;
        }

        return $this->overrideMemo = $map;
    }

    /** @var array<int, array<int, float>>|null */
    private ?array $overrideMemo = null;

    /**
     * Set or clear one employee's own amount for one allowance (B14).
     *
     * `$amount === null` hands them back to the programme. Anything else is a deliberate figure,
     * recorded with who set it and when — without that, a typed amount and a migrated one look
     * identical, which is precisely what made B6's 56 frozen rows impossible to work through.
     *
     * REFUSED IF THE RULE DOES NOT COVER THEM. An override is not a way onto an allowance:
     * membership is the eligibility rule, and an amount for someone outside it would pay nothing
     * while looking on screen like an arrangement. Returns false so the caller can 422.
     */
    public function setAllowanceAmount(
        BenefitProgram $program,
        Employee $employee,
        ?float $amount,
        ?int $actorId,
    ): bool {
        if ($program->kind !== 'allowance' || ! $this->covers($program, $employee)) {
            return false;
        }

        $before = $employee->benefitPrograms()
            ->where('hr_benefit_programs.id', $program->id)
            ->first()?->pivot?->amount;

        $values = $amount === null
            ? ['amount' => null, 'amount_set_by' => null, 'amount_set_at' => null]
            : ['amount' => round($amount, 2), 'amount_set_by' => $actorId, 'amount_set_at' => now()];

        // A rule-based allowance has no enrolment rows at all, so the row is created BY setting an
        // override. It means "paid differently", not "a member" — `allowancesFor()` never reads it
        // as membership.
        $employee->benefitPrograms()->syncWithoutDetaching([$program->id => $values]);

        $this->overrideMemo = null;

        $programmeAmount = (float) ($program->amount ?? 0);
        $this->audit->log(
            'updated', 'Benefits',
            $amount === null
                ? "{$employee->user?->name} follows the programme amount for '{$program->name}'"
                    . ' — ' . number_format((float) $before, 2) . ' → ' . number_format($programmeAmount, 2)
                : "{$employee->user?->name} is paid " . number_format($amount, 2)
                    . " for '{$program->name}' instead of " . number_format($programmeAmount, 2),
            $employee,
        );

        return true;
    }

    /**
     * How many of the people a programme covers follow it, and how many carry their own amount.
     *
     * The edit dialog claims an edit will reach N people; without this it counted enrolments and
     * was wrong for every one of them (B6). An override means the edit does NOT reach that person,
     * which is the one thing HR needs to know before saving a new figure.
     *
     * @return array{following: int, overridden: int}
     */
    public function reachOf(BenefitProgram $program): array
    {
        $covered = $this->eligibleEmployeeIds($program);
        $overrides = $this->overrideMemo();

        // Counted off the memo the catalogue has already loaded, not a query per card: the
        // resource asks this for every programme on the page.
        $overridden = 0;
        foreach ($covered as $employeeId) {
            if (isset($overrides[$employeeId][(int) $program->id])) {
                $overridden++;
            }
        }

        return ['following' => count($covered) - $overridden, 'overridden' => $overridden];
    }

    /**
     * Per-request memo of the employees a programme can cover. Cleared by nothing, because the
     * roster is not what this service writes — it cannot go stale within a request.
     *
     * @var Collection<int, Employee>|null
     */
    private ?Collection $rosterMemo = null;

    /**
     * Everyone currently employed — the population every rule is evaluated against.
     *
     * SEPARATED EMPLOYEES ARE EXCLUDED (B13 item 6). This used to be a bare `Employee::query()`,
     * so a leaver still counted toward "everyone (14 people)" on the allowance card and toward the
     * "N employees stop being paid this" warning on delete, while `PayrollService` had already
     * stopped paying them. The filter is the same one payroll calls "currently employed": no
     * separation recorded, and not soft-deleted (Eloquent's own scope).
     *
     * A future-dated hire IS counted, deliberately: the programme does cover them, and their first
     * payslip is the thing that waits for their start date.
     *
     * ONE QUERY, not one per programme. The catalogue endpoint asks for a count per card, and
     * `BenefitProgramResource` resolves this service for each — a count query each was an N+1 in
     * the same shape the payroll performance test caught in `allowancesFor()`.
     *
     * @return Collection<int, Employee>
     */
    private function roster(): Collection
    {
        return $this->rosterMemo ??= Employee::query()
            ->whereNull('separation_type')
            ->whereNull('separation_date')
            ->get(['id', 'employment_status', 'is_management']);
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
            // Only meaningful in the `status` mode; cleared otherwise so a mode change can't leave
            // a stale list behind that silently comes back if the mode is switched again.
            'eligibility_statuses' => ($data['eligibility'] ?? $existing?->eligibility) === 'status'
                ? array_values($data['eligibility_statuses'] ?? $existing?->eligibility_statuses ?? [])
                : null,
            // HMO / company-plan config.
            'deduction_cadence' => $data['deduction_cadence'] ?? $existing?->deduction_cadence,
            'tiers' => array_key_exists('tiers', $data) ? $data['tiers'] : $existing?->tiers,
            // `status` is the draft/published state of a COMPANY plan. An allowance has no such
            // state — it exists or it is deleted — so it is stored as "Active" and the employee
            // card derives its badge anyway (B13 item 4). New allowances used to save as
            // "Enrolled" while seeded ones said "Active", which put two words for one thing on
            // the same page.
            'status' => $data['status']
                ?? $existing?->status
                ?? (($data['kind'] ?? $existing?->kind ?? 'allowance') === 'allowance' ? 'Active' : 'Enrolled'),
            'details' => $data['details'] ?? $existing?->details ?? [],
            // progress is nullable and intentionally clearable, so respect an explicit key.
            'progress' => array_key_exists('progress', $data)
                ? ($data['progress'] ?: null)
                : $existing?->progress,
            'footer_label' => array_key_exists('footer_label', $data)
                ? $data['footer_label']
                : $existing?->footer_label,
        ];
    }
}
