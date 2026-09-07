<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts payroll to the ledger as ONE balanced journal entry — surfacing the
 * employer share of SSS/PhilHealth/Pag-IBIG as a real company expense.
 *
 * Two sources: (1) a live HRIS payroll run (postRun), or (2) totals imported
 * from a CSV when the HRIS is external/absent (importTotals). Both post the
 * SAME balanced entry through postTotals().
 */
class PayrollPostingService
{
    public function __construct(
        private readonly JournalService $journal,
    ) {}

    /**
     * @return array<string, float|int>
     */
    public function totalsForRun(int $runId): array
    {
        // Raw query — the BelongsToOrg scope does not apply, so fence the tenant
        // here. The run-id filter alone would sum another org's payslips if a
        // foreign run id were passed; with the org fence a foreign run simply
        // sums to zero payslips (and postRun refuses it outright).
        $t = DB::table('hr_payslips')
            ->where('org_id', CurrentOrg::id())
            ->where('payroll_run_id', $runId)
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*) as employees,
                COALESCE(SUM(gross), 0) as gross,
                COALESCE(SUM(reimbursements), 0) as reimbursements,
                COALESCE(SUM(sss), 0) as ee_sss,
                COALESCE(SUM(philhealth), 0) as ee_ph,
                COALESCE(SUM(pagibig), 0) as ee_pg,
                COALESCE(SUM(employer_sss), 0) as er_sss,
                COALESCE(SUM(employer_philhealth), 0) as er_ph,
                COALESCE(SUM(employer_pagibig), 0) as er_pg,
                COALESCE(SUM(withholding_tax), 0) as wht,
                COALESCE(SUM(net_pay), 0) as net
            ')
            ->first();

        return [
            'employees' => (int) ($t->employees ?? 0),
            'gross' => (float) ($t->gross ?? 0),
            'reimbursements' => (float) ($t->reimbursements ?? 0),
            'ee_sss' => (float) ($t->ee_sss ?? 0),
            'ee_ph' => (float) ($t->ee_ph ?? 0),
            'ee_pg' => (float) ($t->ee_pg ?? 0),
            'er_sss' => (float) ($t->er_sss ?? 0),
            'er_ph' => (float) ($t->er_ph ?? 0),
            'er_pg' => (float) ($t->er_pg ?? 0),
            'wht' => (float) ($t->wht ?? 0),
            'net' => (float) ($t->net ?? 0),
        ];
    }

    public function employerCost(array $totals): float
    {
        return round(
            $totals['gross'] + $totals['er_sss'] + $totals['er_ph'] + $totals['er_pg'],
            2,
        );
    }

    public function isPosted(int $runId): bool
    {
        return JournalEntry::query()
            ->where('org_id', CurrentOrg::id())
            ->where('source_type', 'payroll')
            ->where('source_id', $runId)
            ->exists();
    }

    /** Post a live HRIS payroll run. */
    public function postRun(int $runId, User $user): JournalEntry
    {
        if ($this->isPosted($runId)) {
            throw ValidationException::withMessages([
                'run' => 'This payroll run has already been posted to the ledger.',
            ]);
        }

        // Raw query — org-fenced by hand (BelongsToOrg does not apply to DB::table).
        // Another org's run id resolves to "not found" rather than posting foreign
        // payroll into this org's ledger.
        $run = DB::table('hr_payroll_runs')
            ->where('org_id', CurrentOrg::id())
            ->where('id', $runId)
            ->whereNull('deleted_at')
            ->first();
        if (! $run) {
            throw ValidationException::withMessages(['run' => 'Payroll run not found.']);
        }

        $t = $this->totalsForRun($runId);
        if ($t['employees'] === 0) {
            throw ValidationException::withMessages(['run' => 'This payroll run has no payslips to post.']);
        }

        return $this->postTotals(
            $t,
            $run->cycle_end,
            'PAYROLL-' . $runId,
            sprintf('Payroll %s to %s (%d employees)', $run->cycle_start, $run->cycle_end, $t['employees']),
            $runId,
            $user,
        );
    }

    /**
     * Post payroll totals imported from a CSV. Net pay is derived if not given:
     * gross − (employee contributions + withholding).
     *
     * @param  array<string, float>  $t
     */
    public function importTotals(array $t, string $entryDate, string $memo, User $user): JournalEntry
    {
        // Idempotency: one CSV import per entry date. Re-uploading the same period
        // is rejected instead of silently double-posting (scoped to the org).
        $reference = 'PAYROLL-IMPORT-' . $entryDate;
        if (JournalEntry::query()->where('source_type', 'payroll')->where('reference', $reference)->exists()) {
            throw ValidationException::withMessages([
                'file' => "A payroll import for {$entryDate} has already been posted.",
            ]);
        }

        $t += ['gross' => 0, 'ee_sss' => 0, 'ee_ph' => 0, 'ee_pg' => 0, 'er_sss' => 0, 'er_ph' => 0, 'er_pg' => 0, 'wht' => 0, 'net' => 0];

        if ($t['net'] <= 0) {
            $t['net'] = round($t['gross'] - ($t['ee_sss'] + $t['ee_ph'] + $t['ee_pg'] + $t['wht']), 2);
        }
        $t['reimbursements'] = 0.0;

        if ($t['gross'] <= 0) {
            throw ValidationException::withMessages(['file' => 'The CSV has no gross pay to import.']);
        }

        return $this->postTotals($t, $entryDate, $reference, $memo, null, $user);
    }

    /** Build + post the balanced payroll journal entry from totals. */
    private function postTotals(array $t, string $entryDate, string $reference, string $memo, ?int $sourceId, User $user): JournalEntry
    {
        $accounts = $this->accountMap();
        $lines = [];
        $reimb = (float) ($t['reimbursements'] ?? 0);

        $this->addLine($lines, $accounts, '5000', debit: round(((float) $t['gross']) - $reimb, 2)); // Salaries Expense
        $this->addLine($lines, $accounts, '5300', debit: $reimb);                                    // Reimbursement Expense
        $this->addLine($lines, $accounts, '5100', debit: (float) $t['er_sss']);                      // SSS Employer Expense
        $this->addLine($lines, $accounts, '5110', debit: (float) $t['er_ph']);                       // PhilHealth Employer Expense
        $this->addLine($lines, $accounts, '5120', debit: (float) $t['er_pg']);                       // Pag-IBIG Employer Expense

        $this->addLine($lines, $accounts, '2100', credit: round(((float) $t['ee_sss']) + ((float) $t['er_sss']), 2));
        $this->addLine($lines, $accounts, '2110', credit: round(((float) $t['ee_ph']) + ((float) $t['er_ph']), 2));
        $this->addLine($lines, $accounts, '2120', credit: round(((float) $t['ee_pg']) + ((float) $t['er_pg']), 2));
        $this->addLine($lines, $accounts, '2130', credit: (float) $t['wht']);
        $this->addLine($lines, $accounts, '2200', credit: (float) $t['net']);

        $entry = $this->journal->create([
            'entry_date' => $entryDate,
            'reference' => $reference,
            'memo' => $memo,
            'source_type' => 'payroll',
            'source_id' => $sourceId,
            'lines' => $lines,
        ]);

        return $this->journal->post($entry, $user);
    }

    /** @return array<string, int> account code => id for the current org */
    private function accountMap(): array
    {
        return Account::query()
            ->where('org_id', CurrentOrg::id())
            ->pluck('id', 'code')
            ->all();
    }

    private function addLine(array &$lines, array $accounts, string $code, float $debit = 0, float $credit = 0): void
    {
        $amount = $debit ?: $credit;
        if (round($amount, 2) <= 0) {
            return;
        }
        if (! isset($accounts[$code])) {
            throw ValidationException::withMessages([
                'accounts' => "Missing account {$code} in the chart of accounts.",
            ]);
        }
        $lines[] = [
            'account_id' => $accounts[$code],
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
        ];
    }
}
