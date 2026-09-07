<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Contact;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Support\AccountingClock;
use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CashflowReportService
{
    /** Aged receivables: unpaid invoices bucketed by how overdue they are. */
    public function agedReceivables(): array
    {
        $today = Carbon::parse(AccountingClock::today())->startOfDay();
        $invoices = Invoice::query()
            ->where('org_id', CurrentOrg::id())
            ->where('status', '!=', 'paid')
            ->orderBy('due_date')
            ->get();

        $items = [];
        $buckets = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'over_90' => 0.0];

        foreach ($invoices as $inv) {
            $due = $inv->due_date ?? $inv->issue_date;
            $overdue = $due && $due->lt($today) ? (int) $due->diffInDays($today) : 0;
            // Outstanding balance, not the full invoice — a partial payment must
            // reduce the aged amount (matches the Statement of Account).
            $amount = round((float) $inv->amount - (float) ($inv->amount_paid ?? 0), 2);
            $buckets[$this->bucketKey($overdue)] += $amount;

            $items[] = [
                'ref' => $inv->invoice_no,
                'name' => $inv->customer_name,
                'due_date' => $due?->toDateString(),
                'days_overdue' => $overdue,
                'amount' => round($amount, 2),
            ];
        }

        return $this->agedPayload($items, $buckets);
    }

    /** Aged payables: unpaid bills bucketed by how overdue they are. */
    public function agedPayables(): array
    {
        $today = Carbon::parse(AccountingClock::today())->startOfDay();
        $bills = Bill::query()
            ->where('org_id', CurrentOrg::id())
            ->where('status', '!=', 'paid')
            ->orderBy('due_date')
            ->get();

        $items = [];
        $buckets = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'over_90' => 0.0];

        foreach ($bills as $bill) {
            $due = $bill->due_date ?? $bill->issue_date;
            $overdue = $due && $due->lt($today) ? (int) $due->diffInDays($today) : 0;
            // Outstanding balance, not the full bill (partial payments reduce it).
            $amount = round((float) $bill->amount - (float) ($bill->amount_paid ?? 0), 2);
            $buckets[$this->bucketKey($overdue)] += $amount;

            $items[] = [
                'ref' => $bill->bill_no,
                'name' => $bill->vendor_name,
                'due_date' => $due?->toDateString(),
                'days_overdue' => $overdue,
                'amount' => round($amount, 2),
            ];
        }

        return $this->agedPayload($items, $buckets);
    }

    /** General ledger: every posted line for one account, with a running balance. */
    public function generalLedger(int $accountId, ?string $from, ?string $to): array
    {
        $account = Account::query()->where('org_id', CurrentOrg::id())->find($accountId);
        if (! $account) {
            return ['account' => null, 'lines' => [], 'closing_balance' => 0];
        }

        $query = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.org_id', CurrentOrg::id())
            ->where('e.status', 'posted')
            ->whereNull('e.deleted_at')
            ->where('l.account_id', $accountId);

        if ($from) {
            $query->where('e.entry_date', '>=', $from);
        }
        if ($to) {
            $query->where('e.entry_date', '<=', $to);
        }

        $rows = $query
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->select('e.entry_date', 'e.reference', 'e.memo', 'l.debit', 'l.credit')
            ->get();

        $debitNormal = in_array($account->type->value, ['asset', 'expense'], true);
        $balance = 0.0;
        $lines = [];

        foreach ($rows as $r) {
            $debit = (float) $r->debit;
            $credit = (float) $r->credit;
            $balance += $debitNormal ? ($debit - $credit) : ($credit - $debit);

            $lines[] = [
                'date' => $r->entry_date,
                'reference' => $r->reference,
                'memo' => $r->memo,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'balance' => round($balance, 2),
            ];
        }

        return [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
            ],
            'lines' => $lines,
            'closing_balance' => round($balance, 2),
        ];
    }

    /** Cash flow for a month: money in vs money out of the Cash account (1000). */
    public function cashFlow(string $period): array
    {
        $start = Carbon::parse($period . '-01')->startOfMonth();
        $end = $start->copy()->addMonth();
        $cashId = Account::query()->where('org_id', CurrentOrg::id())->where('code', '1000')->value('id');

        // Opening cash = every posted cash line BEFORE the period start.
        $before = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.org_id', CurrentOrg::id())
            ->where('e.status', 'posted')
            ->whereNull('e.deleted_at')
            ->where('l.account_id', $cashId)
            ->where('e.entry_date', '<', $start->toDateString())
            ->selectRaw('COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c')
            ->first();
        $opening = round((float) ($before->d ?? 0) - (float) ($before->c ?? 0), 2);

        // Every cash movement IN the period — the detail the report was missing.
        $rows = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.org_id', CurrentOrg::id())
            ->where('e.status', 'posted')
            ->whereNull('e.deleted_at')
            ->where('l.account_id', $cashId)
            ->where('e.entry_date', '>=', $start->toDateString())
            ->where('e.entry_date', '<', $end->toDateString())
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->get(['e.entry_date', 'e.reference', 'e.memo', 'l.memo as line_memo', 'l.debit', 'l.credit']);

        $inflow = 0.0;
        $outflow = 0.0;
        $running = $opening;
        $lines = [];
        foreach ($rows as $r) {
            $in = (float) $r->debit;
            $out = (float) $r->credit;
            $inflow += $in;
            $outflow += $out;
            $running = round($running + $in - $out, 2);
            $lines[] = [
                'date' => $r->entry_date,
                'reference' => $r->reference,
                'description' => $r->line_memo ?: $r->memo,
                'inflow' => round($in, 2),
                'outflow' => round($out, 2),
                'balance' => $running,
            ];
        }

        return [
            'period' => $period,
            'opening_balance' => $opening,
            'inflow' => round($inflow, 2),
            'outflow' => round($outflow, 2),
            'net' => round($inflow - $outflow, 2),
            'closing_balance' => round($opening + $inflow - $outflow, 2),
            'lines' => $lines,
        ];
    }

    /**
     * VAT summary for a month (PH BIR): Output VAT (on sales) minus Input VAT
     * (on purchases) = net VAT payable to the BIR (or creditable if negative).
     */
    public function vatReport(string $period): array
    {
        $start = Carbon::parse($period . '-01')->startOfMonth();
        $end = $start->copy()->addMonth();

        // Output VAT — liability account 2140, credit-normal (VAT collected on sales).
        $out = $this->accountMovement('2140', $start->toDateString(), $end->toDateString());
        $outputVat = round($out['credit'] - $out['debit'], 2);

        // Input VAT — asset account 1200, debit-normal (VAT paid on purchases).
        $in = $this->accountMovement('1200', $start->toDateString(), $end->toDateString());
        $inputVat = round($in['debit'] - $in['credit'], 2);

        return [
            'period' => $period,
            'output_vat' => $outputVat,
            'input_vat' => $inputVat,
            'net_vat_payable' => round($outputVat - $inputVat, 2),
        ];
    }

    /**
     * @return array{debit: float, credit: float}
     */
    /**
     * BIR Form 2550Q — Quarterly VAT return figures. Output VAT (on sales) less
     * Input VAT (on purchases) = VAT payable, laid out as the form organizes it,
     * with a per-month breakdown. These are the figures to transcribe into
     * eBIRForms — not an official filable government form.
     *
     * @return array<string, mixed>
     */
    public function birVat2550Q(int $year, int $quarter): array
    {
        $startMonth = ($quarter - 1) * 3 + 1;
        $start = Carbon::create($year, $startMonth, 1)->startOfDay();
        $end = $start->copy()->addMonths(3); // exclusive

        // Taxable base from source documents (invoices = sales, bills = purchases).
        $sales = Invoice::query()
            ->where('org_id', CurrentOrg::id())
            ->whereBetween('issue_date', [$start->toDateString(), $end->copy()->subDay()->toDateString()])
            ->get(['subtotal', 'tax_amount', 'vat_class']);
        $purchases = Bill::query()
            ->where('org_id', CurrentOrg::id())
            ->whereBetween('issue_date', [$start->toDateString(), $end->copy()->subDay()->toDateString()])
            ->get(['subtotal', 'tax_amount', 'vat_class']);

        $vatableSales = round($sales->where('vat_class', 'vatable')->sum('subtotal'), 2);
        $zeroRatedSales = round($sales->where('vat_class', 'zero_rated')->sum('subtotal'), 2);
        $exemptSales = round($sales->where('vat_class', 'exempt')->sum('subtotal'), 2);
        $vatablePurchases = round($purchases->where('vat_class', 'vatable')->sum('subtotal'), 2);
        $exemptPurchases = round($purchases->where('vat_class', '!=', 'vatable')->sum('subtotal'), 2);

        // VAT amounts from the ledger (authoritative, double-entry). NET movement
        // (credit−debit on 2140, debit−credit on 1200) is intentional: it lets a
        // reversed invoice/bill correctly REDUCE the period's VAT. When a VAT
        // remittance-to-BIR posting is added later, exclude that entry type here
        // so a payment of the liability doesn't understate output tax.
        $outMove = $this->accountMovement('2140', $start->toDateString(), $end->toDateString());
        $inMove = $this->accountMovement('1200', $start->toDateString(), $end->toDateString());
        $outputVat = round($outMove['credit'] - $outMove['debit'], 2);
        $inputVat = round($inMove['debit'] - $inMove['credit'], 2);
        $net = round($outputVat - $inputVat, 2);

        // Per-month breakdown (informational). VAT is filed & remitted quarterly
        // on 2550Q — monthly 2550M was abolished under TRAIN (2023).
        $months = [];
        for ($i = 0; $i < 3; $i++) {
            $mStart = $start->copy()->addMonths($i);
            $mEnd = $mStart->copy()->addMonth();
            $mOut = $this->accountMovement('2140', $mStart->toDateString(), $mEnd->toDateString());
            $mIn = $this->accountMovement('1200', $mStart->toDateString(), $mEnd->toDateString());
            $months[] = [
                'month' => $mStart->format('Y-m'),
                'label' => $mStart->format('F Y'),
                'output_vat' => round($mOut['credit'] - $mOut['debit'], 2),
                'input_vat' => round($mIn['debit'] - $mIn['credit'], 2),
            ];
        }

        return [
            'year' => $year,
            'quarter' => $quarter,
            'period_label' => "Q{$quarter} {$year} ({$start->format('M')}–{$end->copy()->subDay()->format('M Y')})",
            'sales' => [
                'vatable' => $vatableSales,
                'zero_rated' => $zeroRatedSales,
                'exempt' => $exemptSales,
                'output_vat' => $outputVat,
            ],
            'purchases' => [
                'vatable' => $vatablePurchases,
                'exempt' => $exemptPurchases,
                'input_vat' => $inputVat,
            ],
            'net_vat_payable' => $net,          // positive = payable, negative = excess input (carry over)
            'is_payable' => $net >= 0,
            'months' => $months,
        ];
    }

    /**
     * BIR Summary List of Sales (SLSP) — per-customer sales for the quarter with
     * their TIN, taxable/exempt amounts and output VAT (data for the SLSP / the
     * BIR alphalist submission — not the alphalist file itself).
     *
     * @return array<string, mixed>
     */
    public function summaryListOfSales(int $year, int $quarter): array
    {
        [$start, $endInclusive] = $this->quarterBounds($year, $quarter);

        $rows = $this->groupByContact(
            Invoice::query()
                ->where('org_id', CurrentOrg::id())
                ->whereBetween('issue_date', [$start, $endInclusive])
                ->with('contact:id,name,tin,address')
                ->get()
        );

        return ['year' => $year, 'quarter' => $quarter, 'rows' => $rows, 'totals' => $this->rowTotals($rows)];
    }

    /**
     * BIR Summary List of Purchases (SLSP) — per-supplier purchases for the
     * quarter with their TIN, taxable/exempt amounts and input VAT.
     *
     * @return array<string, mixed>
     */
    public function summaryListOfPurchases(int $year, int $quarter): array
    {
        [$start, $endInclusive] = $this->quarterBounds($year, $quarter);

        $rows = $this->groupByContact(
            Bill::query()
                ->where('org_id', CurrentOrg::id())
                ->whereBetween('issue_date', [$start, $endInclusive])
                ->with('contact:id,name,tin,address')
                ->get()
        );

        return ['year' => $year, 'quarter' => $quarter, 'rows' => $rows, 'totals' => $this->rowTotals($rows)];
    }

    /** @return array{0:string,1:string} [startDate, endInclusiveDate] */
    private function quarterBounds(int $year, int $quarter): array
    {
        $start = Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();

        return [$start->toDateString(), $start->copy()->addMonths(3)->subDay()->toDateString()];
    }

    /**
     * Group invoice/bill documents by contact into SLSP rows.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $docs
     * @return array<int, array<string, mixed>>
     */
    private function groupByContact($docs): array
    {
        $byContact = [];
        foreach ($docs as $doc) {
            $cid = $doc->contact_id ?? 0;
            if (! isset($byContact[$cid])) {
                $byContact[$cid] = [
                    'contact_id' => $cid,
                    'name' => $doc->contact?->name ?? 'Unknown',
                    'tin' => $doc->contact?->tin,
                    'address' => $doc->contact?->address,
                    'vatable' => 0.0,
                    'zero_rated' => 0.0,
                    'exempt' => 0.0,
                    'vat' => 0.0,
                    'gross' => 0.0,
                ];
            }
            $class = $doc->vat_class ?? ((float) $doc->tax_amount > 0 ? 'vatable' : 'exempt');
            $subtotal = (float) $doc->subtotal;
            $byContact[$cid]['vatable'] += $class === 'vatable' ? $subtotal : 0.0;
            $byContact[$cid]['zero_rated'] += $class === 'zero_rated' ? $subtotal : 0.0;
            $byContact[$cid]['exempt'] += ($class !== 'vatable' && $class !== 'zero_rated') ? $subtotal : 0.0;
            $byContact[$cid]['vat'] += (float) $doc->tax_amount;
            $byContact[$cid]['gross'] += (float) $doc->amount;
        }

        $rows = array_map(function ($r) {
            foreach (['vatable', 'zero_rated', 'exempt', 'vat', 'gross'] as $k) {
                $r[$k] = round($r[$k], 2);
            }

            return $r;
        }, array_values($byContact));

        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function rowTotals(array $rows): array
    {
        return [
            'vatable' => round(array_sum(array_column($rows, 'vatable')), 2),
            'zero_rated' => round(array_sum(array_column($rows, 'zero_rated')), 2),
            'exempt' => round(array_sum(array_column($rows, 'exempt')), 2),
            'vat' => round(array_sum(array_column($rows, 'vat')), 2),
            'gross' => round(array_sum(array_column($rows, 'gross')), 2),
        ];
    }

    private function accountMovement(string $code, string $start, string $endExclusive): array
    {
        $accountId = Account::query()
            ->where('org_id', CurrentOrg::id())
            ->where('code', $code)
            ->value('id');

        if (! $accountId) {
            return ['debit' => 0.0, 'credit' => 0.0];
        }

        $r = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.org_id', CurrentOrg::id())
            ->where('e.status', 'posted')
            ->whereNull('e.deleted_at')
            ->where('l.account_id', $accountId)
            ->where('e.entry_date', '>=', $start)
            ->where('e.entry_date', '<', $endExclusive)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as debit, COALESCE(SUM(l.credit), 0) as credit')
            ->first();

        return ['debit' => (float) ($r->debit ?? 0), 'credit' => (float) ($r->credit ?? 0)];
    }

    /**
     * Statement of Account for one customer: every invoice issued on/before
     * `asOf`, with what's been paid and what's still due, plus running totals and
     * an aging breakdown of the outstanding balance. Open-item style — handles
     * partial payments (balance_due = amount − amount_paid).
     *
     * @return array<string, mixed>
     */
    public function statementOfAccount(int $contactId, ?string $asOf = null): array
    {
        $asOfDate = $asOf ? Carbon::parse($asOf)->endOfDay() : Carbon::parse(AccountingClock::today())->endOfDay();

        // Org-scoped via BelongsToOrg; 404 if the contact isn't in this org.
        $contact = Contact::query()->findOrFail($contactId);

        $invoices = Invoice::query()
            ->where('contact_id', $contactId)
            ->whereDate('issue_date', '<=', $asOfDate)
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        $lines = [];
        $billed = 0.0;
        $paid = 0.0;
        $outstanding = 0.0;
        $buckets = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'over_90' => 0.0];

        foreach ($invoices as $inv) {
            $amount = (float) $inv->amount;
            $amountPaid = (float) ($inv->amount_paid ?? 0);
            $balance = round($amount - $amountPaid, 2);

            $billed += $amount;
            $paid += $amountPaid;
            $outstanding += $balance;

            $due = $inv->due_date ?? $inv->issue_date;
            $overdue = ($balance > 0.005 && $due && $due->lt($asOfDate)) ? (int) $due->diffInDays($asOfDate) : 0;
            if ($balance > 0.005) {
                $buckets[$this->bucketKey($overdue)] += $balance;
            }

            $lines[] = [
                'invoice_no' => $inv->invoice_no,
                'issue_date' => $inv->issue_date?->toDateString(),
                'due_date' => $due?->toDateString(),
                'description' => $inv->description,
                'amount' => round($amount, 2),
                'amount_paid' => round($amountPaid, 2),
                'balance_due' => $balance,
                'status' => $inv->status,
                'days_overdue' => $overdue,
            ];
        }

        return [
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
            ],
            'as_of' => $asOfDate->toDateString(),
            'lines' => $lines,
            'totals' => [
                'billed' => round($billed, 2),
                'paid' => round($paid, 2),
                'outstanding' => round($outstanding, 2),
            ],
            'aging' => array_map(fn ($v) => round($v, 2), $buckets),
        ];
    }

    private function bucketKey(int $overdue): string
    {
        return match (true) {
            $overdue <= 0 => 'current',
            $overdue <= 30 => 'd1_30',
            $overdue <= 60 => 'd31_60',
            $overdue <= 90 => 'd61_90',
            default => 'over_90',
        };
    }

    private function agedPayload(array $items, array $buckets): array
    {
        return [
            'items' => $items,
            'buckets' => array_map(fn ($v) => round($v, 2), $buckets),
            'total' => round(array_sum($buckets), 2),
        ];
    }
}
