<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\BenefitProgram;
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
            ->with(['payments', 'policy'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * HR issues a loan — Slice B15, path one.
     *
     * IT DOES NOT START `active` ANY MORE. A deduction for a debt owed to the employer needs the
     * employee's written consent (Labor Code Art. 113), so an issued loan waits at
     * `pending_authorization` until they give it. `collectForPeriod()` only reads `active`, so
     * nothing is collected in the meantime — by construction, not by a reminder.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Employee $employee, array $data, User $actor): EmployeeLoan
    {
        $policy = $this->policyFor($data['benefit_program_id'] ?? null);
        $this->assertWithinPolicy($policy, $employee, $data);

        $loan = EmployeeLoan::create([
            'employee_id' => $employee->id,
            'benefit_program_id' => $policy?->id,
            'type' => $data['type'] ?? 'loan',
            'reference' => $data['reference'] ?? null,
            'principal' => $data['principal'],
            'per_period_amount' => $data['per_period_amount'],
            'start_date' => $data['start_date'],
            // Issued by HR, so the decision is already taken — what is missing is the employee's.
            'status' => 'pending_authorization',
            'approved_by' => $actor->id,
            'approved_at' => now(),
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

    /**
     * The employee asks to borrow — Slice B15, path two.
     *
     * Nothing is decided and nothing is collectible: it sits at `requested` until HR approves, and
     * even then it still needs the employee's authorization. The rule on the policy decides
     * whether they may ask at all, which is the same rule an allowance uses to decide who it
     * covers — one rule engine, not two.
     *
     * @param  array<string, mixed>  $data
     */
    public function request(Employee $employee, array $data): EmployeeLoan
    {
        $policy = $this->policyFor($data['benefit_program_id'] ?? null);
        $this->assertWithinPolicy($policy, $employee, $data);

        $loan = EmployeeLoan::create([
            'employee_id' => $employee->id,
            'benefit_program_id' => $policy?->id,
            'type' => $data['type'] ?? 'loan',
            'principal' => $data['principal'],
            'per_period_amount' => $data['per_period_amount'],
            'start_date' => $data['start_date'],
            'status' => 'requested',
            'requested_at' => now(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $employee->user_id,
        ]);

        $employee->loadMissing('user');
        $this->audit->log(
            'requested', 'Loans',
            ($employee->user?->name ?? "Employee {$employee->id}") . ' requested ₱'
                . number_format((float) $loan->principal, 2)
                . ($policy ? " under '{$policy->name}'" : '')
                . ' at ₱' . number_format((float) $loan->per_period_amount, 2) . '/period',
            $loan,
        );

        return $loan;
    }

    /** HR approves a request. Still not collectible — the employee has to authorize it. */
    public function approve(EmployeeLoan $loan, User $actor, ?string $note = null): EmployeeLoan
    {
        if ($loan->status !== 'requested') {
            throw ValidationException::withMessages([
                'status' => ["Only a requested loan can be approved; this one is {$loan->status}."],
            ]);
        }

        $loan->update([
            'status' => 'pending_authorization',
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'decision_note' => $note,
        ]);

        $this->audit->log(
            'approved', 'Loans',
            "Approved loan #{$loan->id} — ₱" . number_format((float) $loan->principal, 2)
                . '. Nothing is deducted until the employee authorizes it.',
            $loan,
        );

        return $loan->fresh('payments');
    }

    public function reject(EmployeeLoan $loan, User $actor, string $reason): EmployeeLoan
    {
        if ($loan->status !== 'requested') {
            throw ValidationException::withMessages([
                'status' => ["Only a requested loan can be rejected; this one is {$loan->status}."],
            ]);
        }

        $loan->update([
            'status' => 'rejected',
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'decision_note' => $reason,
        ]);

        $this->audit->log('rejected', 'Loans', "Rejected loan #{$loan->id} — {$reason}", $loan);

        return $loan->fresh('payments');
    }

    /**
     * The employee authorizes the deduction — the gate everything else exists to reach.
     *
     * THE EMPLOYEE DOES THIS, NOT HR. Art. 113 wants the worker's own consent; a record HR could
     * create on their behalf would be worth nothing. The caller passes the authenticated user and
     * this refuses anyone but the borrower.
     */
    public function authorize(EmployeeLoan $loan, User $actor): EmployeeLoan
    {
        if ($loan->status !== 'pending_authorization') {
            throw ValidationException::withMessages([
                'status' => ["This loan is {$loan->status} — there is nothing to authorize."],
            ]);
        }

        $loan->loadMissing('employee');

        if ($loan->employee?->user_id !== $actor->id) {
            throw ValidationException::withMessages([
                'authorization' => ['Only the employee whose pay is deducted can authorize it.'],
            ]);
        }

        $loan->update([
            'status' => 'active',
            'authorized_at' => now(),
            'authorized_by' => $actor->id,
        ]);

        $this->audit->log(
            'authorized', 'Loans',
            "{$actor->name} authorized ₱" . number_format((float) $loan->per_period_amount, 2)
                . '/period to be deducted from their pay for loan #' . $loan->id
                . ' (₱' . number_format((float) $loan->principal, 2) . ')',
            $loan,
        );

        return $loan->fresh('payments');
    }

    /** The policy a loan is issued under, if any. */
    private function policyFor(mixed $programId): ?BenefitProgram
    {
        if ($programId === null) {
            return null;
        }

        $policy = BenefitProgram::find($programId);

        if ($policy === null || $policy->kind !== 'loan_policy') {
            throw ValidationException::withMessages([
                'benefit_program_id' => ['That is not a loan policy.'],
            ]);
        }

        return $policy;
    }

    /**
     * A loan must fit the product it is issued under, or the product is decoration.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertWithinPolicy(?BenefitProgram $policy, Employee $employee, array $data): void
    {
        if ($policy === null) {
            return;
        }

        $errors = [];

        if (! app(BenefitProgramService::class)->covers($policy, $employee)) {
            $errors['benefit_program_id'] = ["{$policy->name} is not available to this employee."];
        }

        $principal = (float) ($data['principal'] ?? 0);
        if ($policy->loan_max_principal !== null && $principal > (float) $policy->loan_max_principal) {
            $errors['principal'] = [
                '₱' . number_format($principal, 2) . ' is over the ₱'
                    . number_format((float) $policy->loan_max_principal, 2) . " maximum for {$policy->name}.",
            ];
        }

        // Term is expressed as periods, so it is checked as periods: principal ÷ instalment.
        $perPeriod = (float) ($data['per_period_amount'] ?? 0);
        if ($policy->loan_term_periods !== null && $perPeriod > 0) {
            $periods = (int) ceil($principal / $perPeriod);
            if ($periods > $policy->loan_term_periods) {
                $errors['per_period_amount'] = [
                    "That instalment repays over {$periods} periods; {$policy->name} allows "
                        . $policy->loan_term_periods . '.',
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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
        // FIN-003(b) — `settled` is included, not just `active`, and that is load-bearing.
        //
        // A loan that THIS run's previous compute settled is no longer `active`. Filtering to
        // `active` alone excluded it from `$loans`, so the cleanup immediately below deleted the
        // very payment that had settled it — while the loan stayed `settled`. The balance came
        // back (`outstanding() > 0`) but `isCollectible()` was false, so payroll could never
        // collect it again, and the re-run's payslip carried no loan deduction so net pay rose.
        //
        // Re-visiting a settled loan is safe: the loop below recomputes `$outstanding` from the
        // payments made OUTSIDE this run, so one settled by an EARLIER run reads zero outstanding
        // and is skipped, while one settled by this run is correctly re-collected and re-settled.
        $loans = EmployeeLoan::where('employee_id', $employee->id)
            ->whereIn('status', ['active', 'settled'])
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

    /**
     * Undo the instalments a payslip collected, because that payslip is being un-paid.
     *
     * FIN-003(a) — WHY THIS EXISTS. `collectForPeriod()` writes the ledger at COMPUTE time, so an
     * employee's balance drops as soon as payroll runs, before anyone asserts the money moved.
     * That is the deliberate behaviour (HR sees the deduction the moment the period is priced),
     * but it was only half a story: `reopenPayslip()` reversed the payslip and its reimbursements
     * and left the loan alone. Reversing a payment therefore wrote off real debt against money the
     * company had just decided not to pay, and a loan the payslip had settled stayed `settled`.
     *
     * Slice 12 established exactly this invariant for reimbursements. This is the loan half.
     *
     * Scoped by (run, employee) rather than by `payslip_id`, because rows written before the
     * payslip existed carry a null `payslip_id` — the pair is what actually identifies "the
     * instalments this payslip collected".
     *
     * @return int how many payment rows were reversed
     */
    public function reverseForPayslip(Payslip $payslip): int
    {
        $payments = LoanPayment::where('payroll_run_id', $payslip->payroll_run_id)
            ->whereHas('loan', fn ($q) => $q->where('employee_id', $payslip->employee_id))
            ->with('loan')
            ->get();

        if ($payments->isEmpty()) {
            return 0;
        }

        $loans = $payments->pluck('loan')->filter()->unique('id');
        LoanPayment::whereIn('id', $payments->pluck('id'))->delete();

        // A loan this payslip had settled owes money again, so it must become collectible again.
        // Anything settled by a DIFFERENT run still reads zero outstanding and is left alone.
        foreach ($loans as $loan) {
            if ($loan->status === 'settled' && $loan->fresh()->outstanding() > 0) {
                $loan->update(['status' => 'active']);
            }
        }

        return $payments->count();
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
