<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Models\Requisition;
use Illuminate\Support\Carbon;

class DashboardService
{
    public function __construct(
        private readonly AttendanceService $attendance,
    ) {}

    public function hrStats(): array
    {
        $tz = config('app.timezone_local');
        $today = now($tz)->toDateString();

        $onLeaveToday = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->count();

        return [
            'headcount' => Employee::count(),
            'on_leave_today' => $onLeaveToday,
            'open_roles' => Requisition::where('stage', 'active')->count(),
            'next_payroll_date' => $this->nextPayrollDate(),
            'pending_leave' => LeaveRequest::where('status', 'pending')->count(),
            'pending_reimbursements' => Reimbursement::where('status', 'pending')->count(),
            'headcount_chart' => $this->headcountChart(),
            'attendance_trend' => $this->attendanceTrend(),
            'recent_activity' => $this->recentActivity(),
        ];
    }

    private function nextPayrollDate(): string
    {
        $latest = PayrollRun::where('status', 'completed')
            ->orderByDesc('cycle_end')
            ->first();

        if ($latest) {
            return Carbon::parse($latest->cycle_end)->addDays(1)->toDateString();
        }

        // Fallback: next 15th or end-of-month from today
        $now = now(config('app.timezone_local'));
        $day = $now->day;
        $month = $now->month;
        $year = $now->year;

        if ($day < 15) {
            return Carbon::create($year, $month, 15)->toDateString();
        }

        return $now->copy()->endOfMonth()->toDateString();
    }

    /** Real joiners/leavers per month (last 9 months). */
    private function headcountChart(): array
    {
        $tz = config('app.timezone_local');
        $months = collect();
        for ($i = 8; $i >= 0; $i--) {
            $months->push(now($tz)->copy()->subMonths($i)->startOfMonth());
        }

        return $months->map(function (Carbon $start) {
            $end = $start->copy()->endOfMonth();

            return [
                'month' => $start->format('M'),
                'joiners' => Employee::whereBetween('hire_date', [$start->toDateString(), $end->toDateString()])->count(),
                'leavers' => 0, // No termination data yet
                'net' => Employee::whereBetween('hire_date', [$start->toDateString(), $end->toDateString()])->count(),
            ];
        })->values()->all();
    }

    /** On-time / late rate per day for the last 30 days. */
    private function attendanceTrend(): array
    {
        $tz = config('app.timezone_local');
        $points = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now($tz)->copy()->subDays($i)->toDateString();
            $rows = Attendance::where('work_date', $date)->get();

            $total = $rows->count();
            if ($total === 0) {
                $points[] = ['date' => $date, 'on_time' => 0, 'late' => 0];
                continue;
            }

            $onTime = $rows->filter(fn ($r) => $r->status === 'present')->count();
            $late = $rows->filter(fn ($r) => $r->status === 'late')->count();

            $points[] = [
                'date' => Carbon::parse($date)->format('M j'),
                'on_time' => (int) round(($onTime / $total) * 100),
                'late' => (int) round(($late / $total) * 100),
            ];
        }

        return $points;
    }

    /**
     * Latest real status changes across leave, reimbursements, and new hires.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(): array
    {
        $items = collect();

        LeaveRequest::with('employee.user')->whereNotNull('reviewed_at')
            ->latest('reviewed_at')->take(4)->get()
            ->each(fn ($r) => $items->push([
                'type' => 'leave',
                'text' => "Leave {$r->status} — {$r->employee->user?->name}",
                'at' => $r->reviewed_at,
            ]));

        Reimbursement::with('employee.user')->whereNotNull('reviewed_at')
            ->latest('reviewed_at')->take(4)->get()
            ->each(fn ($r) => $items->push([
                'type' => 'reimbursement',
                'text' => "Claim {$r->status} — {$r->employee->user?->name}",
                'at' => $r->reviewed_at,
            ]));

        Employee::with('user')->latest()->take(3)->get()
            ->each(fn ($e) => $items->push([
                'type' => 'hire',
                'text' => "New hire onboarded — {$e->user?->name}",
                'at' => $e->created_at,
            ]));

        return $items->sortByDesc('at')->take(6)->values()->all();
    }
}
