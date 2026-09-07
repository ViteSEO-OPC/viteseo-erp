<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Support\AccountingClock;
use App\Support\CurrentOrg;
use App\Support\Entitlements;

/**
 * The counts behind Accounting's own notification bell — "what needs money
 * moved today". Deliberately tiny: the bell polls it every minute.
 *
 * It does NOT re-define what "overdue" means; it reuses the aged
 * receivables/payables report so the bell and the reports can never disagree
 * (same unpaid rule, same outstanding-balance math, same Manila day boundary).
 *
 * Entitlement-aware by design: invoices belong to `accounting.invoicing` and
 * bills to `accounting.bills`. A key is null — not 0 — when the org hasn't
 * availed that function, so the UI can tell "not availed" from "all clear".
 */
class AlertsService
{
    /** A bill counts as "due soon" within this many days of today. */
    public const DUE_SOON_DAYS = 7;

    public function __construct(
        private readonly CashflowReportService $cashflow,
    ) {}

    /**
     * @return array{
     *     as_of: string,
     *     due_soon_days: int,
     *     overdue_invoices: int|null,
     *     overdue_invoice_amount: float|null,
     *     overdue_bills: int|null,
     *     overdue_bill_amount: float|null,
     *     bills_due_soon: int|null,
     * }
     */
    public function forCurrentOrg(): array
    {
        $orgId = CurrentOrg::id();

        $payload = [
            'as_of' => AccountingClock::today(),
            'due_soon_days' => self::DUE_SOON_DAYS,
            'overdue_invoices' => null,
            'overdue_invoice_amount' => null,
            'overdue_bills' => null,
            'overdue_bill_amount' => null,
            'bills_due_soon' => null,
        ];

        if ($orgId === null) {
            return $payload;
        }

        if (Entitlements::has($orgId, 'accounting.invoicing')) {
            $overdue = $this->overdue($this->cashflow->agedReceivables()['items']);

            $payload['overdue_invoices'] = count($overdue);
            $payload['overdue_invoice_amount'] = $this->sum($overdue);
        }

        if (Entitlements::has($orgId, 'accounting.bills')) {
            $items = $this->cashflow->agedPayables()['items'];
            $overdue = $this->overdue($items);
            $cutoff = AccountingClock::now()->addDays(self::DUE_SOON_DAYS)->toDateString();

            // Not yet overdue, but landing inside the window. Y-m-d strings
            // compare correctly lexicographically, so no TZ maths is needed.
            $dueSoon = array_filter(
                $items,
                fn (array $i): bool => (int) $i['days_overdue'] === 0
                    && $i['due_date'] !== null
                    && $i['due_date'] <= $cutoff,
            );

            $payload['overdue_bills'] = count($overdue);
            $payload['overdue_bill_amount'] = $this->sum($overdue);
            $payload['bills_due_soon'] = count($dueSoon);
        }

        return $payload;
    }

    /**
     * @param  list<array{days_overdue:int, amount:float, due_date:string|null}>  $items
     * @return list<array{days_overdue:int, amount:float, due_date:string|null}>
     */
    private function overdue(array $items): array
    {
        return array_values(array_filter($items, fn (array $i): bool => (int) $i['days_overdue'] > 0));
    }

    /** @param  list<array{amount:float}>  $items */
    private function sum(array $items): float
    {
        return round(array_sum(array_column($items, 'amount')), 2);
    }
}
