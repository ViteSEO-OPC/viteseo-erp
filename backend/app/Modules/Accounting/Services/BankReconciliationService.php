<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Enums\JournalStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankReconciliation;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\AccountingClock;
use App\Support\CurrentOrg;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bank reconciliation (single-pass): match a cash/bank account's posted ledger
 * lines against the bank statement. The user ticks the lines that cleared; when
 * the cleared balance equals the statement balance the reconciliation is saved
 * and those lines are stamped as reconciled. Balances are signed as debit − credit
 * (a debit raises a cash/asset account).
 */
class BankReconciliationService
{
    /** Posted lines on the account, only those not yet reconciled, up to `asOf`. */
    private function unreconciledLinesQuery(int $accountId, ?Carbon $asOf = null)
    {
        return JournalLine::query()
            ->where('account_id', $accountId)
            ->whereNull('bank_reconciliation_id')
            ->whereHas('entry', function ($q) use ($asOf): void {
                $q->where('org_id', CurrentOrg::id())
                    ->where('status', JournalStatus::Posted->value);
                if ($asOf) {
                    $q->whereDate('entry_date', '<=', $asOf);
                }
            });
    }

    /** Sum of debit − credit over already-reconciled lines (the opening cleared balance). */
    private function beginningBalance(int $accountId): float
    {
        $row = JournalLine::query()
            ->where('account_id', $accountId)
            ->whereNotNull('bank_reconciliation_id')
            ->whereHas('entry', fn ($q) => $q->where('org_id', CurrentOrg::id()))
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')
            ->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    /**
     * The reconciliation worksheet for an account as of a date: the opening
     * (previously-reconciled) balance, the full book balance, and every
     * outstanding line to tick.
     *
     * @return array<string, mixed>
     */
    public function worksheet(int $accountId, ?string $asOf = null): array
    {
        $asOfDate = $asOf ? Carbon::parse($asOf)->endOfDay() : Carbon::parse(AccountingClock::today())->endOfDay();
        $account = Account::query()->findOrFail($accountId); // org-scoped

        $lines = $this->unreconciledLinesQuery($accountId, $asOfDate)
            ->with('entry:id,entry_date,reference,memo,status')
            ->get()
            ->sortBy(fn ($l) => $l->entry?->entry_date?->toDateString())
            ->values()
            ->map(fn (JournalLine $l) => [
                'id' => $l->id,
                'entry_date' => $l->entry?->entry_date?->toDateString(),
                'reference' => $l->entry?->reference,
                'memo' => $l->memo ?? $l->entry?->memo,
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
            ]);

        // Book balance = every posted line on the account up to asOf (reconciled or not).
        $book = JournalLine::query()
            ->where('account_id', $accountId)
            ->whereHas('entry', fn ($q) => $q->where('org_id', CurrentOrg::id())
                ->where('status', JournalStatus::Posted->value)
                ->whereDate('entry_date', '<=', $asOfDate))
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')
            ->first();

        return [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'as_of' => $asOfDate->toDateString(),
            'beginning_balance' => $this->beginningBalance($accountId),
            'book_balance' => round((float) $book->d - (float) $book->c, 2),
            'lines' => $lines,
        ];
    }

    /**
     * Complete a reconciliation: the cleared lines must bring the beginning
     * balance up to the statement balance exactly, or it's rejected.
     */
    public function complete(int $accountId, string $statementDate, float $statementBalance, array $clearedLineIds, User $actor): BankReconciliation
    {
        return DB::transaction(function () use ($accountId, $statementDate, $statementBalance, $clearedLineIds, $actor): BankReconciliation {
            Account::query()->findOrFail($accountId); // ensure the account is in this org

            // Only accept lines that are outstanding on THIS account AND dated on
            // or before the statement date (matching what the worksheet showed) —
            // a reconciliation is an as-of-date assertion.
            $asOf = Carbon::parse($statementDate)->endOfDay();
            $lines = $this->unreconciledLinesQuery($accountId, $asOf)
                ->whereIn('acc_journal_lines.id', $clearedLineIds)
                ->get();

            if ($lines->count() !== count(array_unique($clearedLineIds))) {
                throw ValidationException::withMessages([
                    'cleared_line_ids' => 'Some selected transactions are invalid or already reconciled.',
                ]);
            }

            $clearedSum = round($lines->sum(fn (JournalLine $l) => (float) $l->debit - (float) $l->credit), 2);
            $beginning = $this->beginningBalance($accountId);
            $clearedBalance = round($beginning + $clearedSum, 2);

            if (abs($statementBalance - $clearedBalance) >= 0.005) {
                throw ValidationException::withMessages([
                    'statement_balance' => 'Cleared balance (' . number_format($clearedBalance, 2)
                        . ') does not match the statement balance. Difference: '
                        . number_format($statementBalance - $clearedBalance, 2) . '.',
                ]);
            }

            $reconciliation = BankReconciliation::create([
                'account_id' => $accountId,
                'statement_date' => $statementDate,
                'statement_balance' => $statementBalance,
                'beginning_balance' => $beginning,
                'cleared_balance' => $clearedBalance,
                'status' => 'completed',
                'reconciled_by' => $actor->id,
            ]);

            JournalLine::query()->whereIn('id', $lines->pluck('id'))
                ->update(['bank_reconciliation_id' => $reconciliation->id]);

            return $reconciliation;
        });
    }

    /** Past reconciliations, newest first (optionally for one account). */
    public function history(?int $accountId = null): array
    {
        return BankReconciliation::query()
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->with('account:id,code,name')
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BankReconciliation $r) => [
                'id' => $r->id,
                'account' => $r->account ? ['code' => $r->account->code, 'name' => $r->account->name] : null,
                'statement_date' => $r->statement_date?->toDateString(),
                'statement_balance' => (float) $r->statement_balance,
                'beginning_balance' => (float) $r->beginning_balance,
                'cleared_balance' => (float) $r->cleared_balance,
                'reconciled_at' => $r->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
