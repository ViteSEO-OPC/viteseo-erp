<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cycle_start' => $this->cycle_start?->format('Y-m-d'),
            'cycle_end' => $this->cycle_end?->format('Y-m-d'),
            'frequency' => $this->frequency,
            'status' => $this->status,
            'total_gross' => (float) $this->total_gross,
            'total_deductions' => (float) $this->total_deductions,
            'total_net' => (float) $this->total_net,
            'employee_count' => $this->employee_count,
            'run_at' => $this->run_at,
            'run_by' => $this->runner?->name,
            // Slice 5 — a locked run can no longer be recomputed.
            'locked_at' => $this->locked_at,
            'locked_by' => $this->locker?->name,
            'is_locked' => $this->locked_at !== null,
            // Whether every payslip has been paid out — the precondition for locking.
            'can_lock' => $this->whenLoaded('payslips', fn () => $this->locked_at === null
                && $this->payslips->isNotEmpty()
                && $this->payslips->every(fn ($p) => $p->status === 'disbursed')),
            // Slice 8 — the rules in force when this run executed. Detail view only: it's a
            // sizeable blob and the runs LIST would carry one per row for no reason.
            'policy_snapshot' => $this->whenLoaded('payslips', fn () => $this->policy_snapshot),
            // Which of those captured rules would price this cycle differently TODAY. Without
            // this the panel is silently stale: a rule changed after the run still applies to
            // the cycle (it is dated on or before cycle_end), so the frozen record and the
            // Company rules screen disagree and nothing explains why.
            'rules_drift' => $this->whenLoaded(
                'payslips',
                fn () => app(PayrollService::class)->snapshotDrift($this->resource),
            ),
            // Per-employee breakdown (only when payslips are eager-loaded).
            'breakdown' => $this->whenLoaded('payslips', fn () => $this->payslips->map(fn ($p) => [
                'id' => $p->id,
                'employee' => $p->employee->user?->name,
                'department' => $p->employee->department?->name,
                'gross' => (float) $p->gross,
                'deductions' => (float) $p->total_deductions,
                'net' => (float) $p->net_pay,
                'status' => $p->status,
            ])),
        ];
    }
}
