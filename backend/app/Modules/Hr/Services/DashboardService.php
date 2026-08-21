<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Attendance;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\PayrollSetting;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Models\Requisition;
use Illuminate\Support\Carbon;

class DashboardService
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly PayScheduleService $schedule,
    ) {}

    public function hrStats(): array
    {
        $tz = config('app.timezone_local');
        $today = now($tz)->toDateString();

        // DISTINCT employees, not approved requests. This card sits beside Headcount and is read
        // as a count of PEOPLE — two approved requests whose ranges both cover today (a leave
        // either side of a weekend, say) counted the same person twice.
        $onLeaveToday = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->distinct()
            ->count('employee_id');

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

    /**
     * WHEN THE COMPANY NEXT PAYS. Off the configured schedule — PayScheduleService is the single
     * source of truth for "when payroll happens", and this card is the one place that answered the
     * question for itself.
     *
     * What it used to do: take the last COMPLETED run's `cycle_end` and add a day. That is a cycle
     * START, not a pay date, and it moves with what has been RUN rather than with the calendar — so
     * running the Aug 16–31 cutoff early made the card announce September 1st on the 21st of August,
     * and running nothing at all left it on a date already in the past. The old fallback was wrong
     * twice over besides: it hardcoded a semi-monthly calendar whatever frequency the company is on,
     * and `day < 15` skipped the 15th itself, a pay date, straight to the end of the month.
     *
     * Pay GROUPS: an employee's own `pay_frequency` puts them on their own period, so the company's
     * next payday is the EARLIEST across the groups actually in use — not the default group's,
     * which a weekly group would beat every time.
     */
    private function nextPayrollDate(): string
    {
        $setting = PayrollSetting::current();
        $anchor = $setting->anchor_date ? Carbon::parse($setting->anchor_date) : null;
        $delay = (int) ($setting->pay_delay_days ?? 0);

        // The frequencies actually in use. An empty-string `pay_frequency` means "unset" and
        // falls back to the company default, exactly as myNextPayday() reads it.
        $frequencies = Employee::query()
            ->distinct()
            ->pluck('pay_frequency')
            ->map(fn ($f) => $f ?: $setting->frequency)
            ->filter()
            ->unique()
            ->all();

        if ($frequencies === []) {
            $frequencies = [$setting->frequency];
        }

        $dates = array_map(
            fn (string $frequency) => $this->schedule->nextPayDate($frequency, $anchor, $delay),
            $frequencies,
        );

        sort($dates);

        return $dates[0];
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
            // true when shipped: separations write `separation_date`, and the chart has been
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

    /**
     * On-time / late rate per day for the last 30 days.
     *
     * ONE grouped query, not thirty. It ran `Attendance::where(...)->get()` per day and hydrated
     * every employee's row for each of them purely to count three numbers — 30 round trips on a
     * screen that loads on every HR login.
     *
     * And every day's label is formatted the SAME way. A day with no attendance rows at all used
     * to push the raw `2026-07-24` while its neighbours pushed `Jul 24`, so the chart's axis
     * mixed two date formats and the quiet days were the ones that looked broken.
     */
    private function attendanceTrend(): array
    {
        $tz = config('app.timezone_local');
        $first = now($tz)->copy()->subDays(29)->startOfDay();

        $counts = Attendance::query()
            ->whereBetween('work_date', [$first->toDateString(), now($tz)->toDateString()])
            ->selectRaw('work_date, status, COUNT(*) as tally')
            ->groupBy('work_date', 'status')
            ->get()
            // toDateString() on purpose: `work_date` is a DATE, and parsing it with a timezone
            // would shift the key by a day. See the same note in AttendanceService.
            ->groupBy(fn ($row) => Carbon::parse($row->work_date)->toDateString());

        $points = [];

        for ($i = 0; $i < 30; $i++) {
            $day = $first->copy()->addDays($i);
            $rows = $counts->get($day->toDateString());
            $total = $rows?->sum('tally') ?? 0;

            $of = fn (string $status) => $total === 0
                ? 0
                : (int) round((($rows->firstWhere('status', $status)->tally ?? 0) / $total) * 100);

            $points[] = [
                'date' => $day->format('M j'),
                'on_time' => $of('present'),
                'late' => $of('late'),
            ];
        }

        return $points;
    }

    /**
     * The latest real status changes across the module.
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
