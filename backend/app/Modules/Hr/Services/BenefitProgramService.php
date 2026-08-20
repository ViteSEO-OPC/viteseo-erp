<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\BenefitProgram;
use App\Modules\Hr\Models\BenefitProgramVersion;
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
        $this->recordVersion($program, $data);
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
        $before = $this->pricing($program);
        $program->update($this->attributes($data, $program));
        $this->allowanceMemo = null;

        // A new dated version only when what the allowance COSTS changed. Renaming it
        // or recolouring its card must not create a price.
        if ($this->pricing($program->fresh()) !== $before) {
            $this->recordVersion($program->fresh(), $data);
        }

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

    /** Delete a programme. Soft, so the row survives for anything that references it. */
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
     * The fields that decide what an allowance costs.
     *
     * @return array<string, mixed>
     */
    private function pricing(BenefitProgram $program): array
    {
        return [
            'amount' => $program->amount === null ? null : (string) $program->amount,
            'frequency' => $program->frequency,
            'tax_treatment' => $program->tax_treatment,
            'deminimis_ceiling' => $program->deminimis_ceiling === null ? null : (string) $program->deminimis_ceiling,
            'ceiling_period' => $program->ceiling_period,
            // Whether the allowance counts toward the statutory contribution base.
            // Versioned with the amount for the same reason: it decides what a payslip was
            // charged on, so an old cycle must reprice to the flag that was in force then.
            'contributable' => (bool) $program->contributable,
        ];
    }

    /**
     * Write the dated version an old payroll run will resolve against.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordVersion(BenefitProgram $program, array $data): void
    {
        if ($program->kind !== 'allowance') {
            return; // only allowances are priced this way; a plan's cost is per enrolment
        }

        BenefitProgramVersion::updateOrCreate(
            [
                'benefit_program_id' => $program->id,
                'effective_from' => $data['effective_from']
                    ?? now(config('app.timezone_local'))->toDateString(),
            ],
            $this->pricing($program) + ['created_by' => auth()->id()],
        );

        $this->versionMemo = [];
    }

    /**
     * Every allowance's amount as it stood on a date, keyed by programme id.
     *
     * @return array<int, BenefitProgramVersion>
     */
    public function pricingAsOf(string $asOf): array
    {
        if (isset($this->versionMemo[$asOf])) {
            return $this->versionMemo[$asOf];
        }

        $map = [];

        // Ordered ascending so the LAST row written per programme is the latest one on or before
        // the date — the same "latest row wins" resolution B3a uses for contribution versions.
        foreach (
            BenefitProgramVersion::query()
                ->whereDate('effective_from', '<=', $asOf)
                ->orderBy('effective_from')
                ->orderBy('id')
                ->get() as $version
        ) {
            $map[(int) $version->benefit_program_id] = $version;
        }

        return $this->versionMemo[$asOf] = $map;
    }

    /** @var array<string, array<int, BenefitProgramVersion>> */
    private array $versionMemo = [];

    /**
     * Set a programme's enrolment list WITHOUT destroying the pivot of anyone who stays.
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
    public function covers(BenefitProgram $program, Employee $employee, ?string $asOf = null): bool
    {
        return match ($program->eligibility) {
            // Dated, for the reason `allowancesFor()` documents: an enrolment that has
            // ended is not membership, and this is what the roster, the headcount and the
            // override guard all ask. Defaults to today, so every existing caller is unaffected
            // except in the case that was wrong.
            'custom' => $this->isEnrolledOn($program, $employee, $asOf ?? now(config('app.timezone_local'))->toDateString()),
            'status' => in_array((string) $employee->employment_status, $program->eligibility_statuses ?? [], true),
            'management' => (bool) $employee->is_management,
            default => true, // 'all', and anything unrecognised — never narrower than intended
        };
    }

    /**
     * The active ALLOWANCE programmes this employee qualifies for right now.
     *
     * @param  ?string  $asOf  Manila calendar date (Y-m-d); null = today
     * @return Collection<int, BenefitProgram>
     */
    public function allowancesFor(Employee $employee, ?string $asOf = null): Collection
    {
        $catalogue = $this->activeAllowances();

        if ($catalogue->isEmpty()) {
            return new Collection;
        }

        $on = $asOf ?? now(config('app.timezone_local'))->toDateString();

        $custom = $catalogue->contains(fn ($p) => $p->eligibility === 'custom')
            ? $this->enrolledProgramIdsFor($employee, $on)
            : [];

        return $catalogue
            ->filter(fn (BenefitProgram $p) => $p->eligibility === 'custom'
                ? in_array((int) $p->id, $custom, true)
                : $this->covers($p, $employee))
            ->values();
    }

    /** Whether one enrolment row covers $on, with the same null/inclusive rules as below. */
    private function isEnrolledOn(BenefitProgram $program, Employee $employee, string $on): bool
    {
        return $program->employees()
            ->whereKey($employee->id)
            ->where(fn ($q) => $q->whereNull('hr_benefit_enrollments.enrolled_at')
                ->orWhereDate('hr_benefit_enrollments.enrolled_at', '<=', $on))
            ->where(fn ($q) => $q->whereNull('hr_benefit_enrollments.ended_at')
                ->orWhereDate('hr_benefit_enrollments.ended_at', '>=', $on))
            ->exists();
    }

    /**
     * The programme ids this employee's enrolment covers on $on.
     *
     * @return list<int>
     */
    private function enrolledProgramIdsFor(Employee $employee, string $on): array
    {
        return $employee->benefitPrograms()
            ->where(fn ($q) => $q->whereNull('hr_benefit_enrollments.enrolled_at')
                ->orWhereDate('hr_benefit_enrollments.enrolled_at', '<=', $on))
            ->where(fn ($q) => $q->whereNull('hr_benefit_enrollments.ended_at')
                ->orWhereDate('hr_benefit_enrollments.ended_at', '>=', $on))
            ->pluck('hr_benefit_programs.id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
     * This employee's deliberate per-allowance amounts, as `program_id => amount`.
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

    /** Set or clear one employee's own amount for one allowance. */
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
     * Who is on a company plan, what tier, what they pay.
     *
     * @param  array<int, array{employee_id:int, tier?:?string, employee_share?:?float, enrolled_at?:?string, ended_at?:?string}>  $rows
     * @return array{enrolled:int, rejected:array<int, string>}
     */
    public function syncPlanEnrolments(BenefitProgram $program, array $rows, ?int $actorId = null): array
    {
        $tiers = collect($program->tiers ?? [])->pluck('name')->all();
        $rejected = [];
        $applied = [];

        foreach ($rows as $row) {
            $employee = Employee::find($row['employee_id']);

            if ($employee === null) {
                $rejected[] = "Employee {$row['employee_id']} does not exist.";

                continue;
            }

            // The rule is the gate. Enrolling somebody a `regular`-only plan excludes is the same
            // fault B5 item 1 fixed for assignment, arriving through a different door.
            if (! $this->covers($program, $employee)) {
                $rejected[] = ($employee->user?->name ?? "Employee {$employee->id}")
                    . ' is not eligible for this plan.';

                continue;
            }

            // B5 item 2 — an unrecognised tier used to enrol them with a NULL share, which meant
            // the company silently paying their whole premium.
            $tier = $row['tier'] ?? null;
            if ($tier !== null && $tiers !== [] && ! in_array($tier, $tiers, true)) {
                $rejected[] = ($employee->user?->name ?? "Employee {$employee->id}")
                    . ": '{$tier}' is not a tier on this plan.";

                continue;
            }

            // Default the share from the chosen tier — the figure HR already typed once when they
            // configured the plan; making them retype it per person is how the two drift apart.
            // An explicit share still wins. Written long-hand because `firstWhere` returns null
            // when the tier has no entry, and indexing null is a warning, not a default.
            $tierRow = $tier === null ? null : collect($program->tiers ?? [])->firstWhere('name', $tier);
            $share = $row['employee_share'] ?? ($tierRow['employee_share'] ?? null);

            $applied[$employee->id] = [
                'tier' => $tier,
                'employee_share' => $share,
                // Dates exist so a mid-month joiner is not charged for the period before they
                // joined, and a leaver stops being charged on a date rather than by deletion.
                'enrolled_at' => $row['enrolled_at'] ?? now(config('app.timezone_local'))->toDateString(),
                'ended_at' => $row['ended_at'] ?? null,
            ];
        }

        if ($applied !== []) {
            $program->employees()->syncWithoutDetaching($applied);
        }

        $this->audit->log(
            'enrolled', 'Benefits',
            "Enrolment updated on '{$program->name}' — " . count($applied) . ' of ' . count($rows)
                . ' employee(s): ' . (implode(', ', array_keys($applied)) ?: 'none')
                . ($rejected === [] ? '' : ' · rejected: ' . implode(' ', $rejected)),
            $program,
        );

        return ['enrolled' => count($applied), 'rejected' => $rejected];
    }

    /** Take someone off a plan. Soft in effect: the row keeps the dates that priced past payslips. */
    public function endPlanEnrolment(BenefitProgram $program, Employee $employee, ?string $on = null): bool
    {
        $enrolled = $program->employees()->whereKey($employee->id)->exists();

        if (! $enrolled) {
            return false;
        }

        $program->employees()->updateExistingPivot($employee->id, [
            'ended_at' => $on ?? now(config('app.timezone_local'))->toDateString(),
        ]);

        $this->audit->log(
            'removed', 'Benefits',
            "{$employee->user?->name} leaves '{$program->name}' — no share is deducted after "
                . ($on ?? now(config('app.timezone_local'))->toDateString()),
            $program,
        );

        return true;
    }

    /**
     * How many of the people a programme covers follow it, and how many carry their own amount.
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
            // The per-item de-minimis ceiling. Cleared when the allowance is marked
            // taxable, because a ceiling on compensation-in-full means nothing and would come
            // back to life if the treatment were switched again.
            'deminimis_ceiling' => ($data['tax_treatment'] ?? $existing?->tax_treatment) === 'taxable'
                ? null
                : (array_key_exists('deminimis_ceiling', $data) ? $data['deminimis_ceiling'] : $existing?->deminimis_ceiling),
            'ceiling_period' => ($data['tax_treatment'] ?? $existing?->tax_treatment) === 'taxable'
                ? null
                : (array_key_exists('ceiling_period', $data) ? $data['ceiling_period'] : $existing?->ceiling_period),
            // NOT cleared when the tax treatment changes, unlike the ceiling above.
            // Tax treatment and contributability are independent questions (a taxable allowance can
            // sit outside the SSS base; the mandated COLA is de-minimis for tax and inside it), so
            // touching one must not silently reset the other. Defaults to counting.
            'contributable' => array_key_exists('contributable', $data)
                ? (bool) $data['contributable']
                : ($existing?->contributable ?? true),
            'eligibility' => $data['eligibility'] ?? $existing?->eligibility ?? 'all',
            // Only meaningful in the `status` mode; cleared otherwise so a mode change can't leave
            // a stale list behind that silently comes back if the mode is switched again.
            'eligibility_statuses' => ($data['eligibility'] ?? $existing?->eligibility) === 'status'
                ? array_values($data['eligibility_statuses'] ?? $existing?->eligibility_statuses ?? [])
                : null,
            // loan-policy config, kept only on a loan policy so a kind change cannot
            // leave a maximum behind on an allowance.
            'loan_max_principal' => ($data['kind'] ?? $existing?->kind) === 'loan_policy'
                ? ($data['loan_max_principal'] ?? $existing?->loan_max_principal)
                : null,
            'loan_term_periods' => ($data['kind'] ?? $existing?->kind) === 'loan_policy'
                ? ($data['loan_term_periods'] ?? $existing?->loan_term_periods)
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
