<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Payment;
use App\Modules\Hr\Models\PaymentBatch;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turning a completed payroll run into money that actually leaves the company (FIN-015).
 *
 * Payroll computed net pay correctly and then stopped tracking it: "disbursement" was a manual
 * status flip per payslip, with no payment record, no bank reference and no way to learn that a
 * transfer had bounced. This service builds the missing half — a batch of payment rows per run,
 * and the bank file that batch produces.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. Building a batch does NOT mark anything disbursed. That is the
 * whole point of separating the three states the audit named (computed → disbursed → settled): a
 * batch says "we intend to pay these", and only the bank's own confirmation says money moved.
 * Flipping payslips at build time would recreate the assertion-without-evidence that FIN-052 is
 * about, just with more tables behind it.
 */
class PaymentService
{
    public function __construct(private AuditService $audit) {}

    /**
     * Build the batch of payments for a completed run.
     *
     * WHAT IS INCLUDED. Every payslip on the run that is still `computed`, has a net above zero,
     * and is not already sitting in a live batch. Each exclusion is reported back rather than
     * silently dropped, because the number finance checks is "does this batch equal the run", and
     * a batch that quietly omits three people looks identical to one that does not.
     *
     * @return array{batch: PaymentBatch, skipped: array<string, int>}
     */
    public function buildForRun(PayrollRun $run, string $method, ?string $notes, User $actor): array
    {
        if ($run->status !== 'completed') {
            throw ValidationException::withMessages([
                'payroll_run_id' => 'Only a completed payroll run can be paid out. This run is ' . $run->status . '.',
            ]);
        }

        return DB::transaction(function () use ($run, $method, $notes, $actor) {
            $payslips = Payslip::with('employee')
                ->where('payroll_run_id', $run->id)
                ->lockForUpdate()
                ->get();

            // Already in a live batch — the guard against paying a run twice. Checked against the
            // payments table rather than a flag on the payslip so a soft-deleted batch releases
            // its payslips automatically.
            $alreadyBatched = Payment::whereIn('payslip_id', $payslips->pluck('id'))
                ->pluck('payslip_id')
                ->all();

            $skipped = [
                'already_disbursed' => 0,
                'already_batched' => 0,
                'zero_net' => 0,
                'missing_bank_details' => 0,
            ];
            $missingDetails = [];
            $payable = new Collection;

            foreach ($payslips as $payslip) {
                if (in_array($payslip->id, $alreadyBatched, true)) {
                    $skipped['already_batched']++;

                    continue;
                }

                // Manually marked paid before these tables existed, or paid by hand outside the
                // bank run. Either way the money has gone; a transfer would be a second one.
                if ($payslip->status === 'disbursed') {
                    $skipped['already_disbursed']++;

                    continue;
                }

                // A bank will not process a ₱0 instruction and finance should not be asked to
                // explain one. Net is floored at zero by `computePayslip()`, so this is only ever
                // exactly zero — a period fully consumed by deductions.
                if ((float) $payslip->net_pay <= 0) {
                    $skipped['zero_net']++;

                    continue;
                }

                // A transfer needs somewhere to go. Found the hard way against the demo data:
                // 11 of 12 employees had no bank account on file and the batch cheerfully wrote
                // 11 transfers with a blank account number — a file the bank rejects, or worse
                // part-processes. They are skipped rather than refused outright, because a company
                // paying one person in cash should not be blocked from paying everyone else by
                // transfer; run a `cash` batch for the remainder and the two together cover the
                // run. `reconcileWithRun()` reports the shortfall until they do.
                if ($method === 'bank_transfer' && blank($payslip->employee?->bank_account)) {
                    $skipped['missing_bank_details']++;
                    $missingDetails[] = $payslip->employee?->employee_no ?? "#{$payslip->employee_id}";

                    continue;
                }

                $payable->push($payslip);
            }

            if ($payable->isEmpty()) {
                throw ValidationException::withMessages([
                    'payroll_run_id' => $this->nothingToPayMessage($skipped, $missingDetails),
                ]);
            }

            $batch = PaymentBatch::create([
                'payroll_run_id' => $run->id,
                'reference' => $this->nextReference($run),
                'method' => $method,
                'status' => 'pending',
                'total_amount' => 0,
                'payment_count' => 0,
                'created_by' => $actor->id,
                'notes' => $notes,
            ]);

            $total = 0.0;

            foreach ($payable as $payslip) {
                $employee = $payslip->employee;

                Payment::create([
                    'payment_batch_id' => $batch->id,
                    'payslip_id' => $payslip->id,
                    'employee_id' => $payslip->employee_id,
                    'amount' => $payslip->net_pay,
                    'method' => $method,
                    // Snapshot — see the Payment docblock. Deliberately not a join: where the
                    // money went must not change when the employee changes bank.
                    'bank_name' => $employee?->bank_name,
                    'bank_account' => $employee?->bank_account,
                    'bank_account_name' => $employee?->bank_account_name,
                    'bank_account_type' => $employee?->bank_account_type,
                    'status' => 'pending',
                ]);

                $total += (float) $payslip->net_pay;
            }

            $batch->update([
                'total_amount' => round($total, 2),
                'payment_count' => $payable->count(),
            ]);

            $this->audit->log(
                'created', 'PaymentBatch',
                'Built payment batch ' . $batch->reference . ' for ' . $payable->count()
                    . ' employee(s) — ₱' . number_format($total, 2),
                $batch,
            );

            return ['batch' => $batch->fresh(), 'skipped' => $skipped];
        });
    }

    /**
     * Does this batch account for the whole run?
     *
     * The one check worth making before a payroll leaves the building. A difference is not
     * necessarily an error — a manually-paid payslip or a zero-net period both produce one
     * legitimately — so this reports rather than refuses, and names the gap in pesos.
     *
     * @return array{balanced: bool, batch_total: float, run_total: float, difference: float, unpaid_payslips: int}
     */
    public function reconcileWithRun(PaymentBatch $batch): array
    {
        $run = $batch->payrollRun;

        $batchTotal = round((float) $batch->total_amount, 2);
        $runTotal = round((float) $run->total_net, 2);

        $unpaid = Payslip::where('payroll_run_id', $run->id)
            ->whereDoesntHave('payments')
            ->where('status', '!=', 'disbursed')
            ->count();

        return [
            'balanced' => abs($batchTotal - $runTotal) < 0.01 && $unpaid === 0,
            'batch_total' => $batchTotal,
            'run_total' => $runTotal,
            'difference' => round($batchTotal - $runTotal, 2),
            'unpaid_payslips' => $unpaid,
        ];
    }

    /**
     * The file finance uploads to the bank, and the record that it was sent.
     *
     * FORMAT. This is a documented GENERIC CSV, not any particular bank's layout. The audit asks
     * for "a bank-file export per supported format" and no bank was named anywhere in the project
     * docs — and a bank file in the wrong layout is not a partial success, it is a rejected upload.
     * Rather than guess at BDO's or BPI's column order, this emits an explicit, self-describing
     * format that a person can map, and the method is written so a real format drops in beside it.
     * Ask finance which bank(s) before adding one.
     *
     * EXPORTING IS A ONE-WAY DOOR. Once the file has been downloaded the company must assume it
     * was uploaded, so `exported_at` is stamped here and the batch can no longer be rebuilt.
     */
    public function bankFile(PaymentBatch $batch, User $actor): string
    {
        $batch->loadMissing(['payments.employee', 'payrollRun']);

        $rows = [[
            'Employee No', 'Employee Name', 'Bank', 'Account Number', 'Account Name',
            'Account Type', 'Amount', 'Currency', 'Reference', 'Pay Period',
        ]];

        // Formatted, not concatenated. `cycle_start`/`cycle_end` are date-cast, so string
        // interpolation yields "2026-07-16 00:00:00" — a midnight nobody asked about, in a column
        // a human at the bank reads.
        $period = $batch->payrollRun->cycle_start?->format('Y-m-d')
            . ' to ' . $batch->payrollRun->cycle_end?->format('Y-m-d');

        foreach ($batch->payments as $payment) {
            $employee = $payment->employee;

            $rows[] = [
                $employee?->employee_no,
                trim(($employee?->user?->name ?? '')) ?: ($employee?->employee_no ?? ''),
                $payment->bank_name,
                // The FULL account number — this is the one artifact that legitimately needs it,
                // because it is the instruction to the bank. Everything shown on screen is masked.
                $payment->bank_account,
                $payment->bank_account_name,
                $payment->bank_account_type,
                number_format((float) $payment->amount, 2, '.', ''),
                'PHP',
                $batch->reference,
                $period,
            ];
        }

        if ($batch->exported_at === null) {
            $batch->update([
                'exported_at' => now(),
                'exported_by' => $actor->id,
                'status' => 'exported',
            ]);

            $this->audit->log(
                'exported', 'PaymentBatch',
                'Generated the bank file for ' . $batch->reference . ' — ' . $batch->payment_count
                    . ' transfer(s), ₱' . number_format((float) $batch->total_amount, 2),
                $batch,
            );
        }

        return CsvWriter::render($rows);
    }

    /**
     * Why there was nothing left to pay — accurately, when several reasons apply at once.
     *
     * Written after getting this wrong. A run whose payable employees were already batched and
     * whose remainder had no bank details reported "No one on this run has bank details on file",
     * which is simply false: two people had them and had already been paid. A refusal that
     * misdescribes its own cause sends HR to the wrong screen, and the employee list in the
     * message makes it look authoritative while it does it.
     *
     * @param  array<string, int>  $skipped
     * @param  array<int, string>  $missing
     */
    private function nothingToPayMessage(array $skipped, array $missing): string
    {
        $reasons = [];

        if ($skipped['already_batched'] > 0) {
            $reasons[] = $skipped['already_batched'] . ' already in a payment batch';
        }

        if ($skipped['already_disbursed'] > 0) {
            $reasons[] = $skipped['already_disbursed'] . ' already marked paid';
        }

        if ($skipped['zero_net'] > 0) {
            $reasons[] = $skipped['zero_net'] . ' with a net of zero';
        }

        if ($skipped['missing_bank_details'] > 0) {
            $names = implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ', …' : '');
            $reasons[] = $skipped['missing_bank_details'] . ' with no bank details on file (' . $names . ')';
        }

        $message = 'There is nothing left to pay on this run: ' . implode('; ', $reasons) . '.';

        // Only offer the way forward that actually applies. Suggesting cash to someone whose real
        // problem is a duplicate batch is noise.
        if ($skipped['missing_bank_details'] > 0) {
            $message .= ' Add the missing account numbers, or pay those employees by cash or check.';
        }

        return $message;
    }

    /**
     * Sequential per run: PB-{run id}-0001. Unique, sortable, and quotable to the bank.
     *
     * Derived from the existing count rather than a stored counter — batches per run are a handful
     * at most, and a counter column would be one more thing to keep in step.
     */
    private function nextReference(PayrollRun $run): string
    {
        $n = PaymentBatch::withTrashed()->where('payroll_run_id', $run->id)->count() + 1;

        return sprintf('PB-%d-%04d', $run->id, $n);
    }
}
