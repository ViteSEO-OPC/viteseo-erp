<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\Employee;
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
    public function effectiveFor(Employee $employee): array
    {
        $enrolled = $this->benefitsFor($employee)
            ->where('enrolled', true)
            ->pluck('contribution_type')
            ->all();

        return $this->settings()
            ->where('is_active', true)
            ->whereIn('type', $enrolled)
            ->mapWithKeys(fn ($s) => [$s->type => $s->config])
            ->all();
    }
}
