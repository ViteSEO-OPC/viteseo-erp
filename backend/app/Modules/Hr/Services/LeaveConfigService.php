<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use Illuminate\Database\Eloquent\Collection;

/**
 * HR-side leave configuration (Company → Leave Type): the leave-type catalogue,
 * the per-employment-status allotment defaults, and per-person balance overrides.
 *
 * Two layers, per the 2026-07-08 decision:
 *   - Allotments = default credits applied ONLY when an employee is created.
 *   - Balances   = each person's actual, editable credits (the source of truth).
 * Editing an allotment never rewrites existing balances (going-forward only).
 */
class LeaveConfigService
{
    public function __construct(private readonly AuditService $audit) {}

    // --- Leave types ---

    public function listTypes(): Collection
    {
        return LeaveType::orderBy('name')->get();
    }

    /** @param array<string, mixed> $data */
    public function createType(array $data): LeaveType
    {
        $type = LeaveType::create($data);

        $this->audit->log('created', 'Leave Types', "Created leave type '{$type->name}'", $type);

        return $type;
    }

    /** @param array<string, mixed> $data */
    public function updateType(LeaveType $type, array $data): LeaveType
    {
        $type->update($data);

        $this->audit->log('updated', 'Leave Types', "Updated leave type '{$type->name}'", $type);

        return $type;
    }

    public function deleteType(LeaveType $type): void
    {
        $type->delete(); // soft delete

        $this->audit->log('removed', 'Leave Types', "Removed leave type '{$type->name}'", $type);
    }

    // --- Allotment defaults (per employment status) ---

    public function listAllotments(): Collection
    {
        return LeaveTypeAllotment::with('leaveType')
            ->orderBy('leave_type_id')
            ->orderBy('employment_status')
            ->get();
    }

    /**
     * Set (create or update) the default days for one leave type + status pair.
     *
     * @param array<string, mixed> $data
     */
    public function upsertAllotment(array $data): LeaveTypeAllotment
    {
        $allotment = LeaveTypeAllotment::updateOrCreate(
            [
                'leave_type_id' => $data['leave_type_id'],
                'employment_status' => $data['employment_status'],
            ],
            ['days_per_year' => $data['days_per_year']],
        )->load('leaveType');

        $this->audit->log('updated', 'Leave Types', "Updated {$allotment->employment_status} leave allotment for '{$allotment->leaveType?->name}'", $allotment);

        return $allotment;
    }

    /** @param array<string, mixed> $data */
    public function updateAllotment(LeaveTypeAllotment $allotment, array $data): LeaveTypeAllotment
    {
        $allotment->update(['days_per_year' => $data['days_per_year']]);
        $allotment->load('leaveType');

        $this->audit->log('updated', 'Leave Types', "Updated {$allotment->employment_status} leave allotment for '{$allotment->leaveType?->name}'", $allotment);

        return $allotment;
    }

    // --- Per-person balances (the editable override) ---

    public function balancesFor(Employee $employee): Collection
    {
        $year = now(config('app.timezone_local'))->year;

        return $employee->leaveBalances()
            ->with('leaveType')
            ->where('year', $year)
            ->get();
    }

    /**
     * Give this person a specific credit for a leave type this year (add a type
     * they didn't have, or change the entitled days). used_days is preserved.
     *
     * @param array<string, mixed> $data
     */
    public function upsertBalance(Employee $employee, array $data): LeaveBalance
    {
        $year = now(config('app.timezone_local'))->year;

        $balance = LeaveBalance::updateOrCreate(
            [
                'employee_id' => $employee->id,
                'leave_type_id' => $data['leave_type_id'],
                'year' => $year,
            ],
            ['entitled_days' => $data['entitled_days']],
        )->load('leaveType');

        $employee->loadMissing('user');
        $name = $employee->user?->name ?? $employee->name;
        $this->audit->log('assigned', 'Leave Types', "Assigned '{$balance->leaveType?->name}' leave balance to {$name}", $balance);

        return $balance;
    }

    public function removeBalance(LeaveBalance $balance): void
    {
        $balance->delete(); // soft delete

        $balance->loadMissing('leaveType');
        $this->audit->log('removed', 'Leave Types', "Removed a '{$balance->leaveType?->name}' leave balance", $balance);
    }
}
