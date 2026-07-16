<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\LeaveType;
use Illuminate\Database\Seeder;

/**
 * Demo leave data: some approved history (so balances are partially used) plus
 * pending requests for the HR queue + dashboard approvals list (§8).
 */
class LeaveDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (LeaveRequest::query()->exists()) {
            return;
        }

        $emp = fn (string $email) => Employee::whereHas(
            'user', fn ($q) => $q->where('email', $email)
        )->first();
        $typeId = fn (string $code) => LeaveType::where('code', $code)->value('id');
        $danaUserId = User::where('email', 'hr@viteseo.ph')->value('id');

        $maya = $emp('employee@viteseo.ph');
        $leo = $emp('leo@viteseo.ph');
        $bea = $emp('bea@viteseo.ph');
        $carlo = $emp('carlo@viteseo.ph');

        // --- Maya: approved history (drives her "3/15 Annual, 1/10 Sick" cards) ---
        LeaveRequest::create([
            'employee_id' => $maya->id, 'leave_type_id' => $typeId('ANL'),
            'start_date' => '2026-06-15', 'end_date' => '2026-06-17', 'days' => 3,
            'reason' => 'Family vacation', 'status' => 'approved',
            'reviewed_by' => $danaUserId, 'reviewed_at' => '2026-06-10 09:00:00',
        ]);
        LeaveRequest::create([
            'employee_id' => $maya->id, 'leave_type_id' => $typeId('SCK'),
            'start_date' => '2026-06-22', 'end_date' => '2026-06-22', 'days' => 1,
            'reason' => 'Flu', 'status' => 'approved',
            'reviewed_by' => $danaUserId, 'reviewed_at' => '2026-06-22 08:00:00',
        ]);
        $this->setUsed($maya->id, $typeId('ANL'), 3);
        $this->setUsed($maya->id, $typeId('SCK'), 1);

        // --- Pending requests (the live HR queue — at least 2) ---
        LeaveRequest::create([
            'employee_id' => $maya->id, 'leave_type_id' => $typeId('ANL'),
            'start_date' => '2026-07-20', 'end_date' => '2026-07-22', 'days' => 3,
            'reason' => 'Out-of-town trip', 'status' => 'pending',
        ]);
        LeaveRequest::create([
            'employee_id' => $leo->id, 'leave_type_id' => $typeId('SCK'),
            'start_date' => '2026-07-15', 'end_date' => '2026-07-15', 'days' => 1,
            'reason' => 'Medical check-up', 'status' => 'pending',
        ]);
        LeaveRequest::create([
            'employee_id' => $bea->id, 'leave_type_id' => $typeId('EMG'),
            'start_date' => '2026-07-10', 'end_date' => '2026-07-10', 'days' => 1,
            'reason' => 'Family emergency', 'status' => 'pending',
        ]);

        // --- A rejected one (for the history/status variety) ---
        LeaveRequest::create([
            'employee_id' => $carlo->id, 'leave_type_id' => $typeId('ANL'),
            'start_date' => '2026-07-01', 'end_date' => '2026-07-03', 'days' => 3,
            'reason' => 'Vacation', 'status' => 'rejected',
            'reviewed_by' => $danaUserId, 'reviewed_at' => '2026-06-28 14:00:00',
        ]);
    }

    private function setUsed(int $employeeId, int $leaveTypeId, float $used): void
    {
        LeaveBalance::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', 2026)
            ->update(['used_days' => $used]);
    }
}
