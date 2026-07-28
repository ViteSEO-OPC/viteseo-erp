<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\Employee;
use App\Models\User;
use App\Modules\Hr\Models\EmployeeBenefit;
use Illuminate\Database\Eloquent\Collection;

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
        // Item 13b.12 — these rates set every statutory deduction on every payslip, so the
        // audit line has to carry the old and new values, not just "settings updated".
        $before = ['config' => $setting->config, 'is_active' => (bool) $setting->is_active];

        $setting->update([
            'config' => $data['config'] ?? $setting->config,
            'is_active' => $data['is_active'] ?? $setting->is_active,
        ]);
        $this->settingsMemo = null; // the rates just changed

        $after = ['config' => $setting->config, 'is_active' => (bool) $setting->is_active];

        $this->audit->log(
            'updated',
            'Benefits',
            "{$setting->type} contribution settings updated",
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
     * Record a new dated version. Saving the same type + date twice UPDATES that version rather
     * than failing on the unique index — HR correcting a figure they just entered is the common
     * case, and a duplicate-key error would be a poor way to say so.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveVersion(array $data, User $actor): ContributionVersion
    {
        $existing = ContributionVersion::where('type', $data['type'])
            ->whereDate('effective_from', $data['effective_from'])
            ->first();

        $before = $existing?->only([
            'employee_rate', 'employer_rate', 'salary_floor', 'salary_cap',
            'employee_amount_cap', 'employer_amount_cap', 'fixed_employer_add_on', 'add_on_min_salary',
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
                    ->map(fn ($b) => ['min_salary' => $b['min_salary'], 'msc' => $b['msc']])
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
                . ', employer ' . round($version->employer_rate * 100, 3) . '%',
            $version,
            $before,
            $version->only(array_keys($before ?? [
                'employee_rate' => null, 'employer_rate' => null, 'salary_floor' => null,
                'salary_cap' => null, 'employee_amount_cap' => null, 'employer_amount_cap' => null,
                'fixed_employer_add_on' => null, 'add_on_min_salary' => null,
            ])),
        );

        return $version;
    }

    /**
     * The employee's checklist, back-filling a row (enrolled) for any active
     * contribution they don't have one for yet.
     */
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
        foreach ($rows as $row) {
            EmployeeBenefit::updateOrCreate(
                ['employee_id' => $employee->id, 'contribution_type' => $row['contribution_type']],
                ['enrolled' => $row['enrolled']],
            );
        }

        $this->audit->log('enrolled', 'Benefits', "Updated contribution enrolment for {$employee->user?->name}", $employee);

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
        $enrolled = $this->benefitsFor($employee)
            ->where('enrolled', true)
            ->pluck('contribution_type')
            ->all();

        $active = $this->settings()
            ->where('is_active', true)
            ->whereIn('type', $enrolled)
            ->pluck('type')
            ->all();

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
     * The version of each contribution in force on a date: the latest row whose `effective_from`
     * is on or before it. One query, memoized per date for the run.
     *
     * @return \\Illuminate\\Support\\Collection<string, ContributionVersion>
     */
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
