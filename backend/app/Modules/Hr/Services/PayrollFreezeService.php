<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Validation\ValidationException;

/**
 * Where payroll stops being editable.
 *
 * Reopening a decision (un-approving leave, un-paying a claim, …) rewrites an input
 * payroll may already have paid on. PayrollService refuses to recompute a locked run
 * or a disbursed payslip (see PayrollService::run()), so this draws the same line for
 * everything upstream: if the day a record covers has already been paid out, the
 * decision behind it is frozen too. Undo it by unlocking the run first.
 *
 * Resources with no bearing on pay (concerns, FAQs, document requests, …) skip this.
 */
class PayrollFreezeService
{
    /**
     * The run that has frozen an employee's day(s), or null when nothing has.
     *
     * @param  string  $from  Manila calendar date (Y-m-d)
     * @param  string|null  $to  End of the range; defaults to a single day.
     */
    public function runCovering(int $employeeId, string $from, ?string $to = null): ?PayrollRun
    {
        $to ??= $from;

        return PayrollRun::query()
            // FIN-001, same family — a 13th-month run must not freeze anything.
            //
            // It is created across {year}-12-01 to {year}-12-31 so that it can be measured on the
            // whole year, but it prices NOTHING from attendance, leave or overtime: its gross is
            // Σ basic ÷ 12 and its only deduction is tax. Without this filter, disbursing 13th
            // month would lock every December attendance correction, leave approval and OT
            // decision for the rest of the month — for a run that cannot be affected by any of
            // them. Freezing must follow the run that actually priced the day.
            ->where('type', '!=', 'thirteenth_month')
            // Any overlap between the record's dates and the run's cycle.
            ->whereDate('cycle_start', '<=', $to)
            ->whereDate('cycle_end', '>=', $from)
            ->where(function ($q) use ($employeeId) {
                $q->whereNotNull('locked_at')
                    ->orWhereExists(function ($sub) use ($employeeId) {
                        $sub->selectRaw('1')
                            ->from('hr_payslips')
                            ->whereColumn('hr_payslips.payroll_run_id', 'hr_payroll_runs.id')
                            ->where('hr_payslips.employee_id', $employeeId)
                            ->where('hr_payslips.status', 'disbursed')
                            ->whereNull('hr_payslips.deleted_at');
                    });
            })
            ->orderByDesc('cycle_end')
            ->first();
    }

    /**
     * Refuse the action when payroll has already settled the days it covers.
     *
     * @param  string  $action  Phrased to complete "Cannot … because …", e.g. "reopen this leave request".
     */
    public function assertOpen(int $employeeId, string $from, ?string $to, string $action): void
    {
        $run = $this->runCovering($employeeId, $from, $to);

        if (! $run) {
            return;
        }

        $period = $run->cycle_start->format('M j') . '–' . $run->cycle_end->format('M j, Y');

        throw ValidationException::withMessages([
            'status' => [
                $run->isLocked()
                    ? "Cannot {$action}: the {$period} payroll run is locked. Unlock it first."
                    : "Cannot {$action}: this period ({$period}) has already been paid out. Reverse the payslip first.",
            ],
        ]);
    }

    /**
     * The payslip guard for claims folded into a run: a claim marked paid by payroll
     * stays paid while that payslip is out the door.
     */
    public function assertPayslipOpen(?int $payrollRunId, int $employeeId, string $action): void
    {
        if (! $payrollRunId) {
            return;
        }

        $run = PayrollRun::find($payrollRunId);

        if (! $run) {
            return;
        }

        $disbursed = Payslip::where('payroll_run_id', $run->id)
            ->where('employee_id', $employeeId)
            ->where('status', 'disbursed')
            ->exists();

        if (! $run->isLocked() && ! $disbursed) {
            return;
        }

        $period = $run->cycle_start->format('M j') . '–' . $run->cycle_end->format('M j, Y');

        throw ValidationException::withMessages([
            'status' => [
                $run->isLocked()
                    ? "Cannot {$action}: the {$period} payroll run is locked. Unlock it first."
                    : "Cannot {$action}: it was paid on the {$period} payslip. Reverse that payslip first.",
            ],
        ]);
    }
}
