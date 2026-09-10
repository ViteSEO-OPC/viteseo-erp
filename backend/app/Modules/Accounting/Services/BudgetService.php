<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    /**
     * Budget vs actual for every EXPENSE account in a month. Actual = net movement
     * (debit − credit) from posted journal entries dated within the period — so the
     * employer contributions posted from payroll show up here as real spending.
     *
     * @return array<int, array{account_id:int, code:string, name:string, budget:float, actual:float, variance:float}>
     */
    public function budgetVsActual(string $period): array
    {
        $orgId = CurrentOrg::id();
        $start = Carbon::parse($period . '-01')->startOfMonth();
        $end = $start->copy()->addMonth();

        $actuals = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.org_id', $orgId)
            ->where('e.status', 'posted')
            ->whereNull('e.deleted_at')
            ->where('e.entry_date', '>=', $start->toDateString())
            ->where('e.entry_date', '<', $end->toDateString())
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.debit) - SUM(l.credit) as net')
            ->pluck('net', 'account_id');

        $budgets = Budget::query()
            ->where('org_id', $orgId)
            ->where('period', $period)
            ->pluck('amount', 'account_id');

        return Account::query()
            ->where('org_id', $orgId)
            ->where('type', 'expense')
            ->orderBy('code')
            ->get()
            ->map(function (Account $a) use ($actuals, $budgets): array {
                $actual = round((float) ($actuals[$a->id] ?? 0), 2);
                $budget = round((float) ($budgets[$a->id] ?? 0), 2);

                return [
                    'account_id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name,
                    'budget' => $budget,
                    'actual' => $actual,
                    'variance' => round($budget - $actual, 2),
                ];
            })
            ->all();
    }

    /** Set (upsert) the budget for one account in one period. */
    public function setBudget(string $period, int $accountId, float $amount): void
    {
        Budget::updateOrCreate(
            ['org_id' => CurrentOrg::id(), 'period' => $period, 'account_id' => $accountId],
            ['amount' => round($amount, 2)],
        );
    }
}
