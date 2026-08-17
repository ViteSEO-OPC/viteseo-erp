<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\OvertimeRequest;
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
            $range = [$start->toDateString(), $end->toDateString()];

            // withTrashed on BOTH counts. A separation soft-deletes the record, so the default
            // scope hides leavers entirely — and it hides the month they were HIRED too, which
            // made past joiners quietly disappear from the chart as people left.
            $joiners = Employee::withTrashed()->whereBetween('hire_date', $range)->count();

            // `leavers` was hardcoded to 0 with a "no termination data yet" note that stopped being
            // true when MF-18 shipped: separations write `separation_date`, and the chart has been
            // reporting a company nobody ever leaves ever since.
            $leavers = Employee::withTrashed()->whereBetween('separation_date', $range)->count();

            return [
                'month' => $start->format('M'),
                'joiners' => $joiners,
                'leavers' => $leavers,
                'net' => $joiners - $leavers,
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
     * The latest real status changes across the module.
     *
     * It read three sources — leave, reimbursements and new hires — out of the dozen things that
     * actually happen, so a day spent approving overtime and early-outs left a feed insisting the
     * only recent events were two claims and a hire. Every decision type that carries a timestamp
     * is here now, and separations are included: someone leaving is the single most significant
     * change to a headcount and it was the one event the dashboard could not show at all.
     *
     * Each source takes a few rows and the merged list is cut to 6, so adding types widens the
     * CHOICE rather than the query cost.
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

        HalfDayRequest::with('employee.user')->whereNotNull('reviewed_at')
            ->latest('reviewed_at')->take(4)->get()
            ->each(fn ($h) => $items->push([
                'type' => 'half_day',
                'text' => "Half-day {$h->status} — {$h->employee->user?->name}",
                'at' => $h->reviewed_at,
            ]));

        EarlyOutRequest::with('employee.user')->whereNotNull('reviewed_at')
            ->latest('reviewed_at')->take(4)->get()
            ->each(fn ($e) => $items->push([
                'type' => 'early_out',
                'text' => "Early-out {$e->status} — {$e->employee->user?->name}",
                'at' => $e->reviewed_at,
            ]));

        OvertimeRequest::with('employee.user')->whereNotNull('reviewed_at')
            ->latest('reviewed_at')->take(4)->get()
            ->each(fn ($o) => $items->push([
                'type' => 'overtime',
                'text' => "Overtime {$o->status} — {$o->employee->user?->name}",
                'at' => $o->reviewed_at,
            ]));

        EmployeeLoan::with('employee.user')->whereNotNull('approved_at')
            ->latest('approved_at')->take(4)->get()
            ->each(fn ($l) => $items->push([
                'type' => 'loan',
                'text' => ucfirst($l->type) . " {$l->status} — {$l->employee->user?->name}",
                'at' => $l->approved_at,
            ]));

        Employee::with('user')->latest()->take(3)->get()
            ->each(fn ($e) => $items->push([
                'type' => 'hire',
                'text' => "New hire onboarded — {$e->user?->name}",
                'at' => $e->created_at,
            ]));

        // Leavers. `onlyTrashed` because a separation archives the record — without it the feed
        // cannot see the one event that changes a headcount most.
        Employee::onlyTrashed()->with('user')->whereNotNull('separation_date')
            ->latest('separation_date')->take(3)->get()
            ->each(fn ($e) => $items->push([
                'type' => 'separation',
                'text' => "Left the company — {$e->user?->name}",
                'at' => $e->separation_date,
            ]));

        return $items->filter(fn ($i) => $i['at'] !== null)
            ->sortByDesc('at')->take(6)->values()->all();
    }
}
