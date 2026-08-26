<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\ChangeRequest;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\DocumentRequest;
use App\Modules\Hr\Models\EarlyOutRequest;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\HalfDayRequest;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Models\PayrollInquiry;
use App\Modules\Hr\Models\PayslipRequest;
use App\Modules\Hr\Models\Reimbursement;
use App\Modules\Hr\Models\RemoteCheckin;
use App\Modules\Hr\Models\WorkRequest;

/**
 * The HR dashboard's approvals card, assembled in ONE place.
 *
 * Thirteen queues feed that card — leave, half-day, early-out, overtime, work requests, loans,
 * claims, field time-ins, document and profile-change requests, payslip requests, payroll
 * inquiries and concerns. The browser used to ask all thirteen endpoints itself, merge the
 * answers, sort them by date and render the top of the list: thirteen round trips, thirteen auth
 * checks and up to thirteen hundred rows travelling to draw twenty.
 *
 * Sorting thirteen lists by date to take the oldest twenty is a database's job.
 *
 * OLDEST FIRST, deliberately: this is a to-do list, and the thing that has been waiting longest
 * is the thing most worth doing. That is the opposite of the notification bell beside it, which
 * shows what happened most recently — the same thirteen queues, read for a different question.
 */
class ApprovalQueueService
{
    /** Where each kind is decided. The card links a row to the screen that can action it. */
    private const WORK_SCHEDULE = '/teamora/requests/work-schedule';

    /**
     * Every outstanding approval, oldest first.
     *
     * Each source is read BOUNDED — only as many rows as could possibly reach the page being
     * asked for. A source cannot contribute a row older than its own oldest, so the overall
     * oldest N can only come from each queue's own oldest N.
     *
     * @param  int  $limit  how many rows the card shows
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function pending(int $limit = 50): array
    {
        $items = [];
        $total = 0;

        foreach ($this->sources() as $source) {
            $rows = $source['query']
                ->orderByRaw($source['order'] . ' asc')
                ->limit($limit)
                ->get();

            // A source that came back SHORT has exhausted its queue, so those rows ARE the count
            // and asking again is waste. Only a full read leaves the question open — which is
            // exactly when the queue is big enough for the extra round trip to be worth it.
            $total += $rows->count() < $limit
                ? $rows->count()
                : (clone $source['query'])->count();

            foreach ($rows as $row) {
                $items[] = ($source['item'])($row);
            }
        }

        // Merged and re-sorted across every source, then cut to the card's length. Sorting on the
        // stamp each row contributed rather than on its own column, because they are not the same
        // column everywhere — a loan is waiting from when it was REQUESTED, a concern from when
        // it was SUBMITTED.
        usort($items, fn (array $a, array $b) => [$a['created_at'], $a['kind'], $a['id']]
            <=> [$b['created_at'], $b['kind'], $b['id']]);

        return [
            'items' => array_slice($items, 0, $limit),
            'total' => $total,
        ];
    }

    /**
     * The thirteen queues, each as a query, the column it has been waiting since, and how one of
     * its rows becomes a card row.
     *
     * @return list<array{query: mixed, order: string, item: callable}>
     */
    private function sources(): array
    {
        return [
            $this->source(
                LeaveRequest::with('employee.user')->where('status', 'pending'),
                'created_at',
                fn ($r) => $this->item('leave', $r->id, $r->employee?->user?->name, $r->created_at, self::WORK_SCHEDULE),
            ),
            $this->source(
                HalfDayRequest::with('employee.user')->where('status', 'pending'),
                'created_at',
                fn ($r) => $this->item('half_day', $r->id, $r->employee?->user?->name, $r->created_at, self::WORK_SCHEDULE),
            ),
            $this->source(
                EarlyOutRequest::with('employee.user')->where('status', 'pending'),
                'created_at',
                fn ($r) => $this->item('early_out', $r->id, $r->employee?->user?->name, $r->created_at, self::WORK_SCHEDULE),
            ),
            $this->source(
                OvertimeRequest::with('employee.user')->where('status', 'pending'),
                'created_at',
                fn ($r) => $this->item('overtime', $r->id, $r->employee?->user?->name, $r->created_at, self::WORK_SCHEDULE),
            ),
            $this->source(
                WorkRequest::with('employee.user')->where('status', 'pending'),
                'created_at',
                fn ($r) => $this->item('work_request', $r->id, $r->employee?->user?->name, $r->created_at, self::WORK_SCHEDULE),
            ),
            $this->source(
                // A loan HR issued directly has no request moment, so it has been waiting since
                // it was created. Sorted on the same expression it reports, or a bounded read
                // would return a different set from the one the merge then orders.
                EmployeeLoan::with('employee.user')->where('status', 'requested'),
                'coalesce(requested_at, created_at)',
                fn ($r) => $this->item(
                    'loan',
                    $r->id,
                    $r->employee?->user?->name,
                    $r->requested_at ?? $r->created_at,
                    "/teamora/people/employees/{$r->employee_id}",
                ),
            ),
            $this->source(
                Reimbursement::with('employee.user')->where('status', 'pending'),
                'created_at',
                fn ($r) => $this->item('claim', $r->id, $r->employee?->user?->name, $r->created_at, '/teamora/finance/reimbursement'),
            ),
            $this->source(
                RemoteCheckin::with('employee.user')->where('status', 'pending'),
                'created_at',
                fn ($r) => $this->item('timein', $r->id, $r->employee?->user?->name, $r->created_at, '/teamora/attendance'),
            ),
            $this->source(
                DocumentRequest::with('employee.user')->where('status', 'pending'),
                'created_at',
                fn ($r) => $this->item('document', $r->id, $r->employee?->user?->name, $r->created_at, '/teamora/requests/document'),
            ),
            $this->source(
                ChangeRequest::with('employee.user')->where('status', 'pending'),
                'created_at',
                fn ($r) => $this->item('change', $r->id, $r->employee?->user?->name, $r->created_at, '/teamora/requests/document'),
            ),
            $this->source(
                PayslipRequest::with('employee.user')->where('status', 'pending'),
                'created_at',
                fn ($r) => $this->item('payslip', $r->id, $r->employee?->user?->name, $r->created_at, '/teamora/requests/payroll'),
            ),
            $this->source(
                PayrollInquiry::with('employee.user')->where('status', 'pending'),
                'created_at',
                fn ($r) => $this->item('inquiry', $r->id, $r->employee?->user?->name, $r->created_at, '/teamora/requests/payroll'),
            ),
            $this->source(
                Concern::with('employee.user')->where('status', 'pending'),
                'submitted_at',
                fn ($r) => $this->item(
                    'concern',
                    $r->id,
                    // THE ANONYMITY INVARIANT. An anonymous concern never carries a name — not
                    // here and not to the browser, which is the point of moving this assembly to
                    // the server: the name is no longer sent and then hidden, it is never sent.
                    $r->is_anonymous ? 'Anonymous' : ($r->employee?->user?->name ?? 'Employee'),
                    $r->submitted_at ?? $r->created_at,
                    '/teamora/requests/concern',
                ),
            ),
        ];
    }

    /** @return array{query: mixed, order: string, item: callable} */
    private function source($query, string $order, callable $item): array
    {
        return ['query' => $query, 'order' => $order, 'item' => $item];
    }

    /** @return array<string, mixed> */
    private function item(string $kind, int $id, ?string $name, $at, string $href): array
    {
        return [
            // Unique across kinds, because two queues can both have a row 7.
            'key' => "{$kind}-{$id}",
            'kind' => $kind,
            'id' => $id,
            'name' => $name ?? '—',
            'created_at' => $at?->toIso8601String(),
            'href' => $href,
        ];
    }
}
