<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\GlAccount;
use App\Modules\Hr\Models\JournalEntry;
use App\Modules\Hr\Models\JournalLine;
use App\Modules\Hr\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turning a payroll run into the double-entry the general ledger needs (FIN-043 / MF-05).
 *
 * Payroll expense, employer statutory cost and every payable a run creates lived only in `hr_payslips`.
 * Nothing produced a journal, so payroll-to-accounting reconciliation was impossible by construction.
 *
 * THE ENTRY IS COMPUTED ONCE AND FROZEN — a deliberate departure from this module's derive-don't-store
 * default (the loan ledger, deduction arrears, the leave-liability report all derive on read). A posted
 * journal is a statement about what the books said on a date. If it re-derived, correcting a payslip six
 * months later would silently rewrite last quarter's ledger and it would stop agreeing with anything
 * already filed from it. So changing a posted entry takes a reversal, not a recompute.
 *
 * DEBITS MUST EQUAL CREDITS OR IT DOES NOT POST. The identity this relies on holds for every payslip the
 * system writes — `net_pay = gross − (sss + philhealth + pagibig + withholding_tax + other_deductions)`
 * — so `DR gross + DR employer shares` equals `CR each payable + CR net pay payable`. If a run cannot
 * balance, that is a payroll bug, and posting anyway would bury it inside accounting where nobody looks
 * for payroll bugs.
 *
 * NOTHING IS SEEDED. The chart of accounts comes from the company's existing ledger; inventing
 * "5100 — Salaries Expense" would produce a journal that posts cleanly and reconciles to nothing. Until
 * HR maps every role in `GlAccount::ROLES` to a real account, `build()` refuses and says which are
 * missing — the same refusal the de-minimis ceilings and the minimum wage make.
 */
class JournalEntryService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Build (or rebuild) the DRAFT entry for a run.
     *
     * Rebuilding a draft is free — it is not on the books yet. Rebuilding a POSTED one is refused: that
     * is the duplicate-posting guard, and the way past it is a reversal.
     */
    public function build(PayrollRun $run, ?string $postingDate = null, ?User $actor = null): JournalEntry
    {
        $accounts = $this->accountsByRole();

        return DB::transaction(function () use ($run, $postingDate, $actor, $accounts) {
            $existing = JournalEntry::where('payroll_run_id', $run->id)
                ->whereIn('status', ['draft', 'posted'])
                // NOT the reversal mirrors. A mirror is itself `posted` and belongs to the same run, so
                // without this the guard would see the correction it just wrote and refuse forever —
                // reversing would permanently lock the run out of the ledger instead of freeing it.
                ->whereNull('reversal_of_id')
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === 'posted') {
                throw ValidationException::withMessages([
                    'payroll_run_id' => [
                        'This run is already posted to the ledger (entry #' . $existing->id
                            . '). Reverse that entry before posting it again.',
                    ],
                ]);
            }

            $entry = $existing ?? new JournalEntry(['payroll_run_id' => $run->id]);
            $entry->fill([
                // Defaults to the cycle end, which is the period the cost belongs to — not today,
                // which is merely when somebody got round to generating it.
                'posting_date' => $postingDate ?? $run->cycle_end?->toDateString() ?? now()->toDateString(),
                'status' => 'draft',
            ]);
            $entry->save();

            // A draft is rebuilt wholesale rather than diffed: the lines are derived from the run, so a
            // partial update could leave a stale line from a payslip that has since changed.
            $entry->lines()->delete();

            foreach ($this->linesFor($run, $accounts) as $line) {
                JournalLine::create(['journal_entry_id' => $entry->id] + $line);
            }

            $entry->load('lines');
            $this->assertBalanced($entry);

            $this->audit->log(
                'created',
                'Accounting',
                'Drafted journal entry #' . $entry->id . ' for the '
                    . $run->cycle_start?->format('M j') . '–' . $run->cycle_end?->format('M j')
                    . ' run (' . number_format($entry->totalDebit(), 2) . ' each side)',
            );

            return $entry;
        });
    }

    /**
     * Put a draft on the books.
     *
     * THE DUPLICATE-POSTING GUARD IS HERE, and it is a locked re-read rather than a check on the object
     * passed in: two finance users clicking Post on the same run would otherwise both see `draft` and
     * both write, double-counting an entire payroll in the ledger.
     */
    public function post(JournalEntry $entry, User $actor): JournalEntry
    {
        return DB::transaction(function () use ($entry, $actor) {
            $fresh = JournalEntry::where('id', $entry->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => ['Only a draft entry can be posted — this one is already ' . $fresh->status . '.'],
                ]);
            }

            // Belt and braces: a draft could have been hand-edited between build and post.
            $fresh->load('lines');
            $this->assertBalanced($fresh);

            $fresh->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $actor->id,
            ]);

            $this->audit->log(
                'updated',
                'Accounting',
                'Posted journal entry #' . $fresh->id . ' dated ' . $fresh->posting_date->toDateString()
                    . ' (' . number_format($fresh->totalDebit(), 2) . ' each side)',
            );

            return $fresh->load('lines.account');
        });
    }

    /**
     * Cancel a posted entry with a MIRROR, never by deleting it.
     *
     * The original keeps status `reversed`, so both halves of the correction stay on the books — which is
     * what a ledger is for, and what the duplicate-posting guard reads to know the run is free to post
     * again. Deleting would destroy the evidence that it had ever been posted.
     */
    public function reverse(JournalEntry $entry, User $actor, ?string $reason = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $actor, $reason) {
            $fresh = JournalEntry::where('id', $entry->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== 'posted') {
                throw ValidationException::withMessages([
                    'status' => ['Only a posted entry can be reversed — this one is ' . $fresh->status . '.'],
                ]);
            }

            $fresh->load('lines');

            $mirror = JournalEntry::create([
                'payroll_run_id' => $fresh->payroll_run_id,
                // Reversals post TODAY, not on the original's date: back-dating one into a closed
                // accounting period is exactly what a reversal exists to avoid.
                'posting_date' => now(config('app.timezone_local'))->toDateString(),
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $actor->id,
                'reversal_of_id' => $fresh->id,
                'note' => $reason,
            ]);

            foreach ($fresh->lines as $line) {
                JournalLine::create([
                    'journal_entry_id' => $mirror->id,
                    'gl_account_id' => $line->gl_account_id,
                    // Swapped — that is the whole mechanism.
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'memo' => 'Reversal of entry #' . $fresh->id,
                ]);
            }

            $fresh->update(['status' => 'reversed']);

            $this->audit->log(
                'updated',
                'Accounting',
                'Reversed journal entry #' . $fresh->id . ' with #' . $mirror->id
                    . ($reason ? " — {$reason}" : ''),
            );

            return $mirror->load('lines.account');
        });
    }

    /**
     * The lines for a run, derived from its payslips.
     *
     * Only DISBURSED and COMPUTED payslips are included — the same population the run's own totals
     * describe. A negative figure flips sides rather than posting as a negative amount on its natural
     * side: withholding tax genuinely goes negative on a year-end true-up or a leaver's final pay
     * (FIN-028, FIN-014), and "CR −₱4,000" is not a line any ledger accepts.
     *
     * @param  array<string, GlAccount>  $accounts
     * @return list<array{gl_account_id:int, debit:float, credit:float, memo:string}>
     */
    private function linesFor(PayrollRun $run, array $accounts): array
    {
        $slips = $run->payslips()->get();

        $sum = fn (string $column) => round((float) $slips->sum($column), 2);

        $gross = $sum('gross');
        $employer = round(
            $sum('employer_sss') + $sum('employer_philhealth') + $sum('employer_pagibig'),
            2,
        );

        $lines = [];

        // --- what the company spent -------------------------------------------------------------
        $this->push($lines, $accounts, 'salaries_expense', $gross, 'debit', 'Gross earnings');
        $this->push($lines, $accounts, 'employer_contributions_expense', $employer, 'debit', 'Employer share of SSS / PhilHealth / Pag-IBIG');

        // --- what it now owes -------------------------------------------------------------------
        // Each agency payable carries BOTH halves: the employee's deduction and the employer's share
        // are remitted together on one filing, so splitting them across accounts would make the
        // payable disagree with the remittance.
        $this->push($lines, $accounts, 'sss_payable', round($sum('sss') + $sum('employer_sss'), 2), 'credit', 'Employee + employer SSS');
        $this->push($lines, $accounts, 'philhealth_payable', round($sum('philhealth') + $sum('employer_philhealth'), 2), 'credit', 'Employee + employer PhilHealth');
        $this->push($lines, $accounts, 'pagibig_payable', round($sum('pagibig') + $sum('employer_pagibig'), 2), 'credit', 'Employee + employer Pag-IBIG');
        $this->push($lines, $accounts, 'withholding_tax_payable', $sum('withholding_tax'), 'credit', 'Withholding tax');
        // FIN-032 — loan interest is REVENUE, split out of `other_deductions` rather than posted to
        // the payable with it. Principal repayment reduces a receivable; interest is what the company
        // earned for lending. Only split when an account exists for it — a company lending at 0%
        // collects no interest and needs no such account.
        $loanInterest = $sum('loan_interest');
        $hasInterestAccount = isset($accounts['loan_interest_income']);

        if ($loanInterest != 0.0 && $hasInterestAccount) {
            $this->push($lines, $accounts, 'other_deductions_payable', round($sum('other_deductions') - $loanInterest, 2), 'credit', 'Loans, arrears and other deductions');
            $this->push($lines, $accounts, 'loan_interest_income', $loanInterest, 'credit', 'Interest earned on employee loans');
        } else {
            $this->push($lines, $accounts, 'other_deductions_payable', $sum('other_deductions'), 'credit', 'Loans, arrears and other deductions');
        }
        $this->push($lines, $accounts, 'net_pay_payable', $sum('net_pay'), 'credit', 'Net pay owed to employees');

        return $lines;
    }

    /**
     * Append a line, flipping the side when the amount is negative, and skipping zeroes.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, GlAccount>  $accounts
     */
    private function push(array &$lines, array $accounts, string $role, float $amount, string $side, string $memo): void
    {
        if (round($amount, 2) == 0.0) {
            return; // a zero line carries no information and clutters every export
        }

        // A refund reverses the direction of the obligation, so it belongs on the other side.
        if ($amount < 0) {
            $side = $side === 'debit' ? 'credit' : 'debit';
            $amount = abs($amount);
        }

        $lines[] = [
            'gl_account_id' => $accounts[$role]->id,
            'debit' => $side === 'debit' ? round($amount, 2) : 0.0,
            'credit' => $side === 'credit' ? round($amount, 2) : 0.0,
            'memo' => $memo,
        ];
    }

    /**
     * The mapped accounts, keyed by role — refusing until every role has one.
     *
     * Naming the missing roles matters: "configure the chart of accounts" is not actionable, and a
     * half-mapped chart would post a journal that balances only because the unmapped figures were
     * silently dropped.
     *
     * @return array<string, GlAccount>
     */
    private function accountsByRole(): array
    {
        $accounts = GlAccount::where('is_active', true)
            ->whereNotNull('payroll_role')
            ->get()
            ->keyBy('payroll_role');

        // REQUIRED_ROLES, not every role: `loan_interest_income` is only needed once a run actually
        // charged interest, and demanding it up front would block a company that lends at 0%.
        $missing = array_diff(GlAccount::REQUIRED_ROLES, $accounts->keys()->all());

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'gl_accounts' => [
                    'The chart of accounts is incomplete — map an account to: '
                        . implode(', ', array_map(fn ($r) => GlAccount::ROLES[$r], $missing)) . '.',
                ],
            ]);
        }

        return $accounts->all();
    }

    /** A journal that does not balance is a payroll bug, and must not be hidden inside accounting. */
    private function assertBalanced(JournalEntry $entry): void
    {
        if ($entry->isBalanced()) {
            return;
        }

        throw ValidationException::withMessages([
            'lines' => [
                'The entry does not balance: debits ' . number_format($entry->totalDebit(), 2)
                    . ' against credits ' . number_format($entry->totalCredit(), 2)
                    . '. This is a payroll problem, not an accounting one — the run should not be posted.',
            ],
        ]);
    }

    /**
     * The accounting export: one row per journal line, for whatever is on the books in a date range.
     *
     * DRAFTS ARE EXCLUDED. An export is what gets handed to accounting or loaded into the ledger, and a
     * draft is by definition not on the books — including one would post an entry nobody approved. Both
     * `posted` and `reversed` entries appear, with their reversals, because that is what the ledger
     * actually contains and dropping the cancelled halves would make the file fail to balance.
     *
     * @return list<array<int, string|float>>
     */
    public function exportRows(string $from, string $to): array
    {
        $entries = JournalEntry::with(['lines.account', 'payrollRun'])
            ->whereIn('status', ['posted', 'reversed'])
            ->whereBetween('posting_date', [$from, $to])
            ->orderBy('posting_date')
            ->orderBy('id')
            ->get();

        $rows = [[
            'Posting date', 'Entry', 'Reference', 'Account code', 'Account name',
            'Debit', 'Credit', 'Memo', 'Payroll period', 'Status',
        ]];

        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                $rows[] = [
                    $entry->posting_date->toDateString(),
                    '#' . $entry->id,
                    $entry->reference ?? '',
                    $line->account?->code ?? '',
                    $line->account?->name ?? '',
                    // Full precision — this is the artifact the ledger is loaded from, so the
                    // rounded on-screen figures have no place in it (FIN-017's lesson).
                    number_format((float) $line->debit, 2, '.', ''),
                    number_format((float) $line->credit, 2, '.', ''),
                    $line->memo ?? '',
                    $entry->payrollRun
                        ? $entry->payrollRun->cycle_start?->toDateString() . ' to ' . $entry->payrollRun->cycle_end?->toDateString()
                        : '',
                    $entry->reversal_of_id ? 'reversal of #' . $entry->reversal_of_id : $entry->status,
                ];
            }
        }

        return $rows;
    }

    /** Whether a run already has something on the books — what the UI reads to label its button. */
    public function statusFor(PayrollRun $run): ?JournalEntry
    {
        return JournalEntry::with('lines.account')
            ->where('payroll_run_id', $run->id)
            ->whereNull('reversal_of_id')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Per-employee, per-agency, per-MONTH statutory remittance at full precision (FIN-017 / MF-08).
     *
     * WHAT WAS ALREADY THERE, so this does not duplicate it: `PayrollService::runCsv()` exports exact
     * per-employee statutory figures — both halves of each contribution, the override flag, the totals —
     * for ONE RUN. That was never the gap.
     *
     * THE GAP IS THE FILING SHAPE. Agencies are remitted monthly, per agency. Under a semi-monthly
     * schedule a month is two runs, so producing the one figure that goes on the form meant opening two
     * CSVs and adding them by hand — while the only consolidated view on screen went through
     * `ReportService::peso()`, which is `number_format($n, 0)` and drops the centavos. A remittance
     * short by a few pesos across a few hundred employees is a filing discrepancy.
     *
     * SO IT AGGREGATES BY MONTH AND SPLITS BY AGENCY, at full precision, and includes the government
     * numbers each form needs. `SUM()` in SQL rather than summing rounded per-run figures: rounding
     * twice is how the total stops matching the payslips.
     *
     * DISBURSED PAYSLIPS ONLY. A remittance is a statement about money that moved; a computed-but-unpaid
     * run has created no obligation to remit yet, and including it would over-remit for a cycle that may
     * still be corrected. This is the same distinction FIN-052 drew for the payroll report's headline.
     *
     * @return list<array<int, string|float>>
     */
    public function remittanceRows(string $month, string $agency): array
    {
        $start = Carbon::parse($month . '-01', config('app.timezone_local'))->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // Which payslip columns each agency's filing needs, and the number that identifies the member.
        $columns = match ($agency) {
            'sss' => ['employee' => 'sss', 'employer' => 'employer_sss', 'number' => 'sss_number'],
            'philhealth' => ['employee' => 'philhealth', 'employer' => 'employer_philhealth', 'number' => 'philhealth_number'],
            'pagibig' => ['employee' => 'pagibig', 'employer' => 'employer_pagibig', 'number' => 'pagibig_number'],
            // Withholding tax has no employer counterpart — the company remits what it withheld.
            'tax' => ['employee' => 'withholding_tax', 'employer' => null, 'number' => 'tin'],
            default => throw ValidationException::withMessages([
                'agency' => ['Unknown agency. Expected sss, philhealth, pagibig or tax.'],
            ]),
        };

        $slips = \App\Modules\Hr\Models\Payslip::query()
            ->join('hr_payroll_runs', 'hr_payslips.payroll_run_id', '=', 'hr_payroll_runs.id')
            ->where('hr_payslips.status', 'disbursed')
            ->whereBetween('hr_payroll_runs.cycle_end', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('hr_payslips.employee_id')
            // Summed in SQL at full precision. Adding already-rounded per-run figures is how a monthly
            // total drifts away from the payslips it came from.
            ->selectRaw('SUM(hr_payslips.' . $columns['employee'] . ') AS employee_share')
            ->selectRaw($columns['employer'] === null
                ? '0 AS employer_share'
                : 'SUM(hr_payslips.' . $columns['employer'] . ') AS employer_share')
            ->selectRaw('COUNT(*) AS runs_in_month')
            ->groupBy('hr_payslips.employee_id')
            ->get();

        // `withTrashed()`: somebody who left mid-month still has to appear on that month's filing.
        $employees = \App\Modules\Hr\Models\Employee::withTrashed()
            ->with('user')
            ->whereIn('id', $slips->pluck('employee_id'))
            ->get()
            ->keyBy('id');

        $label = ['sss' => 'SSS', 'philhealth' => 'PhilHealth', 'pagibig' => 'Pag-IBIG', 'tax' => 'Withholding tax'][$agency];

        $rows = [[
            'Employee no', 'Name', $label . ' number', 'Runs in month',
            'Employee share', 'Employer share', 'Total remittable',
        ]];

        $totals = ['employee' => 0.0, 'employer' => 0.0];

        foreach ($slips as $row) {
            $employee = $employees->get($row->employee_id);
            $employeeShare = round((float) $row->employee_share, 2);
            $employerShare = round((float) $row->employer_share, 2);

            $totals['employee'] += $employeeShare;
            $totals['employer'] += $employerShare;

            $rows[] = [
                $employee?->employee_no ?? '',
                $employee?->user?->name ?? '',
                // The FULL government number, not the masked form. This file IS the filing — a
                // remittance listing "••••2-34" is unusable, and it goes to the agency, not on screen.
                // The reveal is audited by the export log rather than per-field (FIN-022).
                (string) ($employee?->{$columns['number']} ?? ''),
                (int) $row->runs_in_month,
                number_format($employeeShare, 2, '.', ''),
                number_format($employerShare, 2, '.', ''),
                number_format(round($employeeShare + $employerShare, 2), 2, '.', ''),
            ];
        }

        // A TOTAL row, because the figure on the form is the total and re-adding a few hundred rows by
        // hand is exactly the step this export exists to remove.
        $rows[] = [
            'TOTAL', '', '', '',
            number_format(round($totals['employee'], 2), 2, '.', ''),
            number_format(round($totals['employer'], 2), 2, '.', ''),
            number_format(round($totals['employee'] + $totals['employer'], 2), 2, '.', ''),
        ];

        return $rows;
    }

    /** The posting date a run defaults to — its cycle end, the period the cost belongs to. */
    public function defaultPostingDate(PayrollRun $run): string
    {
        return $run->cycle_end?->toDateString()
            ?? Carbon::now(config('app.timezone_local'))->toDateString();
    }
}
