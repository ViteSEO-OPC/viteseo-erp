<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\CashflowReportService;
use App\Modules\Accounting\Services\FinancialStatementService;
use App\Modules\Accounting\Services\JournalService;
use App\Modules\Accounting\Support\AccountingClock;
use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly FinancialStatementService $statements,
        private readonly CashflowReportService $cashflow,
    ) {}

    /**
     * Trial balance — per-account debit/credit totals for posted entries, plus the
     * grand totals and a `balanced` flag (they should always be equal).
     */
    public function trialBalance(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        $rows = $this->journal->trialBalance();
        $totalDebit = round(array_sum(array_column($rows, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($rows, 'credit')), 2);

        return response()->json([
            'data' => [
                'rows' => $rows,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balanced' => abs($totalDebit - $totalCredit) < 0.005,
            ],
        ]);
    }

    /** Profit & Loss for a month (defaults to the current month). */
    public function profitAndLoss(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        $period = (string) $request->query('period', AccountingClock::now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = AccountingClock::now()->format('Y-m');
        }

        return response()->json(['data' => $this->statements->profitAndLoss($period)]);
    }

    /** Balance Sheet as of a date (defaults to today). */
    public function balanceSheet(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        $asOf = (string) $request->query('as_of', AccountingClock::today());
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            $asOf = AccountingClock::today();
        }

        return response()->json(['data' => $this->statements->balanceSheet($asOf)]);
    }

    /** Aged receivables — unpaid invoices bucketed by overdue age. */
    public function agedReceivables(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        return response()->json(['data' => $this->cashflow->agedReceivables()]);
    }

    /** BIR Form 2550Q — quarterly VAT return figures (Output − Input VAT). */
    public function birVat2550Q(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        return response()->json([
            'data' => $this->cashflow->birVat2550Q((int) $data['year'], (int) $data['quarter']),
        ]);
    }

    /** BIR SLSP — Summary List of Sales (per-customer, with TIN) for a quarter. */
    public function summaryListOfSales(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);
        $data = $this->quarterInput($request);

        return response()->json(['data' => $this->cashflow->summaryListOfSales((int) $data['year'], (int) $data['quarter'])]);
    }

    /** BIR SLSP — Summary List of Purchases (per-supplier, with TIN) for a quarter. */
    public function summaryListOfPurchases(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);
        $data = $this->quarterInput($request);

        return response()->json(['data' => $this->cashflow->summaryListOfPurchases((int) $data['year'], (int) $data['quarter'])]);
    }

    /** @return array{year:int, quarter:int} */
    private function quarterInput(Request $request): array
    {
        return $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'quarter' => ['required', 'integer', 'min:1', 'max:4'],
        ]);
    }

    /** Statement of Account for one customer (open invoices + payments + aging). */
    public function statementOfAccount(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        $data = $request->validate([
            'contact_id' => ['required', 'integer'],
            'as_of' => ['nullable', 'date'],
        ]);

        return response()->json([
            'data' => $this->cashflow->statementOfAccount((int) $data['contact_id'], $data['as_of'] ?? null),
        ]);
    }

    /** Aged payables — unpaid bills bucketed by overdue age. */
    public function agedPayables(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        return response()->json(['data' => $this->cashflow->agedPayables()]);
    }

    /** General ledger — one account's posted lines with a running balance. */
    public function generalLedger(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        $data = $request->validate([
            'account_id' => [
                'required',
                'integer',
                Rule::exists('acc_accounts', 'id')->where(
                    fn ($q) => $q->where('org_id', CurrentOrg::id())->whereNull('deleted_at'),
                ),
            ],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json([
            'data' => $this->cashflow->generalLedger(
                (int) $data['account_id'],
                $data['from'] ?? null,
                $data['to'] ?? null,
            ),
        ]);
    }

    /** Cash flow — money in vs money out of Cash for a month. */
    public function cashFlow(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        $period = (string) $request->query('period', AccountingClock::now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = AccountingClock::now()->format('Y-m');
        }

        return response()->json(['data' => $this->cashflow->cashFlow($period)]);
    }

    /** VAT summary for a month — Output VAT − Input VAT = net VAT payable (BIR). */
    public function vat(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('accounting.reports.view'), 403);

        $period = (string) $request->query('period', AccountingClock::now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = AccountingClock::now()->format('Y-m');
        }

        return response()->json(['data' => $this->cashflow->vatReport($period)]);
    }
}
