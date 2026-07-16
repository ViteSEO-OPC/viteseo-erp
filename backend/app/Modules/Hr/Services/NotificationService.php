<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\ChangeRequest;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\DocumentRequest;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\Meeting;
use App\Modules\Hr\Models\PayrollInquiry;
use App\Modules\Hr\Models\PayslipRequest;
use App\Modules\Hr\Models\Reimbursement;
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
    public function forUser(User $user, int $limit = 20, int $offset = 0): array
    {
        return $user->hasAnyRole(['hr_officer', 'super_admin'])
            ? $this->forHr($limit, $offset)
            : $this->forEmployee($user);
    }

    private function forHr(int $limit, int $offset): array
    {
        $items = new Collection();

        $pendingLeave = LeaveRequest::with(['employee.user', 'leaveType'])
            ->where('status', 'pending')->latest()->get();
        foreach ($pendingLeave as $l) {
            $items->push([
                'id' => "leave-{$l->id}",
                'title' => "Leave request · {$l->employee->user?->name}",
                'subtitle' => "{$l->leaveType->name} · {$l->days} day(s)",
                'href' => '/teamora/requests/leave',
                'at' => $l->created_at,
            ]);
        }

        $pendingHalfDay = HalfDayRequest::with('employee.user')
            ->where('status', 'pending')->latest()->get();
        foreach ($pendingHalfDay as $h) {
            $items->push([
                'id' => "halfday-{$h->id}",
                'title' => "Half-day request · {$h->employee->user?->name}",
                'subtitle' => "{$h->work_date->format('M j')} · {$h->period} (unpaid)",
                'href' => '/teamora/requests/leave',
                'at' => $h->created_at,
            ]);
        }

        $pendingReimb = Reimbursement::with('employee.user')
            ->where('status', 'pending')->latest()->get();
        foreach ($pendingReimb as $r) {
            $items->push([
                'id' => "claim-{$r->id}",
                'title' => "Reimbursement · {$r->employee->user?->name}",
                'subtitle' => ucfirst($r->category) . ' · ₱' . number_format((float) $r->amount, 0),
                'href' => '/teamora/finance/reimbursement',
                'at' => $r->created_at,
            ]);
        }

        $pendingDocs = DocumentRequest::with('employee.user')
            ->whereIn('status', ['pending', 'in_progress'])->latest()->get();
        foreach ($pendingDocs as $d) {
            $items->push([
                'id' => "doc-{$d->id}",
                'title' => "Document request · {$d->employee->user?->name}",
                'subtitle' => ucfirst($d->type) . ' record',
                'href' => '/teamora/requests/document',
                'at' => $d->created_at,
            ]);
        }

        $pendingChanges = ChangeRequest::with('employee.user')
            ->where('status', 'pending')->latest()->get();
        foreach ($pendingChanges as $c) {
            $items->push([
                'id' => "change-{$c->id}",
                'title' => "Change request · {$c->employee->user?->name}",
                'subtitle' => ucfirst(str_replace('_', ' ', $c->field)) . ' update',
                'href' => '/teamora/requests/document',
                'at' => $c->created_at,
            ]);
        }

        $pendingPayslipReqs = PayslipRequest::with('employee.user')
            ->where('status', 'pending')->latest()->get();
        foreach ($pendingPayslipReqs as $p) {
            $items->push([
                'id' => "payslipreq-{$p->id}",
                'title' => "Payslip request · {$p->employee->user?->name}",
                'subtitle' => 'Needs approval',
                'href' => '/teamora/requests/payroll',
                'at' => $p->created_at,
            ]);
        }

        $openInquiries = PayrollInquiry::with('employee.user')
            ->whereIn('status', ['pending', 'in_progress'])->latest()->get();
        foreach ($openInquiries as $q) {
            $items->push([
                'id' => "inquiry-{$q->id}",
                'title' => "Payroll inquiry · {$q->employee->user?->name}",
                'subtitle' => 'High priority',
                'href' => '/teamora/requests/payroll',
                'at' => $q->created_at,
            ]);
        }

        // Concerns — never reveal the submitter for anonymous ones.
        $openConcerns = Concern::with('employee.user')
            ->whereIn('status', ['pending', 'under_review'])->latest('submitted_at')->get();
        foreach ($openConcerns as $c) {
            $items->push([
                'id' => "concern-{$c->id}",
                'title' => $c->is_anonymous ? 'Anonymous concern' : "Concern · {$c->employee->user?->name}",
                'subtitle' => ucfirst($c->category) . " · {$c->reference_no}",
                'href' => '/teamora/requests/concern',
                'at' => $c->submitted_at,
            ]);
        }

        $sorted = $items->sortByDesc('at')->values();
        $total = $sorted->count();
        $paged = $sorted->slice($offset, $limit)->values();

        return [
            'total' => $total,
            'items' => $paged->all(),
            'has_more' => ($offset + $limit) < $total,
            'nav' => [
                '/hr/requests/leave' => $pendingLeave->count() + $pendingHalfDay->count(),
                '/hr/finance/reimbursement' => $pendingReimb->count(),
                '/hr/requests/document' => $pendingDocs->count() + $pendingChanges->count(),
                '/hr/requests/payroll' => $pendingPayslipReqs->count() + $openInquiries->count(),
                '/hr/requests/concern' => $openConcerns->count(),
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
                'subtitle' => "{$l->leaveType->name} · {$l->days} day(s)",
                'href' => '/teamora/time-off',
                'at' => $l->reviewed_at,
            ]));

        HalfDayRequest::where('employee_id', $employee->id)
            ->whereNotNull('reviewed_at')->latest('reviewed_at')->take(5)->get()
            ->each(fn ($h) => $items->push([
                'id' => "halfday-{$h->id}",
                'title' => "Half-day {$h->status}",
                'subtitle' => "{$h->work_date->format('M j')} · {$h->period}",
                'href' => '/teamora/time-off',
                'at' => $h->reviewed_at,
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

        $sorted = $items->sortByDesc('at')->take(8)->values();

        return [
            'total' => $sorted->count(),
            'items' => $sorted->all(),
            'has_more' => false,
            'nav' => [], // employees have no per-page action badges
        ];
    }
}
