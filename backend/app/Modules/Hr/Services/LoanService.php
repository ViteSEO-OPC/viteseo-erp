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

/** Loans and cash advances repaid through payroll. */
class LoanService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Loans across ALL employees, optionally by status — the queue HR decides from.
     *
     * @return Collection<int, EmployeeLoan>
     */
    public function all(?string $status = null): Collection
    {
        return EmployeeLoan::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->with(['employee.user', 'policy'])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get();
    }

    public function forEmployee(Employee $employee): Collection
    {
        return EmployeeLoan::where('employee_id', $employee->id)
            ->with(['payments', 'policy'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * HR issues a loan, path one.
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
            // The terms. Omitted means zero, which is an interest-free advance rather than
            // a missing configuration.
            'interest_rate' => $data['interest_rate'] ?? 0,
            'interest_period' => $data['interest_period'] ?? 'annual',
            'fees' => $data['fees'] ?? 0,
            'priority' => $data['priority'] ?? 100,
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
     * The employee asks to borrow, path two.
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
            // The terms. Omitted means zero, which is an interest-free advance rather than
            // a missing configuration.
            'interest_rate' => $data['interest_rate'] ?? 0,
            'interest_period' => $data['interest_period'] ?? 'annual',
            'fees' => $data['fees'] ?? 0,
            'priority' => $data['priority'] ?? 100,
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

    /** The employee authorizes the deduction — the gate everything else exists to reach. */
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

    /**
     * Refuse to rewrite a repayment schedule payroll has already paid against.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertScheduleEditable(EmployeeLoan $loan, array $data): void
    {
        $touchesSchedule = array_intersect_key($data, array_flip([
            'principal', 'per_period_amount', 'start_date',
            // The rate, its period and the fee are part of the schedule too. Changing the
            // rate after a disbursed period would reprice interest that has already been collected
            // and consented to, which is the same wrong as moving the instalment.
            'interest_rate', 'interest_period', 'fees',
        ]));

        if ($touchesSchedule === []) {
            return;
        }

        $settled = LoanPayment::where('loan_id', $loan->id)
            ->where(fn ($q) => $q
                ->whereHas('payslip', fn ($p) => $p->where('status', 'disbursed'))
                ->orWhereHas('payrollRun', fn ($r) => $r->whereNotNull('locked_at')))
            ->with('payrollRun')
            ->first();

        if ($settled === null) {
            return;
        }

        $period = $settled->payrollRun
            ? $settled->payrollRun->cycle_start->format('M j') . '–' . $settled->payrollRun->cycle_end->format('M j, Y')
            : 'a settled period';

        throw ValidationException::withMessages([
            'principal' => [
                "This loan has already been collected from on the {$period} payroll, which has been "
                    . 'paid out. Changing the amount or start date now would leave that payslip '
                    . 'disagreeing with the loan. Reverse the payslip first, or cancel this loan and '
                    . 'issue a corrected one.',
            ],
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(EmployeeLoan $loan, array $data, User $actor): EmployeeLoan
    {
        // The principal can't drop below what has already been collected — that would imply
        // the company owes the employee money back, which is not something payroll can express.
        // Compared against PRINCIPAL repaid, not the total collected. `paid()` now includes
        // interest and fees, so using it here would refuse a legitimate correction on a loan whose
        // interest happened to exceed the reduction being made.
        if (isset($data['principal']) && (float) $data['principal'] < $loan->paidPrincipal()) {
            throw ValidationException::withMessages([
                'principal' => ['The principal cannot be less than the ₱' . number_format($loan->paidPrincipal(), 2) . ' already repaid.'],
            ]);
        }

        // The product ceiling holds for the LIFE of the loan, not just at issue.
        $loan->loadMissing('employee');
        if ($loan->employee !== null) {
            $this->assertWithinPolicy(
                $this->policyFor($loan->benefit_program_id),
                $loan->employee,
                [
                    'principal' => $data['principal'] ?? $loan->principal,
                    'per_period_amount' => $data['per_period_amount'] ?? $loan->per_period_amount,
                ],
            );
        }

        // The repayment schedule is frozen once payroll has paid against it.
        $this->assertScheduleEditable($loan, $data);

        // `status` IS NOT EDITABLE HERE, and must never be added back.
        $loan->update(array_intersect_key($data, array_flip([
            'type', 'reference', 'principal', 'per_period_amount', 'start_date', 'notes',
            // The terms of the loan. Editable like the principal and instalment are, and
            // frozen by the same `assertScheduleEditable()` guard once payroll has collected against
            // them: changing the rate after the fact would reprice instalments already paid.
            'interest_rate', 'interest_period', 'fees', 'priority',
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

        // Consent that was never given cannot be resumed.
        $restored = $loan->authorized_at ? 'active' : 'pending_authorization';

        $loan->update(['status' => $restored]);
        $this->audit->log(
            'reopened',
            'Loans',
            "Reopened loan #{$loan->id} — " . ($restored === 'active'
                ? 'collection resumes'
                : 'awaiting the employee’s authorization')
                . ($reason ? " — {$reason}" : ''),
            $loan,
        );

        return $loan->fresh('payments');
    }

    /**
     * Collect this period's instalments for one employee and record them against the run.
     *
     * @return array{total: float, lines: array<int, array{name: string, amount: float}>}
     */
    public function collectForPeriod(
        Employee $employee,
        PayrollRun $run,
        string $cycleEnd,
        float $availableNet,
        ?Payslip $payslip = null,
        // How many paydays a year, so a diminishing-balance rate can be split per period.
        // Defaults to 0, which yields a zero rate: an omitted argument charges no interest rather
        // than guessing a frequency and charging the wrong amount.
        int $periodsPerYear = 0,
    ): array {
        // (b) — `settled` is included, not just `active`, and that is load-bearing.
        $loans = EmployeeLoan::where('employee_id', $employee->id)
            ->whereIn('status', ['active', 'settled'])
            ->whereDate('start_date', '<=', $cycleEnd)
            ->with('payments')
            // Priority first (lower runs first), so when net pay cannot cover everything HR
            // decides which obligation gets the money rather than the query order deciding for them.
            // Then oldest-first within a priority, which is the fair tie-break.
            ->orderBy('priority')
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
        $interestTotal = 0.0;
        $remaining = max(0.0, $availableNet);

        foreach ($loans as $loan) {
            // A re-run must not read its OWN previous payment as already-paid, or the balance
            // would look smaller every time. Exclude this run's row before deciding the amount.
            // PRINCIPAL only. Interest is charged on this balance, so counting it here
            // would let a loan repay itself out of its own interest.
            $principalPaidElsewhere = round((float) $loan->payments()
                ->where('payroll_run_id', '!=', $run->id)
                ->sum('principal_amount'), 2);
            $feesPaidElsewhere = round((float) $loan->payments()
                ->where('payroll_run_id', '!=', $run->id)
                ->sum('fee_amount'), 2);

            $outstanding = round(max(0, (float) $loan->principal - $principalPaidElsewhere), 2);
            $feeDue = round(max(0, (float) $loan->fees - $feesPaidElsewhere), 2);

            if ($outstanding <= 0 && $feeDue <= 0) {
                $loan->payments()->where('payroll_run_id', $run->id)->delete();
                $loan->update(['status' => 'settled']);

                continue;
            }

            // This period's interest, on the balance still owed — diminishing balance, so it falls as
            // the loan is repaid. Zero rate (or an omitted `$periodsPerYear`) yields zero.
            $interestDue = $loan->periodInterest($periodsPerYear, $outstanding);

            /**
             * ALLOCATION ORDER WHEN THE PAYSLIP CANNOT COVER EVERYTHING: interest, then fee, then
             * principal.
             */
            $interest = round(min($interestDue, $remaining), 2);
            $remaining = round($remaining - $interest, 2);

            $fee = round(min($feeDue, $remaining), 2);
            $remaining = round($remaining - $fee, 2);

            $principal = round(min((float) $loan->per_period_amount, $outstanding, $remaining), 2);
            $remaining = round($remaining - $principal, 2);

            $amount = round($interest + $fee + $principal, 2);

            if ($amount <= 0) {
                // Nothing left in the payslip — drop any stale row from a previous compute so
                // the ledger reflects what this run actually collected.
                $loan->payments()->where('payroll_run_id', $run->id)->delete();

                continue;
            }

            LoanPayment::updateOrCreate(
                ['loan_id' => $loan->id, 'payroll_run_id' => $run->id],
                [
                    'payslip_id' => $payslip?->id,
                    'amount' => $amount,
                    'principal_amount' => $principal,
                    'interest_amount' => $interest,
                    'fee_amount' => $fee,
                ],
            );

            $label = $loan->reference
                ? "{$this->typeLabel($loan->type)} ({$loan->reference})"
                : $this->typeLabel($loan->type);

            // Separate payslip lines, so the employee can see what reduced their balance and what was
            // the cost of borrowing. Folding them into one figure is what made the old interest field
            // misleading in the first place.
            if ($principal > 0) {
                $lines[] = ['name' => $label, 'amount' => $principal];
            }
            if ($interest > 0) {
                $lines[] = ['name' => $label . ' — interest', 'amount' => $interest];
            }
            if ($fee > 0) {
                $lines[] = ['name' => $label . ' — fee', 'amount' => $fee];
            }

            $total += $amount;
            $interestTotal += $interest;

            // Settled once the principal is repaid AND the fee is collected — an outstanding fee is
            // genuinely still owed, and the alternative is writing it off silently.
            if (round($principalPaidElsewhere + $principal, 2) >= (float) $loan->principal
                && round($feesPaidElsewhere + $fee, 2) >= (float) $loan->fees) {
                $loan->update(['status' => 'settled']);
            }
        }

        return [
            'total' => round($total, 2),
            // Reported separately so the payslip can store it as its own figure — the cost of
            // borrowing is a different thing from the repayment, and both belong on the document.
            'interest' => round($interestTotal, 2),
            'lines' => $lines,
        ];
    }

    /**
     * Undo the instalments a payslip collected, because that payslip is being un-paid.
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

    /**
     * Settle every outstanding loan on separation, from whatever the final pay can afford.
     *
     * @return float total settled
     */
    /**
     * The projected repayment schedule — what the employee is agreeing to.
     *
     * @return array{rows: list<array<string, float|int>>, total_interest: float, periods: int, truncated: bool}
     */
    public function amortisationSchedule(EmployeeLoan $loan, int $periodsPerYear): array
    {
        $rate = $loan->periodInterestRate($periodsPerYear);
        $instalment = round((float) $loan->per_period_amount, 2);
        $balance = round((float) $loan->principal, 2);
        $feeDue = round((float) $loan->fees, 2);

        $rows = [];
        $totalInterest = 0.0;
        $truncated = false;

        if ($instalment <= 0) {
            // No instalment means no schedule to project — and it is a data problem worth surfacing
            // rather than dividing by zero over.
            return ['rows' => [], 'total_interest' => 0.0, 'periods' => 0, 'truncated' => false];
        }

        for ($period = 1; $balance > 0; $period++) {
            if ($period > 600) {
                $truncated = true;
                break;
            }

            $interest = round($balance * $rate, 2);
            $principal = round(min($instalment, $balance), 2);
            $fee = $period === 1 ? $feeDue : 0.0;

            $rows[] = [
                'period' => $period,
                'opening_balance' => $balance,
                'interest' => $interest,
                'fee' => $fee,
                'principal' => $principal,
                'total' => round($interest + $fee + $principal, 2),
                'closing_balance' => round($balance - $principal, 2),
            ];

            $totalInterest = round($totalInterest + $interest, 2);
            $balance = round($balance - $principal, 2);
        }

        return [
            'rows' => $rows,
            'total_interest' => $totalInterest,
            'periods' => count($rows),
            'truncated' => $truncated,
        ];
    }

    public function settleOnSeparation(Employee $employee, PayrollRun $run, float $available): float
    {
        $loans = EmployeeLoan::where('employee_id', $employee->id)
            ->whereIn('status', ['active', 'settled'])
            ->with('payments')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        // A re-run must replace this run's rows rather than add to them — the same invariant
        // collectForPeriod() keeps, and the reason no loan has a stored balance.
        LoanPayment::where('payroll_run_id', $run->id)
            ->whereHas('loan', fn ($q) => $q->where('employee_id', $employee->id))
            ->delete();

        $remaining = round(max(0.0, $available), 2);
        $settled = 0.0;

        foreach ($loans as $loan) {
            // Principal and fees tracked separately, because `outstanding()` derives from
            // principal alone. Interest is NOT charged here: a final settlement pays off the balance
            // on the separation date, and charging a full period's interest on money being repaid the
            // same day would overcharge somebody who is leaving.
            $principalPaidElsewhere = round((float) $loan->payments()
                ->where('payroll_run_id', '!=', $run->id)
                ->sum('principal_amount'), 2);
            $feesPaidElsewhere = round((float) $loan->payments()
                ->where('payroll_run_id', '!=', $run->id)
                ->sum('fee_amount'), 2);

            $outstanding = round(max(0, (float) $loan->principal - $principalPaidElsewhere), 2);
            $feeDue = round(max(0, (float) $loan->fees - $feesPaidElsewhere), 2);

            if ($outstanding <= 0 && $feeDue <= 0) {
                $loan->update(['status' => 'settled']);

                continue;
            }

            if ($remaining <= 0) {
                continue; // nothing left to take; the balance stays outstanding and visible
            }

            // Fee before principal, matching collectForPeriod()'s order.
            $fee = round(min($feeDue, $remaining), 2);
            $remaining = round($remaining - $fee, 2);

            $principal = round(min($outstanding, $remaining), 2);
            $remaining = round($remaining - $principal, 2);

            $amount = round($fee + $principal, 2);

            if ($amount <= 0) {
                continue;
            }

            LoanPayment::updateOrCreate(
                ['loan_id' => $loan->id, 'payroll_run_id' => $run->id],
                [
                    'payslip_id' => null,
                    'amount' => $amount,
                    'principal_amount' => $principal,
                    'interest_amount' => 0,
                    'fee_amount' => $fee,
                ],
            );

            $settled = round($settled + $amount, 2);

            if (round($principalPaidElsewhere + $principal, 2) >= (float) $loan->principal
                && round($feesPaidElsewhere + $fee, 2) >= (float) $loan->fees) {
                $loan->update(['status' => 'settled']);
            }
        }

        return $settled;
    }
}
