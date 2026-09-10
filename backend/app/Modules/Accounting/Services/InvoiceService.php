<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Contact;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Support\AccountingClock;
use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly JournalService $journal,
    ) {}

    /**
     * Create + issue an invoice with line items and VAT.
     * Posts: Dr A/R (total) / Cr Income per account (subtotal) / Cr Output VAT (2140).
     */
    public function create(array $data, User $user): Invoice
    {
        // Two concurrent creates can compute the same next invoice_no; the
        // unique (org_id, invoice_no) index rejects the loser, who retries the
        // whole transaction and takes the next number.
        return retry(
            3,
            fn (): Invoice => $this->createOnce($data, $user),
            0,
            fn (\Throwable $e): bool => $e instanceof UniqueConstraintViolationException,
        );
    }

    private function createOnce(array $data, User $user): Invoice
    {
        return DB::transaction(function () use ($data, $user): Invoice {
            $orgId = CurrentOrg::id();
            $contact = Contact::query()->where('org_id', $orgId)->find($data['contact_id']);
            $customerName = $contact?->name ?? 'Customer';

            $lines = $this->normalizeLines($data['lines']);
            $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
            $taxRate = (float) ($data['tax_rate'] ?? 0);
            // VAT class drives whether tax applies: only "vatable" charges VAT;
            // zero-rated and exempt are always 0%.
            $vatClass = $data['vat_class'] ?? ($taxRate > 0 ? 'vatable' : 'exempt');
            if ($vatClass !== 'vatable') {
                $taxRate = 0.0;
            }
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $total = round($subtotal + $taxAmount, 2);

            $no = 'INV-' . str_pad(
                (string) (Invoice::withTrashed()->where('org_id', $orgId)->count() + 1),
                4,
                '0',
                STR_PAD_LEFT,
            );

            $invoice = Invoice::create([
                'org_id' => $orgId,
                'contact_id' => $contact?->id,
                'invoice_no' => $no,
                'customer_name' => $customerName,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'description' => $data['description'] ?? null,
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'vat_class' => $vatClass,
                'amount' => $total,
                'income_account_id' => $lines[0]['account_id'],
                // Raised, not sent. `send()` is what sets 'sent' and stamps
                // sent_at; a screen that never calls it must not claim delivery.
                // Matches BillService, which has always used 'awaiting'.
                'status' => 'awaiting',
            ]);

            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }

            $entryLines = [['account_id' => $this->accountId('1100'), 'debit' => $total]];
            foreach ($this->groupByAccount($lines) as $accountId => $amount) {
                $entryLines[] = ['account_id' => (int) $accountId, 'credit' => $amount];
            }
            if ($taxAmount > 0) {
                $entryLines[] = ['account_id' => $this->accountId('2140'), 'credit' => $taxAmount];
            }

            $entry = $this->journal->create([
                'entry_date' => $data['issue_date'],
                'reference' => $no,
                'memo' => "Invoice {$no} — {$customerName}",
                'lines' => $entryLines,
            ]);
            $posted = $this->journal->post($entry, $user);

            $invoice->update(['journal_entry_id' => $posted->id]);

            return $invoice;
        });
    }

    /** Record payment: Dr Cash (total) / Cr A/R (total). */
    /**
     * Record a payment (full or partial) against an invoice. Posts Dr Cash /
     * Cr A/R for the amount and advances status unpaid → partial → paid.
     */
    public function recordPayment(Invoice $invoice, float $amount, User $user): Invoice
    {
        if ($invoice->isPaid()) {
            return $invoice;
        }

        $balance = round((float) $invoice->amount - (float) $invoice->amount_paid, 2);
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $balance) {
            throw ValidationException::withMessages([
                'amount' => "Payment must be between 0.01 and the outstanding balance ({$balance}).",
            ]);
        }

        return DB::transaction(function () use ($invoice, $amount, $user): Invoice {
            $entry = $this->journal->create([
                'entry_date' => AccountingClock::today(),
                'reference' => $invoice->invoice_no . '-PMT',
                'memo' => "Payment received — {$invoice->invoice_no}",
                'lines' => [
                    ['account_id' => $this->accountId('1000'), 'debit' => $amount],
                    ['account_id' => $this->accountId('1100'), 'credit' => $amount],
                ],
            ]);
            $posted = $this->journal->post($entry, $user);

            $newPaid = round((float) $invoice->amount_paid + $amount, 2);
            $invoice->update([
                'amount_paid' => $newPaid,
                'status' => $newPaid >= (float) $invoice->amount ? 'paid' : 'partial',
                'payment_entry_id' => $posted->id,
            ]);

            return $invoice;
        });
    }

    /** Pay the full outstanding balance. */
    public function markPaid(Invoice $invoice, User $user): Invoice
    {
        $balance = round((float) $invoice->amount - (float) $invoice->amount_paid, 2);

        return $balance > 0 ? $this->recordPayment($invoice, $balance, $user) : $invoice;
    }

    /** @return array<int, array<string, mixed>> */
    private function normalizeLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $qty = (float) ($line['quantity'] ?? 1);
            $price = (float) ($line['unit_price'] ?? 0);
            $out[] = [
                'description' => $line['description'] ?? null,
                'quantity' => $qty,
                'unit_price' => $price,
                'account_id' => (int) $line['account_id'],
                'line_total' => round($qty * $price, 2),
            ];
        }

        return $out;
    }

    /** @return array<int, float> account_id => summed line total */
    private function groupByAccount(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $id = (int) $line['account_id'];
            $out[$id] = round(($out[$id] ?? 0) + $line['line_total'], 2);
        }

        return $out;
    }

    private function accountId(string $code): int
    {
        $id = Account::query()
            ->where('org_id', CurrentOrg::id())
            ->where('code', $code)
            ->value('id');

        if ($id === null) {
            throw ValidationException::withMessages([
                'account' => "Missing account {$code} in the chart of accounts.",
            ]);
        }

        return (int) $id;
    }
}
