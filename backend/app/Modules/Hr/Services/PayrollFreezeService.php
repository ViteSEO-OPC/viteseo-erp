<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Validation\ValidationException;

/** Where payroll stops being editable. */
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
            // Same family — a 13th-month run must not freeze anything.
            ->whereNotIn('type', ['thirteenth_month', 'adjustment'])
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

    /**
     * Completed runs whose cycle COVERS a date — company-wide, for a rule that bends that one day.
     *
     * The per-employee `completedRunsRepricedBy()` above answers a different question: a salary
     * change is one person's and reaches forward from its date, while a daily rule override is
     * everyone's and reaches exactly one day. Same purpose, though — name the periods that were
     * priced under the old rule, so the change can be confirmed rather than applied blind.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PayrollRun>
     */
    public function completedRunsCovering(string $date): \Illuminate\Database\Eloquent\Collection
    {
        return PayrollRun::query()
            ->where('status', 'completed')
            // Same family the freeze uses: 13th month and off-cycle adjustments are not priced
            // from attendance, so a grace change cannot move them.
            ->whereNotIn('type', ['thirteenth_month', 'adjustment'])
            ->whereDate('cycle_start', '<=', $date)
            ->whereDate('cycle_end', '>=', $date)
            ->orderBy('cycle_start')
            ->get();
    }

    /**
     * Completed runs for one employee that a change effective from `$from` would reprice
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PayrollRun>
     */
    public function completedRunsRepricedBy(int $employeeId, string $from): \Illuminate\Database\Eloquent\Collection
    {
        return PayrollRun::query()
            ->where('status', 'completed')
            ->where('type', 'regular')          // 13th month is measured on basic already paid
            ->whereDate('cycle_end', '>=', $from)
            ->whereExists(function ($sub) use ($employeeId) {
                $sub->selectRaw('1')
                    ->from('hr_payslips')
                    ->whereColumn('hr_payslips.payroll_run_id', 'hr_payroll_runs.id')
                    ->where('hr_payslips.employee_id', $employeeId)
                    ->whereNull('hr_payslips.deleted_at');
            })
            ->orderBy('cycle_start')
            ->get();
    }
}
