<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Payment;
use App\Modules\Hr\Models\RetroFlag;
use App\Modules\Hr\Models\PaymentBatch;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Turning a completed payroll run into money that actually leaves the company. */
class PaymentService
{
    public function __construct(
        private AuditService $audit,
        // Disbursing a payslip is not a column write — it also settles the reimbursements the
        // payslip carried, and reversing one puts back the loan instalments it collected. Both
        // live in PayrollService, and reconciliation has to go through them.
        private PayrollService $payroll,
        //  / — when a bounced transfer's payslip CANNOT be reopened (locked run), the
        // reversal has to surface somewhere a person actually looks. An audit-log line is a record,
        // not a task.
        private RetroFlagService $retro,
    ) {}

    /**
     * Build the batch of payments for a completed run.
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

    /** The file finance uploads to the bank, and the record that it was sent. */
    public function bankFile(PaymentBatch $batch, User $actor): string
    {
        $batch->loadMissing(['payments.employee', 'payrollRun']);

        $rows = [[
            // `Payment Ref` is first because it is the column reconciliation matches on. A bank
            // that echoes a reference field back gives an exact match per transfer; without it the
            // import has to fall back to guessing from employee number and amount.
            'Payment Ref', 'Employee No', 'Employee Name', 'Bank', 'Account Number', 'Account Name',
            'Account Type', 'Amount', 'Currency', 'Batch Reference', 'Pay Period',
        ]];

        // Formatted, not concatenated. `cycle_start`/`cycle_end` are date-cast, so string
        // interpolation yields "2026-07-16 00:00:00" — a midnight nobody asked about, in a column
        // a human at the bank reads.
        $period = $batch->payrollRun->cycle_start?->format('Y-m-d')
            . ' to ' . $batch->payrollRun->cycle_end?->format('Y-m-d');

        foreach ($batch->payments as $payment) {
            $employee = $payment->employee;

            $rows[] = [
                $this->paymentRef($payment),
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
     * Stable per-transfer identifier, e.g. `PB-7-0001-42`.
     *
     * Composed rather than stored: it is derived from two immutable values, and a stored copy
     * would be one more thing that can disagree with them.
     */
    public function paymentRef(Payment $payment): string
    {
        return ($payment->batch?->reference ?? 'PB') . '-' . $payment->id;
    }

    /** Record that one transfer actually cleared — the moment "paid" stops being an assertion. */
    public function settle(Payment $payment, ?string $reference, ?string $settledAt, User $actor): Payment
    {
        if ($payment->status === 'settled') {
            throw ValidationException::withMessages([
                'status' => 'This transfer is already settled.',
            ]);
        }

        return DB::transaction(function () use ($payment, $reference, $settledAt, $actor) {
            $payment->update([
                'status' => 'settled',
                'reference' => $reference,
                'settled_at' => $settledAt ? Carbon::parse($settledAt) : now(),
                'failure_reason' => null,
            ]);

            $payslip = $payment->payslip;

            // Already disbursed by the manual path, or by an earlier payment on the same payslip
            // after a re-issue. Either way there is nothing left to flip.
            if ($payslip && $payslip->status !== 'disbursed') {
                // The actor is the person reconciling, and the key is the payment
                // itself: re-importing the same bank file settles the same payment again, which
                // must be a duplicate rather than a second disbursement.
                $this->payroll->markDone($payslip, $actor, "payment:{$payment->id}");
            }

            $this->refreshBatchStatus($payment->batch);

            $this->audit->log(
                'updated', 'Payment',
                'Settled ' . $this->paymentRef($payment) . ' — ₱'
                    . number_format((float) $payment->amount, 2)
                    . ($reference ? ' (bank ref ' . $reference . ')' : ''),
                $payment,
            );

            return $payment->fresh();
        });
    }

    /** Record that a transfer did NOT arrive. */
    public function fail(Payment $payment, string $status, string $reason, User $actor): Payment
    {
        if (! in_array($status, Payment::UNSUCCESSFUL, true)) {
            throw ValidationException::withMessages([
                'status' => 'A failed transfer is either `failed` or `returned`.',
            ]);
        }

        return DB::transaction(function () use ($payment, $status, $reason, $actor) {
            $payment->update([
                'status' => $status,
                'failure_reason' => $reason,
                'settled_at' => null,
            ]);

            $payslip = $payment->payslip;
            $reopened = false;

            if ($payslip && $payslip->status === 'disbursed') {
                try {
                    $this->payroll->reopenPayslip($payslip, 'Bank transfer ' . $status . ': ' . $reason);
                    $reopened = true;
                } catch (ValidationException) {
                    // LOCKED RUN. `reopenPayslip()` is what puts back the loan instalments the payslip
                    // collected (`LoanService::reverseForPayslip()`), so when it refuses, TWO things are
                    // now wrong and neither is visible: the payslip still reads `disbursed`, and the
                    // employee's loan balance is still reduced by an instalment that came back with the
                    // transfer.
                    $this->retro->flagIfSettled(
                        $payment,
                        $payment->employee_id,
                        $payslip->payrollRun?->cycle_start?->toDateString() ?? now()->toDateString(),
                        $payslip->payrollRun?->cycle_end?->toDateString(),
                        RetroFlag::OWED,
                        'Bank transfer ' . $status . ' (' . $reason . ') but the run is locked, so the '
                            . 'payslip could not be reversed — unlock it, reverse the payslip, and any '
                            . 'loan instalment it collected will be put back',
                    );
                }
            }

            $this->refreshBatchStatus($payment->batch);

            $this->audit->log(
                'updated', 'Payment',
                ucfirst($status) . ' ' . $this->paymentRef($payment) . ' — ₱'
                    . number_format((float) $payment->amount, 2) . ': ' . $reason
                    . ($payslip && ! $reopened && $payslip->status === 'disbursed'
                        ? ' — PAYSLIP STILL MARKED DISBURSED: its run is locked, unlock it and reverse the payslip by hand'
                        : ''),
                $payment,
            );

            return $payment->fresh();
        });
    }

    /** Keep the batch's headline status honest about its payments. */
    public function refreshBatchStatus(?PaymentBatch $batch): void
    {
        if (! $batch) {
            return;
        }

        $counts = $batch->payments()
            ->selectRaw('status, COUNT(*) c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $total = (int) $counts->sum();
        $settled = (int) ($counts['settled'] ?? 0);
        $unsuccessful = (int) ($counts['failed'] ?? 0) + (int) ($counts['returned'] ?? 0);

        $status = match (true) {
            $total > 0 && $settled === $total => 'settled',
            $settled > 0 || $unsuccessful > 0 => 'partial',
            $batch->exported_at !== null => 'exported',
            default => 'pending',
        };

        $batch->update(['status' => $status]);
    }

    /**
     * Apply the bank's confirmation file to a batch.
     *
     * @return array{applied: bool, settled: int, failed: int, unmatched: array<int, string>, rows: int}
     */
    public function importReconciliation(PaymentBatch $batch, string $csv, bool $apply, User $actor): array
    {
        $rows = $this->parseCsv($csv);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'That file has no rows this import can read. It needs a header line with at least a status column and either `Payment Ref` or `Employee No`.',
            ]);
        }

        $payments = $batch->payments()->with('employee')->get();
        $byRef = $payments->keyBy(fn (Payment $p) => strtolower($this->paymentRef($p)));
        $byEmployee = $payments->keyBy(fn (Payment $p) => strtolower((string) $p->employee?->employee_no));

        $settled = 0;
        $failed = 0;
        $unmatched = [];

        foreach ($rows as $i => $row) {
            $ref = strtolower(trim((string) ($row['payment ref'] ?? '')));
            $employeeNo = strtolower(trim((string) ($row['employee no'] ?? '')));

            $payment = ($ref !== '' ? $byRef->get($ref) : null)
                ?? ($employeeNo !== '' ? $byEmployee->get($employeeNo) : null);

            if (! $payment) {
                $unmatched[] = 'row ' . ($i + 2) . ': ' . ($ref ?: $employeeNo ?: '(no identifier)');

                continue;
            }

            $outcome = $this->normaliseStatus((string) ($row['status'] ?? ''));

            if ($outcome === null) {
                $unmatched[] = 'row ' . ($i + 2) . ': unrecognised status "' . ($row['status'] ?? '') . '"';

                continue;
            }

            if ($outcome === 'settled') {
                $settled++;

                if ($apply && $payment->status !== 'settled') {
                    $this->settle(
                        $payment,
                        trim((string) ($row['bank reference'] ?? $row['reference'] ?? '')) ?: null,
                        trim((string) ($row['settled date'] ?? $row['date'] ?? '')) ?: null,
                        $actor,
                    );
                }

                continue;
            }

            $failed++;

            if ($apply && $payment->status !== $outcome) {
                $this->fail(
                    $payment,
                    $outcome,
                    trim((string) ($row['failure reason'] ?? $row['reason'] ?? '')) ?: 'Reported by the bank as ' . $outcome,
                    $actor,
                );
            }
        }

        if ($apply) {
            $this->audit->log(
                'updated', 'PaymentBatch',
                'Reconciled ' . $batch->reference . ' — ' . $settled . ' settled, ' . $failed
                    . ' failed, ' . count($unmatched) . ' unmatched',
                $batch,
            );
        }

        return [
            'applied' => $apply,
            'settled' => $settled,
            'failed' => $failed,
            'unmatched' => $unmatched,
            'rows' => count($rows),
        ];
    }

    /**
     * Read a CSV into rows keyed by lower-cased header.
     *
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        // Strip the UTF-8 BOM Excel writes back out, which would otherwise become part of the
        // first header's name and stop `payment ref` ever matching.
        fwrite($handle, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
        rewind($handle);

        $header = fgetcsv($handle, escape: '');

        if ($header === false) {
            return [];
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $rows = [];

        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }

            $rows[] = array_combine(
                $header,
                array_pad(array_slice($line, 0, count($header)), count($header), ''),
            );
        }

        fclose($handle);

        return $rows;
    }

    /** Map whatever the bank calls it onto our three outcomes. */
    private function normaliseStatus(string $raw): ?string
    {
        $value = strtolower(trim($raw));

        return match (true) {
            in_array($value, ['settled', 'paid', 'success', 'successful', 'completed', 'ok', 'posted'], true) => 'settled',
            in_array($value, ['returned', 'reversed', 'refunded'], true) => 'returned',
            in_array($value, ['failed', 'rejected', 'declined', 'error', 'unpaid'], true) => 'failed',
            default => null,
        };
    }

    /**
     * Why there was nothing left to pay — accurately, when several reasons apply at once.
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
