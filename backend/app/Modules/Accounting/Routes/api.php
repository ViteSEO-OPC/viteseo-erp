<?php

declare(strict_types=1);

use App\Modules\Accounting\Controllers\AccountController;
use App\Modules\Accounting\Controllers\AlertsController;
use App\Modules\Accounting\Controllers\AttachmentController;
use App\Modules\Accounting\Controllers\BankReconciliationController;
use App\Modules\Accounting\Controllers\BillController;
use App\Modules\Accounting\Controllers\BudgetController;
use App\Modules\Accounting\Controllers\ContactController;
use App\Modules\Accounting\Controllers\InvoiceController;
use App\Modules\Accounting\Controllers\JournalEntryController;
use App\Modules\Accounting\Controllers\OrgSettingsController;
use App\Modules\Accounting\Controllers\PayrollPostingController;
use App\Modules\Accounting\Controllers\PurchaseOrderController;
use App\Modules\Accounting\Controllers\RecurringInvoiceController;
use App\Modules\Accounting\Controllers\ReportController;
use App\Modules\Accounting\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// URLs resolve to /api/v1/accounting/... (the `api` prefix + middleware are
// added by AccountingServiceProvider).
// SEC-RL — the module's rate-limit FLOOR, mirroring HR (`hr-api:` / `hr-api-hourly:`). All 60
// Accounting routes previously had no limiter of any kind.
//
// TWO WINDOWS: 120/min bounds a burst, 2000/hour bounds a grind — a per-minute limit alone
// permits 119 requests a minute forever, which is a patient walk of the general ledger.
//
// BOTH PREFIXES ARE REQUIRED. An authenticated request is keyed on `sha1($user->id)` with the
// route playing no part, so an unprefixed limiter here would share one counter with every other
// unprefixed `throttle:` the account touches (see the HR payroll note — that is how a 5/min
// write limit came to be enforced against ordinary page loads).
Route::prefix('v1/accounting')->middleware(['auth:sanctum', 'set-current-org', 'subscription-active', 'feature:accounting', 'throttle:120,1,accounting-api:', 'throttle:2000,60,accounting-api-hourly:'])->group(function (): void {
    // Chart of accounts
    Route::get('accounts', [AccountController::class, 'index']);
    Route::post('accounts', [AccountController::class, 'store']);
    Route::put('accounts/{account}', [AccountController::class, 'update']);
    Route::delete('accounts/{account}', [AccountController::class, 'destroy']);

    // Journal entries (double-entry). Posted entries are immutable.
    Route::get('journal-entries', [JournalEntryController::class, 'index']);
    Route::post('journal-entries', [JournalEntryController::class, 'store']);
    Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post']);
    Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse']);

    // Global top-bar search (invoices / bills / contacts / chart of accounts).
    // NOT feature-gated on purpose — SearchService drops the groups the org
    // hasn't availed or the user can't read, so search never 403s wholesale.
    Route::get('search', [SearchController::class, 'index']);

    // Contacts (customers / vendors)
    Route::get('contacts', [ContactController::class, 'index']);
    Route::post('contacts', [ContactController::class, 'store']);

    // Org identity (invoice letterhead + VAT defaults). Any member can read
    // (invoices prefill from it); only the Finance Admin can change it.
    Route::get('org-settings', [OrgSettingsController::class, 'show']);
    Route::put('org-settings', [OrgSettingsController::class, 'update']);

    // Bank reconciliation — match a cash/bank account against the statement.
    Route::get('bank-reconciliations', [BankReconciliationController::class, 'index']);
    Route::get('bank-reconciliations/worksheet', [BankReconciliationController::class, 'worksheet']);
    Route::post('bank-reconciliations', [BankReconciliationController::class, 'store']);

    // Attachments (receipts / documents on invoices & bills)
    Route::get('attachments', [AttachmentController::class, 'index']);
    Route::post('attachments', [AttachmentController::class, 'store']);
    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download']);

    // Invoicing (Accounts Receivable) — gated by the availed feature
    Route::middleware('feature:accounting.invoicing')->group(function (): void {
        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::post('invoices', [InvoiceController::class, 'store']);
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay']);
        Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send']);

        // Recurring invoices — schedules that auto-generate invoices per period.
        Route::get('recurring-invoices', [RecurringInvoiceController::class, 'index']);
        Route::post('recurring-invoices', [RecurringInvoiceController::class, 'store']);
        Route::post('recurring-invoices/run', [RecurringInvoiceController::class, 'run']);
        Route::post('recurring-invoices/{recurringInvoice}/pause', [RecurringInvoiceController::class, 'pause']);
        Route::post('recurring-invoices/{recurringInvoice}/resume', [RecurringInvoiceController::class, 'resume']);
        Route::delete('recurring-invoices/{recurringInvoice}', [RecurringInvoiceController::class, 'destroy']);
    });

    // Bills (Accounts Payable) — gated
    Route::middleware('feature:accounting.bills')->group(function (): void {
        Route::get('bills', [BillController::class, 'index']);
        Route::post('bills', [BillController::class, 'store']);
        Route::post('bills/{bill}/pay', [BillController::class, 'pay']);

        // Purchase orders — commitments that convert into bills.
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::post('purchase-orders/{purchaseOrder}/convert-to-bill', [PurchaseOrderController::class, 'convertToBill']);
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
        Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);
    });

    // HRIS payroll → ledger — available when the org also has the HR module,
    // and only while the Payroll→Accounting connection is switched on. Without
    // the hr gate an accounting-only tenant would see the shared (un-scoped)
    // HR payroll tables.
    Route::middleware(['feature:accounting.payroll_posting', 'feature:hr', 'connection:payroll_accounting'])->group(function (): void {
        Route::get('payroll-runs', [PayrollPostingController::class, 'runs']);
        Route::post('payroll-runs/{run}/post-to-ledger', [PayrollPostingController::class, 'post']);
        Route::post('payroll-import', [PayrollPostingController::class, 'import']);
    });

    // Budgets (budget vs actual per month) — gated
    Route::middleware('feature:accounting.budgeting')->group(function (): void {
        Route::get('budgets', [BudgetController::class, 'index']);
        Route::put('budgets', [BudgetController::class, 'upsert']);
    });

    // Reports
    //
    // SEC-RL — all twelve carry `throttle:20,1,accounting-reports:`, HR's number for the same
    // shape of work. Each one aggregates over the full journal-line history for a period and
    // serializes the result; the statutory forms (BIR 2550Q, SLSP) are the heaviest reads in the
    // platform. At the group's 120/min a single account is a database CPU DoS, and the general
    // ledger is also the complete financial record of the tenant — the export a competitor wants.
    //
    // ONE SHARED `accounting-reports:` COUNTER on purpose: these are alternate views of the same
    // work, and a real accountant pulling a month-end pack moves between them. The prefix is what
    // stops that budget being shared with unrelated requests — it is not optional, see the group
    // note at the top of this file.
    Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->middleware('throttle:20,1,accounting-reports:');
    Route::get('reports/profit-and-loss', [ReportController::class, 'profitAndLoss'])->middleware('throttle:20,1,accounting-reports:');
    Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware('throttle:20,1,accounting-reports:');
    Route::get('reports/aged-receivables', [ReportController::class, 'agedReceivables'])->middleware(['feature:accounting.invoicing', 'throttle:20,1,accounting-reports:']);
    Route::get('reports/statement-of-account', [ReportController::class, 'statementOfAccount'])->middleware(['feature:accounting.invoicing', 'throttle:20,1,accounting-reports:']);
    Route::get('reports/aged-payables', [ReportController::class, 'agedPayables'])->middleware(['feature:accounting.bills', 'throttle:20,1,accounting-reports:']);
    Route::get('reports/general-ledger', [ReportController::class, 'generalLedger'])->middleware('throttle:20,1,accounting-reports:');
    Route::get('reports/cash-flow', [ReportController::class, 'cashFlow'])->middleware('throttle:20,1,accounting-reports:');
    Route::get('reports/vat', [ReportController::class, 'vat'])->middleware(['feature:accounting.tax_vat', 'throttle:20,1,accounting-reports:']);
    Route::get('reports/bir-2550q', [ReportController::class, 'birVat2550Q'])->middleware(['feature:accounting.tax_vat', 'throttle:20,1,accounting-reports:']);
    Route::get('reports/slsp-sales', [ReportController::class, 'summaryListOfSales'])->middleware(['feature:accounting.tax_vat', 'throttle:20,1,accounting-reports:']);
    Route::get('reports/slsp-purchases', [ReportController::class, 'summaryListOfPurchases'])->middleware(['feature:accounting.tax_vat', 'throttle:20,1,accounting-reports:']);

    // Notification bell counts (overdue AR / AP, bills due this week). Not
    // feature-gated as a whole — the entitlement check is per-section inside
    // the service, so a bills-only org still gets its bill counts.
    Route::get('alerts', [AlertsController::class, 'index']);
});
