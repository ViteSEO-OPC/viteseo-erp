<?php

namespace App\Modules\Hr\Database\Seeders;

use App\Models\User;
use App\Modules\Hr\Models\LeaveRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Approved leave history spread across recent months so the Reports → Absences &
 * Leaves stacked chart has real month-by-type data. Uses existing employees
 * (ids 4-10, i.e. not the primary demo logins) and the seeded leave types.
 * Requests only (balances untouched) so no one shows negative remaining days.
 */
class LeaveReportDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (LeaveRequest::where('reason', 'Report demo leave')->exists()) {
            return;
        }

        $danaUserId = User::where('email', 'hr@viteseo.ph')->value('id');

        // [employee_id, leave_type_id (1 Annual, 2 Sick, 3 Emergency, 6 Unpaid), start, days]
        $rows = [
            [4, 1, '2026-01-08', 5], [5, 2, '2026-01-14', 3], [6, 3, '2026-01-20', 1],
            [7, 1, '2026-02-05', 3], [8, 2, '2026-02-12', 1], [9, 6, '2026-02-18', 2], [10, 3, '2026-02-24', 1],
            [4, 1, '2026-03-04', 6], [6, 2, '2026-03-11', 3], [8, 3, '2026-03-17', 2], [9, 6, '2026-03-25', 1],
            [5, 1, '2026-04-06', 5], [7, 2, '2026-04-13', 2], [10, 3, '2026-04-21', 2], [4, 6, '2026-04-28', 1],
            [6, 1, '2026-05-05', 6], [8, 2, '2026-05-12', 4], [9, 3, '2026-05-19', 3], [10, 6, '2026-05-26', 2],
            [7, 1, '2026-06-03', 4], [5, 3, '2026-06-11', 2], [8, 6, '2026-06-19', 1],
        ];

        foreach ($rows as [$empId, $typeId, $start, $days]) {
            LeaveRequest::create([
                'employee_id' => $empId,
                'leave_type_id' => $typeId,
                'start_date' => $start,
                'end_date' => Carbon::parse($start)->addDays(max(0, $days - 1))->toDateString(),
                'days' => $days,
                'reason' => 'Report demo leave',
                'status' => 'approved',
                'reviewed_by' => $danaUserId,
                'reviewed_at' => $start . ' 09:00:00',
            ]);
        }
    }
}
