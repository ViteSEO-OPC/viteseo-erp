<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeLoan;
use App\Modules\Hr\Models\LoanPayment;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Loans and cash advances repaid through payroll (Slice 11b).
 *
 * RE-RUN SAFETY IS THE WHOLE DESIGN. A loan has no `balance` column; the balance is
 * `principal − SUM(payments)`, and payments are unique per (loan, payroll run) and written with
 * updateOrCreate. So recomputing a cycle REPLACES that period's payment rather than adding a
 * second one, and the balance can never drift no matter how many times payroll is re-run — the
 * failure mode Slices 5 and 12 were about.
 *
 * SHORTFALL (decision 2026-07-28): if the payslip can't cover the full instalment we collect
 * what it can and leave the rest on the balance, so net pay never goes negative and the loan
 * simply settles a period later.
 */
class LoanService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return Collection<int, EmployeeLoan> */
    public function forEmployee(Employee $employee): Collection
    {
        return EmployeeLoan::where('employee_id', $employee->id)
            ->with('payments')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(Employee $employee, array $data, User $actor): EmployeeLoan
    {
        $loan = EmployeeLoan::create([
            'employee_id' => $employee->id,
            'type' => $data['type'] ?? 'loan',
            'reference' => $data['reference'] ?? null,
            'principal' => $data['principal'],
            'per_period_amount' => $data['per_period_amount'],
            'start_date' => $data['start_date'],
            'status' => 'active',
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor->id,
        ]);

        $employee->loadMissing('user');
        $name = $employee->user?->name ?? $employee->name;
        $this->audit->log(
            'created', 'Loans',
            "Recorded a {$loan->type} for {$name} — ₱" . number_format((float) $loan->principal, 2)
                . ' at ₱' . number_format((float) $loan->per_period_amount, 2) . '/period',
            $loan,
        );

        return $loan;
    }

    /** @param array<string, mixed> $data */
    public function update(EmployeeLoan $loan, array $data, User $actor): EmployeeLoan
    {
        // The principal can't drop below what has already been collected — that would imply
        // the company owes the employee money back, which is not something payroll can express.
        if (isset($data['principal']) && (float) $data['principal'] < $loan->paid()) {
            throw ValidationException::withMessages([
                'principal' => ['The principal cannot be less than the ₱' . number_format($loan->paid(), 2) . ' already repaid.'],
            ]);
        }

        $loan->update(array_intersect_key($data, array_flip([
            'type', 'reference', 'principal', 'per_period_amount', 'start_date', 'status', 'notes',
        ])));

        $this->audit->log('updated', 'Loans', "Updated loan #{$loan->id}", $loan);

        return $loan->fresh('payments');
    }

    public function cancel(EmployeeLoan $loan, User $actor, string $reason): EmployeeLoan
    {
        if ($loan->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => ['This loan is already cancelled.']]);
        }

        $loan->update(['status' => 'cancelled', 'notes' => trim(($loan->notes ? $loan->notes . "\n" : '') . "Cancelled: {$reason}")]);
        $this->audit->log('cancelled', 'Loans', "Cancelled loan #{$loan->id} — {$reason}", $loan);

        return $loan;
    }

    /**
     * Undo a cancellation — the loan goes back to active and payroll resumes collecting it
     * from the next run. Instalments already collected are untouched, so the balance simply
     * picks up where it left off. A loan payroll marked `settled` is left alone: its balance
     * is zero, so re-activating it would collect nothing.
     */
    public function reopen(EmployeeLoan $loan, ?string $reason = null): EmployeeLoan
    {
        if ($loan->status !== 'cancelled') {
            throw ValidationException::withMessages([
                'status' => ["Only a cancelled loan can be reopened; this one is {$loan->status}."],
            ]);
        }

        $loan->update(['status' => 'active']);
        $this->audit->log(
            'reopened',
            'Loans',
            "Reopened loan #{$loan->id} — collection resumes" . ($reason ? " — {$reason}" : ''),
            $loan,
        );

        return $loan->fresh('payments');
    }

    /**
     * Collect this period's instalments for one employee and record them against the run.
     *
     * `availableNet` is what the payslip can still afford after every other deduction. Each
     * loan takes the lesser of its instalment, its remaining balance, and what's left — so a
     * thin payslip pays what it can and the rest carries.
     *
     * A loan whose balance reaches zero is marked `settled` here, so it stops being collected
     * without HR having to notice.
     *
     * @return array{total: float, lines: array<int, array{name: string, amount: float}>}
     */
    public function collectForPeriod(
        Employee $employee,
        PayrollRun $run,
        string $cycleEnd,
        float $availableNet,
        ?Payslip $payslip = null,
    ): array {
        $loans = EmployeeLoan::where('employee_id', $employee->id)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $cycleEnd)
            ->with('payments')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        // A loan that is no longer collectible — cancelled, put on hold, or back-dated out of
        // this cycle — is never visited by the loop below, so a row it left behind on an
        // earlier compute would survive and keep its balance wrongly reduced. Clear those first.
        LoanPayment::where('payroll_run_id', $run->id)
            ->whereNotIn('loan_id', $loans->pluck('id'))
            ->whereHas('loan', fn ($q) => $q->where('employee_id', $employee->id))
            ->delete();

        $lines = [];
        $total = 0.0;
        $remaining = max(0.0, $availableNet);

        foreach ($loans as $loan) {
            // A re-run must not read its OWN previous payment as already-paid, or the balance
            // would look smaller every time. Exclude this run's row before deciding the amount.
            $paidElsewhere = round((float) $loan->payments()
                ->where('payroll_run_id', '!=', $run->id)
                ->sum('amount'), 2);
            $outstanding = round(max(0, (float) $loan->principal - $paidElsewhere), 2);

            if ($outstanding <= 0) {
                $loan->payments()->where('payroll_run_id', $run->id)->delete();
                $loan->update(['status' => 'settled']);

                continue;
            }

            $amount = round(min((float) $loan->per_period_amount, $outstanding, $remaining), 2);

            if ($amount <= 0) {
                // Nothing left in the payslip — drop any stale row from a previous compute so
                // the ledger reflects what this run actually collected.
                $loan->payments()->where('payroll_run_id', $run->id)->delete();

                continue;
            }

            LoanPayment::updateOrCreate(
                ['loan_id' => $loan->id, 'payroll_run_id' => $run->id],
                ['payslip_id' => $payslip?->id, 'amount' => $amount],
            );

            $label = $loan->reference
                ? "{$this->typeLabel($loan->type)} ({$loan->reference})"
                : $this->typeLabel($loan->type);

            $lines[] = ['name' => $label, 'amount' => $amount];
            $total += $amount;
            $remaining = round($remaining - $amount, 2);

            // Fully repaid once this period's payment lands.
            if (round($paidElsewhere + $amount, 2) >= (float) $loan->principal) {
                $loan->update(['status' => 'settled']);
            }
        }

        return ['total' => round($total, 2), 'lines' => $lines];
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'cash_advance' => 'Cash advance',
            'other' => 'Other deduction',
            default => 'Loan',
        };
    }
}
