<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\LeaveType;
use App\Modules\Hr\Models\LeaveTypeAllotment;
use App\Modules\Hr\Models\SharedLeaveAllotment;
use App\Modules\Hr\Models\SharedLeaveBalance;
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

    // --- Global allotment mode (shared vs not-shared) ---

    public function getSettings(): LeaveSetting
    {
        return LeaveSetting::current();
    }

    /**
     * Switch the company-wide allotment model. Flipping the mode provisions the
     * target model's balances for the current year for existing staff who lack
     * them (non-destructive — never overwrites or resurrects edited/removed rows).
     */
    public function setMode(string $mode): LeaveSetting
    {
        $settings = LeaveSetting::current();

        if ($settings->allotment_mode !== $mode) {
            $settings->update(['allotment_mode' => $mode]);
            $this->audit->log('updated', 'Leave Types', "Switched leave allotment mode to '{$mode}'", $settings);

            if ($mode === LeaveSetting::MODE_SHARED) {
                $this->backfillSharedBalances();
            } else {
                $this->backfillPerTypeBalances();
            }
        }

        return $settings;
    }

    // --- Shared allotment defaults (one pool per employment status) ---

    public function listSharedAllotments(): Collection
    {
        // Ensure a row exists for each status (singleton-style, like settings).
        foreach (EmployeeService::STATUSES as $status) {
            SharedLeaveAllotment::firstOrCreate(['employment_status' => $status], ['days_per_year' => 0]);
        }

        return SharedLeaveAllotment::orderBy('id')->get();
    }

    /**
     * Set the shared pool default for one employment status.
     *
     * @param array<string, mixed> $data
     */
    public function upsertSharedAllotment(array $data): SharedLeaveAllotment
    {
        $allotment = SharedLeaveAllotment::updateOrCreate(
            ['employment_status' => $data['employment_status']],
            ['days_per_year' => $data['days_per_year']],
        );

        $this->audit->log('updated', 'Leave Types', "Updated shared leave allotment for {$allotment->employment_status}", $allotment);

        return $allotment;
    }

    /** Provision a shared pool balance for the current year for staff who lack one. */
    private function backfillSharedBalances(): void
    {
        $year = now(config('app.timezone_local'))->year;
        $allotments = SharedLeaveAllotment::pluck('days_per_year', 'employment_status');

        foreach (Employee::all() as $employee) {
            SharedLeaveBalance::withTrashed()->firstOrCreate(
                ['employee_id' => $employee->id, 'year' => $year],
                [
                    'entitled_days' => (int) ($allotments[$employee->employment_status] ?? 0),
                    'used_days' => 0,
                ],
            );
        }
    }

    /** Provision per-type balances for the current year for staff who lack them. */
    private function backfillPerTypeBalances(): void
    {
        $year = now(config('app.timezone_local'))->year;
        $types = LeaveType::all();

        foreach (Employee::all() as $employee) {
            $allotments = LeaveTypeAllotment::where('employment_status', $employee->employment_status)
                ->pluck('days_per_year', 'leave_type_id');

            foreach ($types as $type) {
                LeaveBalance::withTrashed()->firstOrCreate(
                    ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
                    [
                        'entitled_days' => (int) ($allotments[$type->id] ?? $type->days_per_year),
                        'used_days' => 0,
                    ],
                );
            }
        }
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
