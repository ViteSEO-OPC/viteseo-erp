<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\ChangeRequest;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\ConcernRevealRequest;
use App\Modules\Hr\Models\DocumentRequest;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\Meeting;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\PayrollInquiry;
use App\Modules\Hr\Models\PayslipRequest;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Models\RemoteCheckin;
use Illuminate\Support\Collection;

/**
 * Derives notifications from existing data (no notifications table — live/unread
 * tracking is Tier 2). HR sees things needing action (pending approvals);
 * employees see recent decisions on their own requests. `nav` maps a sidebar
 * href → a count so nav items can show a red badge only where relevant.
 *
 * @return array{total:int, items:array, nav:array<string,int>}
 */
class NotificationService
{
    /** Where leave, half-day, early-out and overtime are all reviewed. */
    private const WORK_SCHEDULE_REQUESTS = '/teamora/requests/work-schedule';

    /** The employee's own leave page. This was `/teamora/time-off`, which is not a route. */
    private const MY_TIME_LEAVE = '/teamora/time-leave';

    /** Where an employee sees their own schedule requests decided. */
    private const MY_WORK_SCHEDULE = '/teamora/work-schedule';

    public function forUser(User $user, int $limit = 20, int $offset = 0): array
    {
        return $user->hasAnyRole(['hr_officer', 'super_admin'])
            ? $this->forHr($limit, $offset)
            : $this->forEmployee($user);
    }

    /**
     * One source of the HR feed: the rows that could land on the page being asked for, and how
     * many are outstanding in total.
     *
     * Each source used to be fetched WHOLE — every pending row of every type, with its eager
     * loads — merged, sorted, then sliced down to twenty. That is twelve unbounded reads to render
     * twenty lines, and the cost grew with the size of the queues rather than the size of the page.
     *
     * Bounding each source to the window is safe because every source is ordered on the same key
     * the merge sorts by: the overall top N can only be drawn from each source's own top N.
     * `count` is taken separately and BEFORE the window, because the bell and the sidebar badges
     * describe the whole queue — a bell reading "3" beside nine outstanding requests is how work
     * goes unreviewed.
     *
     * `$orderBy` is a raw fragment so a source can order on an expression; it is a literal at
     * every call site and never carries request input.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return array{rows: \Illuminate\Database\Eloquent\Collection<int, *>, count: int}
     */
    private function pending($query, string $orderBy, int $window): array
    {
        $rows = (clone $query)->orderByRaw("{$orderBy} desc")->limit($window)->get();

        return [
            'rows' => $rows,
            // A page that came back SHORT has exhausted its queue, so those rows are the whole
            // count and asking the database again is pure waste. Only a full page leaves the
            // question open — which is exactly the case where the queue is big enough for the
            // COUNT to be worth a round trip. Small deployments therefore pay nothing for this.
            'count' => $rows->count() < $window ? $rows->count() : (clone $query)->count(),
        ];
    }

    private function forHr(int $limit, int $offset): array
    {
        $items = new Collection();

        // Only ever read enough of each queue to fill the page being asked for.
        $window = $offset + $limit;

        $pendingLeave = $this->pending(LeaveRequest::with(['employee.user', 'leaveType'])
            ->where('status', 'pending'), 'created_at', $window);
        foreach ($pendingLeave['rows'] as $l) {
            $items->push([
                'id' => "leave-{$l->id}",
                'title' => "Leave request · {$l->employee->user?->name}",
                'subtitle' => ($l->leaveType?->name ?? 'Leave') . " · {$l->days} day(s)",
                'href' => self::WORK_SCHEDULE_REQUESTS,
                'at' => $l->created_at,
            ]);
        }

        $pendingHalfDay = $this->pending(HalfDayRequest::with('employee.user')
            ->where('status', 'pending'), 'created_at', $window);
        foreach ($pendingHalfDay['rows'] as $h) {
            $items->push([
                'id' => "halfday-{$h->id}",
                'title' => "Half-day request · {$h->employee->user?->name}",
                'subtitle' => "{$h->work_date->format('M j')} · {$h->period} (unpaid)",
                'href' => self::WORK_SCHEDULE_REQUESTS,
                'at' => $h->created_at,
            ]);
        }

        // Early-out and overtime were reviewable from the day they shipped and appeared in NO
        // aggregate: not here, not on the dashboard, not as a badge. The only way to find one was
        // to open its page and look. Same review page as leave and half-day.
        $pendingEarlyOut = $this->pending(EarlyOutRequest::with('employee.user')
            ->where('status', 'pending'), 'created_at', $window);
        foreach ($pendingEarlyOut['rows'] as $e) {
            $items->push([
                'id' => "earlyout-{$e->id}",
                'title' => "Early-out request · {$e->employee->user?->name}",
                'subtitle' => $e->work_date->format('M j') . ' · unpaid',
                'href' => self::WORK_SCHEDULE_REQUESTS,
                'at' => $e->created_at,
            ]);
        }

        $pendingOvertime = $this->pending(OvertimeRequest::with('employee.user')
            ->where('status', 'pending'), 'created_at', $window);
        foreach ($pendingOvertime['rows'] as $o) {
            $items->push([
                'id' => "overtime-{$o->id}",
                'title' => "Overtime request · {$o->employee->user?->name}",
                'subtitle' => $o->work_date->format('M j') . ' · ' . rtrim(rtrim((string) $o->hours, '0'), '.') . ' hour(s)',
                'href' => self::WORK_SCHEDULE_REQUESTS,
                'at' => $o->created_at,
            ]);
        }

        // A loan sits at `requested` until HR decides. Approving does not start the deduction —
        // the employee still has to authorise it (Art. 113) — but nobody was being told to decide.
        $requestedLoans = $this->pending(EmployeeLoan::with('employee.user')
            ->where('status', 'requested'), 'coalesce(requested_at, created_at)', $window);
        foreach ($requestedLoans['rows'] as $loan) {
            $items->push([
                'id' => "loan-{$loan->id}",
                'title' => ucfirst($loan->type) . " request · {$loan->employee->user?->name}",
                'subtitle' => '₱' . number_format((float) $loan->principal, 2) . ' principal',
                // Decided on the employee's record (Compensation tab). `/teamora/loans` is the
                // EMPLOYEE's own page — sending HR there would show them their own loans.
                'href' => "/teamora/people/employees/{$loan->employee_id}",
                'at' => $loan->requested_at ?? $loan->created_at,
            ]);
        }

        $pendingReimb = $this->pending(Reimbursement::with('employee.user')
            ->where('status', 'pending'), 'created_at', $window);
        foreach ($pendingReimb['rows'] as $r) {
            $items->push([
                'id' => "claim-{$r->id}",
                'title' => "Reimbursement · {$r->employee->user?->name}",
                'subtitle' => ucfirst($r->category) . ' · ₱' . number_format((float) $r->amount, 0),
                'href' => '/teamora/finance/reimbursement',
                'at' => $r->created_at,
            ]);
        }

        // Off-site time-ins. These are the most time-critical thing in the list: the
        // employee is already working and isn't on the clock until HR decides.
        $pendingCheckins = $this->pending(RemoteCheckin::with('employee.user')
            ->where('status', RemoteCheckin::STATUS_PENDING), 'created_at', $window);
        foreach ($pendingCheckins['rows'] as $rc) {
            $items->push([
                'id' => "checkin-{$rc->id}",
                'title' => "Field time-in · {$rc->employee->user?->name}",
                'subtitle' => 'Photo needs review',
                'href' => '/teamora/attendance',
                'at' => $rc->created_at,
            ]);
        }

        $pendingDocs = $this->pending(DocumentRequest::with('employee.user')
            ->whereIn('status', ['pending', 'in_progress']), 'created_at', $window);
        foreach ($pendingDocs['rows'] as $d) {
            $items->push([
                'id' => "doc-{$d->id}",
                'title' => "Document request · {$d->employee->user?->name}",
                'subtitle' => ucfirst($d->type) . ' record',
                'href' => '/teamora/requests/document',
                'at' => $d->created_at,
            ]);
        }

        $pendingChanges = $this->pending(ChangeRequest::with('employee.user')
            ->where('status', 'pending'), 'created_at', $window);
        foreach ($pendingChanges['rows'] as $c) {
            $items->push([
                'id' => "change-{$c->id}",
                'title' => "Change request · {$c->employee->user?->name}",
                'subtitle' => ucfirst(str_replace('_', ' ', $c->field)) . ' update',
                'href' => '/teamora/requests/document',
                'at' => $c->created_at,
            ]);
        }

        $pendingPayslipReqs = $this->pending(PayslipRequest::with('employee.user')
            ->where('status', 'pending'), 'created_at', $window);
        foreach ($pendingPayslipReqs['rows'] as $p) {
            $items->push([
                'id' => "payslipreq-{$p->id}",
                'title' => "Payslip request · {$p->employee->user?->name}",
                'subtitle' => 'Needs approval',
                'href' => '/teamora/requests/payroll',
                'at' => $p->created_at,
            ]);
        }

        $openInquiries = $this->pending(PayrollInquiry::with('employee.user')
            ->whereIn('status', ['pending', 'in_progress']), 'created_at', $window);
        foreach ($openInquiries['rows'] as $q) {
            $items->push([
                'id' => "inquiry-{$q->id}",
                'title' => "Payroll inquiry · {$q->employee->user?->name}",
                'subtitle' => 'High priority',
                'href' => '/teamora/requests/payroll',
                'at' => $q->created_at,
            ]);
        }

        // Concerns — never reveal the submitter for anonymous ones.
        $openConcerns = $this->pending(Concern::with('employee.user')
            ->whereIn('status', ['pending', 'under_review']), 'submitted_at', $window);
        foreach ($openConcerns['rows'] as $c) {
            $items->push([
                'id' => "concern-{$c->id}",
                'title' => $c->is_anonymous ? 'Anonymous concern' : "Concern · {$c->employee->user?->name}",
                'subtitle' => ucfirst($c->category) . " · {$c->reference_no}",
                'href' => '/teamora/requests/concern',
                'at' => $c->submitted_at,
            ]);
        }

        $sorted = $items->sortByDesc('at')->values();
        // Summed from the per-source COUNTS, not from `$sorted` — `$items` now holds only enough
        // of each queue to fill the requested page, so counting it would report the page size and
        // understate the work outstanding.
        $total = $pendingLeave['count'] + $pendingHalfDay['count'] + $pendingEarlyOut['count']
            + $pendingOvertime['count'] + $requestedLoans['count'] + $pendingReimb['count']
            + $pendingCheckins['count'] + $pendingDocs['count'] + $pendingChanges['count']
            + $pendingPayslipReqs['count'] + $openInquiries['count'] + $openConcerns['count'];
        $paged = $sorted->slice($offset, $limit)->values();

        return [
            'total' => $total,
            'items' => $paged->all(),
            'has_more' => ($offset + $limit) < $total,
            'nav' => [
                '/teamora/attendance' => $pendingCheckins['count'],
                // Keyed by NAV HREF, matched exactly by the sidebar (`navBadges[child.href]`).
                // This said `/teamora/requests/leave`, which is not a nav item and not a page —
                // leave and half-day are reviewed on the work-schedule page with early-out and
                // overtime. Every count written here was therefore dropped on the floor.
                self::WORK_SCHEDULE_REQUESTS => $pendingLeave['count'] + $pendingHalfDay['count']
                    + $pendingEarlyOut['count'] + $pendingOvertime['count'],
                '/teamora/finance/reimbursement' => $pendingReimb['count'],
                '/teamora/requests/document' => $pendingDocs['count'] + $pendingChanges['count'],
                '/teamora/requests/payroll' => $pendingPayslipReqs['count'] + $openInquiries['count'],
                '/teamora/requests/concern' => $openConcerns['count'],
            ],
        ];
    }

    private function forEmployee(User $user): array
    {
        $employee = $user->employee;
        if (! $employee) {
            return ['total' => 0, 'items' => [], 'nav' => []];
        }

        $items = new Collection();

        LeaveRequest::with('leaveType')->where('employee_id', $employee->id)
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->take(5)->get()
            ->each(fn ($l) => $items->push([
                'id' => "leave-{$l->id}",
                'title' => "Leave {$l->status}",
                'subtitle' => ($l->leaveType?->name ?? 'Leave') . " · {$l->days} day(s)",
                'href' => self::MY_TIME_LEAVE,
                'at' => $l->reviewed_at,
            ]));

        HalfDayRequest::where('employee_id', $employee->id)
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->take(5)->get()
            ->each(fn ($h) => $items->push([
                'id' => "halfday-{$h->id}",
                'title' => "Half-day {$h->status}",
                'subtitle' => "{$h->work_date->format('M j')} · {$h->period}",
                'href' => self::MY_TIME_LEAVE,
                'at' => $h->reviewed_at,
            ]));

        // The employee was told about leave, half-day, claims, check-ins, documents, changes,
        // payslips, inquiries and concerns — but not about the three below, so an approved
        // overtime or a decided loan simply never appeared. A loan is the one that matters most:
        // approval does NOT start the deduction, the employee has to authorise it themselves
        // (Art. 113), and they cannot do that if nothing tells them there is anything to do.
        EarlyOutRequest::where('employee_id', $employee->id)
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->take(5)->get()
            ->each(fn ($e) => $items->push([
                'id' => "earlyout-{$e->id}",
                'title' => "Early-out {$e->status}",
                'subtitle' => $e->work_date->format('M j') . ' · unpaid',
                'href' => self::MY_WORK_SCHEDULE,
                'at' => $e->reviewed_at,
            ]));

        OvertimeRequest::where('employee_id', $employee->id)
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->take(5)->get()
            ->each(fn ($o) => $items->push([
                'id' => "overtime-{$o->id}",
                'title' => "Overtime {$o->status}",
                'subtitle' => $o->work_date->format('M j') . ' · ' . rtrim(rtrim((string) $o->hours, '0'), '.') . ' hour(s)',
                'href' => self::MY_WORK_SCHEDULE,
                'at' => $o->reviewed_at,
            ]));

        EmployeeLoan::where('employee_id', $employee->id)
            ->whereNotNull('approved_at')->latest('approved_at')->take(5)->get()
            ->each(fn ($loan) => $items->push([
                'id' => "loan-{$loan->id}",
                'title' => $loan->status === 'pending_authorization'
                    ? ucfirst($loan->type) . ' approved — your authorisation needed'
                    : ucfirst($loan->type) . " {$loan->status}",
                'subtitle' => '₱' . number_format((float) $loan->principal, 2) . ' principal',
                'href' => '/teamora/loans',
                'at' => $loan->approved_at,
            ]));

        Reimbursement::where('employee_id', $employee->id)
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->take(5)->get()
            ->each(fn ($r) => $items->push([
                'id' => "claim-{$r->id}",
                'title' => "Claim {$r->status}",
                'subtitle' => ucfirst($r->category) . ' · ₱' . number_format((float) $r->amount, 0),
                'href' => '/teamora/claims',
                'at' => $r->reviewed_at,
            ]));

        // A rejected field time-in is the one decision here the employee must ACT on —
        // they aren't clocked in and have to re-submit — so the note carries HR's reason.
        RemoteCheckin::where('employee_id', $employee->id)
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->take(5)->get()
            ->each(fn ($rc) => $items->push([
                'id' => "checkin-{$rc->id}",
                'title' => "Field time-in {$rc->status}",
                'subtitle' => $rc->status === RemoteCheckin::STATUS_REJECTED
                    ? ($rc->rejection_note ?? 'Submit a new photo to time in.')
                    : 'Your time in was recorded.',
                'href' => '/teamora/attendance',
                'at' => $rc->reviewed_at,
            ]));

        DocumentRequest::where('employee_id', $employee->id)
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->take(5)->get()
            ->each(fn ($d) => $items->push([
                'id' => "doc-{$d->id}",
                'title' => "Document request {$d->status}",
                'subtitle' => ucfirst($d->type) . ' record',
                'href' => '/teamora/profile',
                'at' => $d->reviewed_at,
            ]));

        ChangeRequest::where('employee_id', $employee->id)
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->take(5)->get()
            ->each(fn ($c) => $items->push([
                'id' => "change-{$c->id}",
                'title' => "Change request {$c->status}",
                'subtitle' => ucfirst(str_replace('_', ' ', $c->field)) . ' update',
                'href' => '/teamora/profile',
                'at' => $c->reviewed_at,
            ]));

        PayslipRequest::where('employee_id', $employee->id)
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->take(5)->get()
            ->each(fn ($p) => $items->push([
                'id' => "payslipreq-{$p->id}",
                'title' => "Payslip request {$p->status}",
                'subtitle' => 'Payslip copy',
                'href' => '/teamora/payslips',
                'at' => $p->reviewed_at,
            ]));

        PayrollInquiry::where('employee_id', $employee->id)
            ->where('status', 'done')->whereNotNull('resolved_at')->latest('resolved_at')->take(5)->get()
            ->each(fn ($q) => $items->push([
                'id' => "inquiry-{$q->id}",
                'title' => 'Payroll inquiry resolved',
                'subtitle' => 'HR responded',
                'href' => '/teamora/payslips',
                'at' => $q->resolved_at,
            ]));

        // Meeting invites routed to this employee (named OR via their anon concern).
        Meeting::with('concern')->where('status', 'scheduled')
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->orWhereHas('concern', fn ($c) => $c->where('employee_id', $employee->id)))
            ->latest('scheduled_at')->take(5)->get()
            ->each(fn ($m) => $items->push([
                'id' => "meeting-{$m->id}",
                'title' => 'Meeting invitation',
                'subtitle' => $m->concern ? "Re: {$m->concern->reference_no}" : 'HR scheduled a meeting',
                'href' => '/teamora/concerns',
                'at' => $m->created_at,
            ]));

        Concern::where('employee_id', $employee->id)
            ->whereNotNull('resolved_at')->latest('resolved_at')->take(3)->get()
            ->each(fn ($c) => $items->push([
                'id' => "concern-{$c->id}",
                'title' => 'Concern resolved',
                'subtitle' => $c->reference_no,
                'href' => '/teamora/concerns',
                'at' => $c->resolved_at,
            ]));

        // HR has asked to know who filed one of their anonymous concerns. The one item in this
        // list the employee is expected to ACT on rather than read — and it expires by being
        // answered, so it is surfaced by its own status rather than a date window.
        ConcernRevealRequest::with('concern')
            ->where('status', 'pending')
            ->whereHas('concern', fn ($q) => $q->where('employee_id', $employee->id))
            ->latest('id')->take(5)->get()
            ->each(fn ($r) => $items->push([
                'id' => "reveal-{$r->id}",
                'title' => 'HR asked to know who you are',
                'subtitle' => $r->concern?->reference_no ?? 'Anonymous concern',
                'href' => '/teamora/concerns',
                'at' => $r->created_at,
            ]));

        $sorted = $items->sortByDesc('at')->take(8)->values();

        return [
            'total' => $sorted->count(),
            'items' => $sorted->all(),
            'has_more' => false,
            'nav' => [], // employees have no per-page action badges
        ];
    }
}
