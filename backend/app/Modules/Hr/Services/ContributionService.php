<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\Employee;
use App\Models\User;
use App\Modules\Hr\Models\EmployeeBenefit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Government-contribution config (Company → Benefits): the editable SSS / PhilHealth
 * / Pag-IBIG settings and the per-employee enrolment checklist. Payroll v2 (Slice 22)
 * calls effectiveFor() to know which contributions to apply and at what rate.
 */
class ContributionService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Per-request memo (Slice 15). A payroll run calls settings() twice per employee — once via
     * benefitsFor(), once via effectiveFor() — for a single, unchanging table. Any write below
     * clears it, so it can't go stale within a request.
     */
    private ?Collection $settingsMemo = null;

    public function settings(): Collection
    {
        return $this->settingsMemo ??= ContributionSetting::orderBy('type')->get();
    }

    /** @param array<string, mixed> $data */
    public function updateSetting(ContributionSetting $setting, array $data): ContributionSetting
    {
        // Item 13b.12 — this decides whether a statutory deduction appears on every payslip in
        // the company, so the audit line carries the old and new value, not just "updated".
        //
        // Slice B9 item 5 — `config` is no longer writable here. The rates live on
        // `hr_contribution_versions` and this row is now only "does the company operate this
        // contribution at all".
        $before = ['is_active' => (bool) $setting->is_active];

        $setting->update(['is_active' => $data['is_active'] ?? $setting->is_active]);
        $this->settingsMemo = null;

        // FIN-038 — switching a contribution ON is one of the two moments an enrolment row can
        // start being missing, so the back-fill happens HERE rather than inside the next payroll
        // run. Before, the first run after an activation enrolled the whole company as a side
        // effect of computing pay.
        if (! $before['is_active'] && $setting->is_active) {
            $this->backfillEnrolments();
        }

        $after = ['is_active' => (bool) $setting->is_active];

        $this->audit->log(
            'updated',
            'Benefits',
            $setting->type . ' is ' . ($setting->is_active ? 'operated company-wide' : 'switched OFF for everyone — it will not be deducted'),
            $setting,
            $before,
            $after,
        );

        return $setting;
    }

    /**
     * Every version of every contribution, newest first — the history HR reads.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ContributionVersion>
     */
    public function versions(?string $type = null): \Illuminate\Database\Eloquent\Collection
    {
        return ContributionVersion::with(['creator', 'brackets'])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('type')
            ->orderByDesc('effective_from')
            ->get();
    }

    /**
     * Completed payroll runs whose cycle a new rate version would reach back into (FIN-026).
     *
     * WHY THIS IS NOT SIMPLY BLOCKED. Backdating is legitimate and common — an agency publishes a
     * circular in March that takes effect in January, and the correct action is to record it with
     * the January date. The danger is not the backdating, it is that it happens SILENTLY.
     *
     * `policySnapshot()` records the rates a run used but does not replay them: a re-run resolves
     * the rates in force TODAY. So after a backdated version is saved, a completed-but-unlocked
     * run's payslips and its own snapshot disagree with what a recompute would now produce, and
     * the next re-run of that period — for any unrelated reason — silently reprices statutory
     * deductions on payslips that have already been reviewed. A locked run is safe only because
     * it refuses re-runs at all.
     *
     * So HR is shown exactly which runs are affected and has to confirm. Returned rather than
     * thrown so the caller can decide whether this is a warning or a refusal.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Modules\Hr\Models\PayrollRun>
     */
    public function completedRunsAffectedBy(string $effectiveFrom): \Illuminate\Database\Eloquent\Collection
    {
        return \App\Modules\Hr\Models\PayrollRun::query()
            ->where('status', 'completed')
            ->whereNull('locked_at')            // a locked run cannot be repriced anyway
            ->where('type', 'regular')          // 13th month does not price statutory contributions
            ->whereDate('cycle_end', '>=', $effectiveFrom)
            ->orderBy('cycle_start')
            ->get();
    }

    /**
     * Record a new dated version. Saving the same type + date twice UPDATES that version rather
     * than failing on the unique index — HR correcting a figure they just entered is the common
     * case, and a duplicate-key error would be a poor way to say so.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveVersion(array $data, User $actor): ContributionVersion
    {
        // FIN-026 — a date that reaches back over a completed run needs eyes on it first.
        $acknowledged = (bool) ($data['acknowledge_repricing'] ?? false);
        unset($data['acknowledge_repricing']);

        if (! $acknowledged) {
            $affected = $this->completedRunsAffectedBy($data['effective_from']);

            if ($affected->isNotEmpty()) {
                $periods = $affected
                    ->map(fn ($r) => $r->cycle_start->format('M j') . '–' . $r->cycle_end->format('M j, Y'))
                    ->implode(', ');

                throw ValidationException::withMessages([
                    'effective_from' => [
                        'This date falls inside ' . $affected->count() . ' completed payroll run'
                            . ($affected->count() === 1 ? '' : 's') . " ({$periods}). Those runs were "
                            . 'priced at the old rates, and re-running any of them would now silently '
                            . 'reprice their statutory deductions. Confirm to save anyway.',
                    ],
                ]);
            }
        }

        $existing = ContributionVersion::where('type', $data['type'])
            ->whereDate('effective_from', $data['effective_from'])
            ->first();

        $before = $existing?->only([
            'employee_rate', 'employer_rate', 'salary_floor', 'salary_cap',
            'employee_amount_cap', 'employer_amount_cap', 'fixed_employer_add_on', 'add_on_min_salary',
            // Slice B19 — moving a contribution between the basic and earnings base changes what
            // every payslip is charged on, which is a bigger change than any rate on this list.
            'salary_basis',
        ]);

        $brackets = $data['brackets'] ?? null;
        unset($data['brackets']);

        $version = ContributionVersion::updateOrCreate(
            ['type' => $data['type'], 'effective_from' => $data['effective_from']],
            [...$data, 'created_by' => $actor->id],
        );

        // Slice B3b — bands are REPLACED wholesale, never merged. A schedule is published as a
        // complete table; merging would leave stale bands from the previous one silently in play.
        if ($brackets !== null) {
            $version->brackets()->delete();
            $version->brackets()->createMany(
                collect($brackets)
                    ->sortBy(fn ($b) => (float) $b['min_salary'])
                    ->map(fn ($b) => [
                        'min_salary' => $b['min_salary'],
                        'msc' => $b['msc'],
                        // Slice B8 — the band's own EC amount, when the published table printed one.
                        'employer_add_on' => $b['employer_add_on'] ?? null,
                    ])
                    ->values()
                    ->all(),
            );
        }

        $this->versionMemo = []; // a rate just changed

        $this->audit->log(
            $existing ? 'updated' : 'created',
            'Benefits',
            strtoupper($version->type) . ' rates '
                . ($existing ? 'corrected' : 'set') . ' effective '
                . $version->effective_from->format('M j, Y')
                . ' — employee ' . round($version->employee_rate * 100, 3) . '%'
                . ', employer ' . round($version->employer_rate * 100, 3) . '%'
                // Slice B19 — named in the sentence, not just the diff. "Charged on actual
                // earnings" is the part of this change a reader needs to see without opening the
                // before/after, because it moves what every payslip is measured against.
                . ', charged on ' . ($version->salary_basis === 'earnings'
                    ? 'actual earnings for the period'
                    : 'basic monthly salary'),
            $version,
            $before,
            $version->only(array_keys($before ?? [
                'employee_rate' => null, 'employer_rate' => null, 'salary_floor' => null,
                'salary_cap' => null, 'employee_amount_cap' => null, 'employer_amount_cap' => null,
                'fixed_employer_add_on' => null, 'add_on_min_salary' => null,
                'salary_basis' => null,
            ])),
        );

        return $version;
    }

    /**
     * Remove a version (Slice B8) — a schedule saved against the wrong date has to be removable,
     * or the only way out is to enter a corrected one on top and leave the mistake in the history.
     *
     * The caller checks that this is not the last version for its type. Bands go with it through
     * the cascade on `hr_contribution_brackets`.
     */
    public function deleteVersion(ContributionVersion $version): void
    {
        $this->audit->log(
            'removed',
            'Benefits',
            strtoupper($version->type) . ' schedule effective '
                . $version->effective_from->format('M j, Y') . ' removed'
                . ' — employee ' . round($version->employee_rate * 100, 3) . '%'
                . ', employer ' . round($version->employer_rate * 100, 3) . '%',
            $version,
        );

        $version->delete();

        $this->versionMemo = []; // the rate in force may have just moved
    }

    /**
     * The employee's checklist, back-filling a row (enrolled) for any active
     * contribution they don't have one for yet.
     */
    /**
     * The active contributions this employee is enrolled in — reading only (FIN-038).
     *
     * A MISSING ROW MEANS ENROLLED, and that default is the whole reason this method can be
     * read-only safely. `benefitsFor()` back-fills a missing row as `enrolled => true`, so the
     * absence of a row has always meant "enrolled, nobody has said otherwise". Treating absence as
     * NOT enrolled would have been the tidier-looking change and a serious bug: an employee whose
     * row had not been created yet would silently stop having SSS deducted, with a run that looks
     * perfectly successful — the same failure FIN-035 exists to prevent, arrived at from the other
     * direction.
     *
     * @return array<int, string>
     */
    private function enrolledActiveTypesFor(Employee $employee): array
    {
        $rows = $employee->benefits()->get()->keyBy('contribution_type');

        return $this->settings()
            ->where('is_active', true)
            ->filter(fn ($setting) => (bool) ($rows[$setting->type]->enrolled ?? true))
            ->pluck('type')
            ->values()
            ->all();
    }

    /**
     * Create the enrolment rows an employee is missing, where the change actually happens (FIN-038).
     *
     * Called on employee creation and when a contribution type is switched on — the two moments a
     * row can start being missing. Payroll no longer does it, so it no longer writes while pricing.
     *
     * IT IS NOT A CORRECTNESS DEPENDENCY, deliberately. `enrolledActiveTypesFor()` treats a missing
     * row as enrolled, so pay is right whether or not this has run. Its job is to give the enrolment
     * checklist real rows to show and toggle, which is a UI concern rather than a payroll one — if it
     * were load-bearing for money, a missed call would silently change someone's deductions.
     *
     * @return int rows created
     */
    public function backfillEnrolments(?Employee $employee = null): int
    {
        $types = $this->settings()->where('is_active', true)->pluck('type');

        if ($types->isEmpty()) {
            return 0;
        }

        $employees = $employee
            ? collect([$employee])
            : Employee::withTrashed()->get(['id']);

        $created = 0;

        foreach ($employees as $target) {
            $have = EmployeeBenefit::where('employee_id', $target->id)
                ->pluck('contribution_type')
                ->all();

            foreach ($types->reject(fn ($t) => in_array($t, $have, true)) as $type) {
                EmployeeBenefit::create([
                    'employee_id' => $target->id,
                    'contribution_type' => $type,
                    // The same default the old back-fill used, and the same one the read path
                    // assumes for a missing row. These three must not drift apart.
                    'enrolled' => true,
                ]);
                $created++;
            }
        }

        return $created;
    }

    public function benefitsFor(Employee $employee): Collection
    {
        // Slice 15 — read the employee's existing rows ONCE and only back-fill what is genuinely
        // missing. This used to fire a firstOrCreate per active contribution per employee (3 × N
        // SELECTs on a payroll run) purely to discover that the rows were already there.
        $existing = $employee->benefits()->orderBy('contribution_type')->get();
        $have = $existing->pluck('contribution_type')->all();

        $missing = $this->settings()
            ->where('is_active', true)
            ->reject(fn ($setting) => in_array($setting->type, $have, true));

        if ($missing->isEmpty()) {
            return $existing;
        }

        foreach ($missing as $setting) {
            EmployeeBenefit::firstOrCreate(
                ['employee_id' => $employee->id, 'contribution_type' => $setting->type],
                ['enrolled' => true],
            );
        }

        return $employee->benefits()->orderBy('contribution_type')->get();
    }

    /**
     * Set enrolment flags for this employee.
     *
     * @param array<int, array{contribution_type:string, enrolled:bool}> $rows
     */
    public function updateBenefits(Employee $employee, array $rows): Collection
    {
        // Slice B9 — record WHAT changed, not that something did. Un-enrolling somebody removes a
        // statutory deduction from every payslip they get from then on; "Updated contribution
        // enrolment for Dana Reyes" cannot answer "who stopped her SSS, and when?". Same
        // correction B5 item 5 made for programme enrolments.
        $before = $employee->benefits()->pluck('enrolled', 'contribution_type');

        foreach ($rows as $row) {
            EmployeeBenefit::updateOrCreate(
                ['employee_id' => $employee->id, 'contribution_type' => $row['contribution_type']],
                ['enrolled' => $row['enrolled']],
            );
        }

        $changes = [];
        foreach ($rows as $row) {
            $was = $before->get($row['contribution_type']);
            if ($was === null || (bool) $was !== (bool) $row['enrolled']) {
                $changes[] = strtoupper($row['contribution_type'])
                    . ($row['enrolled'] ? ' on' : ' OFF — no longer deducted');
            }
        }

        $this->audit->log(
            'enrolled', 'Benefits',
            "Updated contribution enrolment for {$employee->user?->name}"
                . ($changes === [] ? ' — no change' : ': ' . implode(', ', $changes)),
            $employee,
        );

        return $employee->benefits()->orderBy('contribution_type')->get();
    }

    /**
     * The contributions this employee is actually enrolled in, with their config —
     * the shape Payroll v2 consumes. Keyed by contribution type.
     *
     * @return array<string, array<string, mixed>>
     */
    public function effectiveFor(Employee $employee, ?string $asOf = null): array
    {
        // FIN-038 — READ ONLY. This used to call benefitsFor(), which back-fills missing enrolment
        // rows with firstOrCreate — so pricing a payslip WROTE to hr_employee_benefits from inside
        // run()'s transaction. Three consequences: a read path mutated enrolment data, a large run
        // added N × types INSERTs and their locks to the critical path, and the first run after
        // someone activated a contribution enrolled the whole company as a side effect of computing
        // pay. The back-fill now happens where the change happens — see backfillEnrolments().
        $active = $this->enrolledActiveTypesFor($employee);

        if ($active === []) {
            return [];
        }

        // Slice B3a — the rates IN FORCE for this pay period, not whatever they are today.
        // Without the date a re-run of an old cycle would silently reprice at the current rate,
        // exactly the bug payroll Slice 13c fixed for salaries.
        $asOf ??= now(config('app.timezone_local'))->toDateString();

        return $this->versionsAsOf($asOf)
            ->filter(fn ($v) => in_array($v->type, $active, true))
            ->mapWithKeys(fn (ContributionVersion $v) => [$v->type => $v->toConfig()])
            ->all();
    }

    /**
     * What the government rates WOULD charge this employee, next to the per-employee override that
     * is suppressing it (Slice B7).
     *
     * WHY THIS EXISTS. Ten profiles carry a peso amount typed into `sss_contribution` and friends.
     * That amount replaces the EMPLOYEE side of the deduction and nothing else — the employer side
     * still follows the rates — so the two halves of one payslip can belong to different salary
     * brackets, and a remittance built from them cannot be filed. Nothing on the employee form said
     * so: they read as three ordinary peso boxes next to the bank details. This is what the form
     * shows beside each one.
     *
     * The arithmetic is `ContributionCalculator`, i.e. the same code payroll runs — a second
     * implementation of "what SSS costs" would drift, which is the failure this whole audit is about.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overridePreviewFor(Employee $employee, ?string $asOf = null): array
    {
        $config = $this->effectiveFor($employee, $asOf);
        $base = (float) $employee->base_salary;

        $overrides = [
            'sss' => $employee->sss_contribution,
            'philhealth' => $employee->philhealth_contribution,
            'pagibig' => $employee->pagibig_contribution,
        ];

        $rows = [];

        foreach ($overrides as $type => $override) {
            $enrolled = isset($config[$type]);
            $rows[] = [
                'type' => $type,
                // Not enrolled ⇒ nothing is deducted at all, and an override on it is inert. Saying
                // so is the difference between "this is suppressed" and "this does nothing".
                'enrolled' => $enrolled,
                'override' => $override !== null ? round((float) $override, 2) : null,
                'calculated_employee' => $enrolled ? ContributionCalculator::employee($config[$type], $base) : 0.0,
                // Shown because it does NOT move with the override — the mismatch is the point.
                'calculated_employer' => $enrolled ? ContributionCalculator::employer($config[$type], $base) : 0.0,
                'basis' => $enrolled ? ($config[$type]['basis'] ?? 'percentage') : null,
                // Slice B19 — WHAT THE FIGURE ABOVE ASSUMES, and it has to be said out loud.
                //
                // This preview has only one salary to work with: the profile's `base_salary`. For
                // a contribution on the `basic` basis that IS the base payroll uses, so the number
                // is exact. For one on `earnings` it is a perfect month — no absences, no lates,
                // no overtime, no premium and every contributable allowance included — which the
                // employee's actual payslip will rarely match. Returning the same shape for both
                // and letting the form present them identically is how a preview becomes a figure
                // people quote back at you.
                'salary_basis' => $enrolled ? ($config[$type]['salary_basis'] ?? 'basic') : null,
                'is_estimate' => $enrolled && ($config[$type]['salary_basis'] ?? 'basic') === 'earnings',
            ];
        }

        return $rows;
    }

    /**
     * The version of each contribution in force on a date: the latest row whose `effective_from`
     * is on or before it. One query, memoized per date for the run.
     *
     * @return \\Illuminate\\Support\\Collection<string, ContributionVersion>
     */
    /**
     * Refuse to price a period for which an active contribution type has no rate in force
     * (FIN-035).
     *
     * THE FAILURE THIS PREVENTS is silent, which is what makes it dangerous. `versionsAsOf()`
     * returns only the types with an `effective_from` on or before the cycle, and `effectiveFor()`
     * filters against that list — so a type with no version simply DROPS OUT. Payroll then
     * deducts ₱0 for it, the run completes, the totals reconcile, and every payslip looks
     * perfectly ordinary. Nothing anywhere says a statutory contribution was skipped.
     *
     * Two ways in. A newly added contribution type is active from the moment it is created but
     * has no dated version until someone adds one — deleting a version is already guarded
     * (`BenefitController::destroyVersion`), creating a type is not. And any cycle dated before
     * the earliest version has the same hole: back-fill a 2024 period against a schedule that
     * starts 2025-01-01 and it deducts nothing.
     *
     * Blocking the run is the right response rather than warning: an under-deducted payslip is
     * one the company must chase the employee for later, and the agencies are owed the full
     * contribution regardless of what was collected.
     *
     * @throws ValidationException naming every type that has no version in force
     */
    public function assertRatesInForce(string $asOf): void
    {
        $active = $this->settings()->where('is_active', true)->pluck('type');
        $resolved = $this->versionsAsOf($asOf)->keys();

        $missing = $active->diff($resolved)->values();

        if ($missing->isEmpty()) {
            return;
        }

        $names = $missing->map(fn (string $t) => match ($t) {
            'sss' => 'SSS',
            'philhealth' => 'PhilHealth',
            'pagibig' => 'Pag-IBIG',
            default => $t,
        })->implode(', ');

        throw ValidationException::withMessages([
            'contributions' => [
                "No {$names} rate is in force for " . $asOf . '. Payroll would deduct ₱0 for '
                    . ($missing->count() === 1 ? 'it' : 'them')
                    . ' without saying so. Add a rate version effective on or before this cycle, '
                    . 'or deactivate the contribution type, then run again.',
            ],
        ]);
    }

    public function versionsAsOf(string $asOf): \Illuminate\Support\Collection
    {
        return $this->versionMemo[$asOf] ??= ContributionVersion::with('brackets')
            ->whereDate('effective_from', '<=', $asOf)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            // Later rows overwrite earlier ones, so the last per type wins.
            ->keyBy('type');
    }

    /** @var array<string, \Illuminate\Support\Collection<string, ContributionVersion>> */
    private array $versionMemo = [];
}
