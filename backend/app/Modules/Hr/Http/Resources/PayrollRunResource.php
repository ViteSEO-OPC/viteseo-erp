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

    /** The whole-run counts from PayrollService::runTotals(). @var array<string, mixed>|null */
    private ?array $runTotalsMemo = null;

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
            // How many payslips this run left untouched because they were already
            // disbursed. Present only on the response to a run that just executed; null when the
            // run is merely being read back, where it would describe an event, not a state.
            'skipped_disbursed' => $this->resource->skippedDisbursedCount,
            // Corrections this recompute found the engine has since disagreed with. Same rule as
            // the line above: present on the response to a run that just executed, null when the
            // run is merely being read back.
            'override_notes' => $this->resource->overrideNotes,
            // A locked run can no longer be recomputed.
            'locked_at' => $this->locked_at,
            'locked_by' => $this->locker?->name,
            'is_locked' => $this->locked_at !== null,
            // Whether every payslip has been paid out IN FULL — the precondition for locking.
            //
            // Counted by the database. This used to walk the loaded payslip collection, which was
            // the whole run and is now one page: ten settled rows out of a company would have
            // offered the button that seals a cycle permanently.
            'can_lock' => $this->whenDetailed(fn () => $this->totals()['can_lock']),
            // How many rows a correction left short or over, and by how much — the notices above
            // the breakdown. Same reason as can_lock: these are statements about the RUN, and the
            // screen holds ten of its rows.
            'disbursed_count' => $this->whenDetailed(fn () => $this->totals()['disbursed_count']),
            'owing_count' => $this->whenDetailed(fn () => $this->totals()['owing_count']),
            'owing_total' => $this->whenDetailed(fn () => $this->totals()['owing_total']),
            'overpaid_count' => $this->whenDetailed(fn () => $this->totals()['overpaid_count']),
            'overpaid_total' => $this->whenDetailed(fn () => $this->totals()['overpaid_total']),
            // What the breakdown below is a page of.
            'breakdown_meta' => $this->resource->breakdownMeta,
            // An earlier period of this tax year was amended after this run computed its withholding,
            // so the run is settled against year-to-date figures that have since moved. Recomputing
            // it is the only thing that clears this — see PayrollService::flagTaxStaleRuns().
            'is_tax_stale' => $this->tax_stale_at !== null,
            'tax_stale_at' => $this->tax_stale_at,
            'tax_stale_reason' => $this->tax_stale_reason,
            // The rules in force when this run executed. Detail view only: it's a
            // sizeable blob and the runs LIST would carry one per row for no reason.
            'policy_snapshot' => $this->whenDetailed(fn () => $this->policy_snapshot),
            // Which of those captured rules would price this cycle differently TODAY. Without
            // this the panel is silently stale: a rule changed after the run still applies to
            // the cycle (it is dated on or before cycle_end), so the frozen record and the
            // Company rules screen disagree and nothing explains why.
            'rules_drift' => $this->whenDetailed(
                fn () => app(PayrollService::class)->snapshotDrift($this->resource),
            ),
            // Per-employee breakdown (only when payslips are eager-loaded). Each row is the same
            // three figures the employee's own payslip shows, so the register and the document
            // can be read side by side — which is the whole point of routing both through
            // Payslip::registerTotals().
            'breakdown' => $this->whenDetailed(fn () => $this->payslips->map(function (Payslip $p) {
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
     * Detail-view only. The signal is the breakdown stamp, not "are payslips loaded" — the runs
     * LIST must not carry a policy snapshot or a lock verdict per row, and now that the detail
     * view loads a page rather than the relation, "loaded" no longer distinguishes the two.
     */
    private function whenDetailed(\Closure $value): mixed
    {
        return $this->when($this->resource->breakdownMeta !== null, $value);
    }

    /** The whole-run figures, memoised so one response asks for them once. */
    private function totals(): array
    {
        return $this->runTotalsMemo ??= app(PayrollService::class)->runTotals($this->resource);
    }

    /**
     * The run's headline figures, summed on the SAME definition as each payslip's.
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

        // DELIBERATELY NOT summed from the loaded relation any more. The detail endpoint used to
        // load every payslip, so adding them up here was the whole run; it now loads ONE PAGE,
        // and the same code would have reported the register totals of ten employees as the
        // totals of the payroll. Always ask the database.

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
