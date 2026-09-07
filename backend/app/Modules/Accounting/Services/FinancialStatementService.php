<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives the two core financial statements from posted journal entries:
 *  - Profit & Loss (income − expenses) for a month
 *  - Balance Sheet (assets = liabilities + equity + net income) as of a date
 *
 * By the double-entry identity these always balance.
 */
class FinancialStatementService
{
    /** Profit & Loss for a month ("YYYY-MM"). */
    public function profitAndLoss(string $period): array
    {
        $start = Carbon::parse($period . '-01')->startOfMonth();
        $end = $start->copy()->addMonth();

        $totals = $this->accountTotals($start->toDateString(), $end->toDateString());

        $income = [];
        $expense = [];
        $totalIncome = 0.0;
        $totalExpense = 0.0;
        $employerContributions = 0.0;

        foreach ($totals as $r) {
            if ($r->type === 'income') {
                $amount = round((float) $r->credit - (float) $r->debit, 2);
                $income[] = ['code' => $r->code, 'name' => $r->name, 'amount' => $amount];
                $totalIncome += $amount;
            } elseif ($r->type === 'expense') {
                $amount = round((float) $r->debit - (float) $r->credit, 2);
                $isEmployerContribution = in_array($r->code, ChartOfAccountsProvisioner::EMPLOYER_CONTRIBUTION_CODES, true);
                $expense[] = [
                    'code' => $r->code,
                    'name' => $r->name,
                    'amount' => $amount,
                    'is_employer_contribution' => $isEmployerContribution,
                ];
                $totalExpense += $amount;
                if ($isEmployerContribution) {
                    $employerContributions += $amount;
                }
            }
        }

        return [
            'period' => $period,
            'income' => $income,
            'expense' => $expense,
            'total_income' => round($totalIncome, 2),
            'total_expense' => round($totalExpense, 2),
            'net_income' => round($totalIncome - $totalExpense, 2),
            'employer_contributions' => round($employerContributions, 2),
        ];
    }

    /** Balance Sheet as of a date ("YYYY-MM-DD"), cumulative from the beginning. */
    public function balanceSheet(string $asOf): array
    {
        $endExclusive = Carbon::parse($asOf)->addDay()->toDateString();
        $totals = $this->accountTotals(null, $endExclusive);

        $assets = [];
        $liabilities = [];
        $equity = [];
        $totalAssets = 0.0;
        $totalLiabilities = 0.0;
        $totalEquity = 0.0;
        $income = 0.0;
        $expense = 0.0;

        foreach ($totals as $r) {
            $debit = (float) $r->debit;
            $credit = (float) $r->credit;

            switch ($r->type) {
                case 'asset':
                    $amount = round($debit - $credit, 2);
                    $assets[] = ['code' => $r->code, 'name' => $r->name, 'amount' => $amount];
                    $totalAssets += $amount;
                    break;
                case 'liability':
                    $amount = round($credit - $debit, 2);
                    $liabilities[] = ['code' => $r->code, 'name' => $r->name, 'amount' => $amount];
                    $totalLiabilities += $amount;
                    break;
                case 'equity':
                    $amount = round($credit - $debit, 2);
                    $equity[] = ['code' => $r->code, 'name' => $r->name, 'amount' => $amount];
                    $totalEquity += $amount;
                    break;
                case 'income':
                    $income += round($credit - $debit, 2);
                    break;
                case 'expense':
                    $expense += round($debit - $credit, 2);
                    break;
            }
        }

        $netIncome = round($income - $expense, 2);
        $totalEquityWithIncome = round($totalEquity + $netIncome, 2);

        return [
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'net_income' => $netIncome,
            'total_assets' => round($totalAssets, 2),
            'total_liabilities' => round($totalLiabilities, 2),
            'total_equity' => $totalEquityWithIncome,
            'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquityWithIncome)) < 0.005,
        ];
    }

    /**
     * Per-account debit/credit totals for posted entries in [start, endExclusive).
     * A null $start means "from the beginning" (cumulative).
     */
    private function accountTotals(?string $start, string $endExclusive): Collection
    {
        $query = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.org_id', CurrentOrg::id())
            ->where('e.status', 'posted')
            ->whereNull('e.deleted_at')
            ->where('e.entry_date', '<', $endExclusive);

        if ($start !== null) {
            $query->where('e.entry_date', '>=', $start);
        }

        return $query
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, a.type, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get();
    }
}
