<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    /** @var array{gross: float, deductions: float, net: float}|null */
    private ?array $totalsMemo = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $totals = $this->registerTotals();

        return [
            'id' => $this->id,
            'cycle_start' => $this->cycle_start?->format('Y-m-d'),
            'cycle_end' => $this->cycle_end?->format('Y-m-d'),
            'frequency' => $this->frequency,
            // regular · thirteenth_month · adjustment · final_pay. `frequency` carries the internal
            // key for the last three (`final_pay:{id}`), so it cannot be read as the run's kind —
            // which the period picker for a correction has to know to label what it is offering.
            'type' => $this->type,
            'status' => $this->status,
            // Stated the way the PAYSLIP states them — see Payslip::registerTotals() for why the
            // stored columns are not these figures. The stored ones remain the engine's record and
            // are what the BIR and reconciliation reports read.
            'total_gross' => $totals['gross'],
            'total_deductions' => $totals['deductions'],
            'total_net' => $totals['net'],
            'employee_count' => $this->employee_count,
            'run_at' => $this->run_at,
            'run_by' => $this->runner?->name,
            // FIN-051 — how many payslips this run left untouched because they were already
            // disbursed. Present only on the response to a run that just executed; null when the
            // run is merely being read back, where it would describe an event, not a state.
            'skipped_disbursed' => $this->resource->skippedDisbursedCount,
            // Slice 5 — a locked run can no longer be recomputed.
            'locked_at' => $this->locked_at,
            'locked_by' => $this->locker?->name,
            'is_locked' => $this->locked_at !== null,
            // Whether every payslip has been paid out IN FULL — the precondition for locking.
            //
            // The balance test is not redundant with the status one. A payslip amended after it was
            // disbursed keeps that status while owing the difference, so status alone would let a run
            // be sealed — permanently, that being what locking means — over money still outstanding.
            'can_lock' => $this->whenLoaded('payslips', fn () => $this->locked_at === null
                && $this->payslips->isNotEmpty()
                && $this->payslips->every(fn (Payslip $p) => $p->status === 'disbursed'
                    && $p->amountOutstanding() <= 0)),
            // An earlier period of this tax year was amended after this run computed its withholding,
            // so the run is settled against year-to-date figures that have since moved. Recomputing
            // it is the only thing that clears this — see PayrollService::flagTaxStaleRuns().
            'is_tax_stale' => $this->tax_stale_at !== null,
            'tax_stale_at' => $this->tax_stale_at,
            'tax_stale_reason' => $this->tax_stale_reason,
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
            // Per-employee breakdown (only when payslips are eager-loaded). Each row is the same
            // three figures the employee's own payslip shows, so the register and the document
            // can be read side by side — which is the whole point of routing both through
            // Payslip::registerTotals().
            'breakdown' => $this->whenLoaded('payslips', fn () => $this->payslips->map(function (Payslip $p) {
                $row = $p->registerTotals();

                return [
                    'id' => $p->id,
                    // The EMPLOYEE, not just their name. A correction is addressed to an employee id,
                    // and the row carrying only a display name meant the amendment form had to fetch
                    // the whole directory and match on text to find out who this period actually paid.
                    'employee_id' => $p->employee_id,
                    'employee' => $p->employee->user?->name,
                    'department' => $p->employee->department?->name,
                    'gross' => $row['gross'],
                    'deductions' => $row['deductions'],
                    'net' => $row['net'],
                    'status' => $p->status,
                    // What has actually been transferred against this row, and what a correction
                    // raised after payment has left owing. `net` alone stopped being the answer to
                    // "how much does this person still get" once a paid payslip could be amended.
                    'disbursed' => $p->disbursed_amount === null ? null : (float) $p->disbursed_amount,
                    'outstanding' => $p->amountOutstanding(),
                ];
            })),
        ];
    }

    /**
     * The run's headline figures, summed on the SAME definition as each payslip's.
     *
     * WHY IT IS NOT SIMPLY THE STORED COLUMNS. The summary cards sit directly above the breakdown
     * table, so if the rows state gross before absences and the cards state it after, the cards
     * stop being the sum of the column beneath them — trading one inconsistency for a worse one
     * that is visible on a single screen.
     *
     * Reads aggregates the query already loaded (`listRuns()` and `runWithBreakdown()` both add
     * them) and falls back to ONE grouped query otherwise, so a run rendered straight after an
     * action reports the same figures as the list it returns to. A run with no payslips keeps its
     * stored totals: there is nothing to re-state and zeroes would be a claim, not a restatement.
     *
     * @return array{gross: float, deductions: float, net: float}
     */
    private function registerTotals(): array
    {
        if ($this->totalsMemo !== null) {
            return $this->totalsMemo;
        }

        $sums = $this->slipSums();

        if ($sums === null) {
            return $this->totalsMemo = [
                'gross' => (float) $this->total_gross,
                'deductions' => (float) $this->total_deductions,
                'net' => (float) $this->total_net,
            ];
        }

        $gross = round($sums['gross'] + $sums['absence'], 2);
        $net = round($sums['net'], 2);

        return $this->totalsMemo = [
            'gross' => $gross,
            'deductions' => round($gross - $net, 2),
            'net' => $net,
        ];
    }

    /**
     * Σ gross, Σ absence charge and Σ net across this run's payslips — or null when it has none.
     *
     * @return array{gross: float, absence: float, net: float}|null
     */
    private function slipSums(): ?array
    {
        // Preloaded aggregate: the list endpoint carries these so a page of runs costs one query.
        if ($this->resource->getAttribute('slips_count') !== null) {
            return (int) $this->resource->getAttribute('slips_count') === 0 ? null : [
                'gross' => (float) $this->resource->getAttribute('slips_gross'),
                'absence' => (float) $this->resource->getAttribute('slips_absence'),
                'net' => (float) $this->resource->getAttribute('slips_net'),
            ];
        }

        // Already in memory (the detail endpoint loads the rows to build the breakdown).
        if ($this->resource->relationLoaded('payslips')) {
            return $this->payslips->isEmpty() ? null : [
                'gross' => (float) $this->payslips->sum(fn (Payslip $p) => (float) $p->gross),
                'absence' => (float) $this->payslips->sum(fn (Payslip $p) => (float) $p->absence_deduction),
                'net' => (float) $this->payslips->sum(fn (Payslip $p) => (float) $p->net_pay),
            ];
        }

        $row = Payslip::query()
            ->where('payroll_run_id', $this->id)
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(gross), 0) AS g, COALESCE(SUM(absence_deduction), 0) AS a, COALESCE(SUM(net_pay), 0) AS n')
            ->first();

        return $row === null || (int) $row->c === 0 ? null : [
            'gross' => (float) $row->g,
            'absence' => (float) $row->a,
            'net' => (float) $row->n,
        ];
    }
}
