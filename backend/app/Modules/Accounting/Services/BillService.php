<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Contact;
use App\Modules\Accounting\Support\AccountingClock;
use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillService
{
    public function __construct(
        private readonly JournalService $journal,
    ) {}

    /**
     * Record a bill with line items and VAT.
     * Posts: Dr Expense per account (subtotal) / Dr Input VAT (1200) / Cr A/P (total).
     */
    public function create(array $data, User $user): Bill
    {
        // Two concurrent creates can compute the same next bill_no; the unique
        // (org_id, bill_no) index rejects the loser, who retries the whole
        // transaction and takes the next number.
        return retry(
            3,
            fn (): Bill => $this->createOnce($data, $user),
            0,
            fn (\Throwable $e): bool => $e instanceof UniqueConstraintViolationException,
        );
    }

    private function createOnce(array $data, User $user): Bill
    {
        return DB::transaction(function () use ($data, $user): Bill {
            $orgId = CurrentOrg::id();
            $contact = Contact::query()->where('org_id', $orgId)->find($data['contact_id']);
            $vendorName = $contact?->name ?? 'Vendor';

            $lines = $this->normalizeLines($data['lines']);
            $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
            $taxRate = (float) ($data['tax_rate'] ?? 0);
            // Purchases are vatable (12% input VAT) or exempt (0%).
            $vatClass = ($data['vat_class'] ?? ($taxRate > 0 ? 'vatable' : 'exempt')) === 'vatable' ? 'vatable' : 'exempt';
            if ($vatClass !== 'vatable') {
                $taxRate = 0.0;
            }
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $total = round($subtotal + $taxAmount, 2);

            $no = 'BILL-' . str_pad(
                (string) (Bill::withTrashed()->where('org_id', $orgId)->count() + 1),
                4,
                '0',
                STR_PAD_LEFT,
            );

            $bill = Bill::create([
                'org_id' => $orgId,
                'contact_id' => $contact?->id,
                'bill_no' => $no,
                'vendor_name' => $vendorName,
                'issue_date' => $data['issue_date'],
                'due_date' => $data['due_date'] ?? null,
                'description' => $data['description'] ?? null,
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'vat_class' => $vatClass,
                'amount' => $total,
                'expense_account_id' => $lines[0]['account_id'],
                'status' => 'awaiting',
            ]);

            foreach ($lines as $line) {
                $bill->lines()->create($line);
            }

            $entryLines = [];
            foreach ($this->groupByAccount($lines) as $accountId => $amount) {
                $entryLines[] = ['account_id' => (int) $accountId, 'debit' => $amount];
            }
            if ($taxAmount > 0) {
                $entryLines[] = ['account_id' => $this->accountId('1200'), 'debit' => $taxAmount];
            }
            $entryLines[] = ['account_id' => $this->accountId('2000'), 'credit' => $total];

            $entry = $this->journal->create([
                'entry_date' => $data['issue_date'],
                'reference' => $no,
                'memo' => "Bill {$no} — {$vendorName}",
                'lines' => $entryLines,
            ]);
            $posted = $this->journal->post($entry, $user);

            $bill->update(['journal_entry_id' => $posted->id]);

            return $bill;
        });
    }

    /** Pay a bill: Dr A/P (total) / Cr Cash (total). */
    /**
     * Record a payment (full or partial) against a bill. Posts Dr A/P / Cr Cash
     * for the amount and advances status unpaid → partial → paid.
     */
    public function recordPayment(Bill $bill, float $amount, User $user): Bill
    {
        if ($bill->isPaid()) {
            return $bill;
        }

        $balance = round((float) $bill->amount - (float) $bill->amount_paid, 2);
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > $balance) {
            throw ValidationException::withMessages([
                'amount' => "Payment must be between 0.01 and the outstanding balance ({$balance}).",
            ]);
        }

        return DB::transaction(function () use ($bill, $amount, $user): Bill {
            $entry = $this->journal->create([
                'entry_date' => AccountingClock::today(),
                'reference' => $bill->bill_no . '-PMT',
                'memo' => "Bill paid — {$bill->bill_no}",
                'lines' => [
                    ['account_id' => $this->accountId('2000'), 'debit' => $amount],
                    ['account_id' => $this->accountId('1000'), 'credit' => $amount],
                ],
            ]);
            $posted = $this->journal->post($entry, $user);

            $newPaid = round((float) $bill->amount_paid + $amount, 2);
            $bill->update([
                'amount_paid' => $newPaid,
                'status' => $newPaid >= (float) $bill->amount ? 'paid' : 'partial',
                'payment_entry_id' => $posted->id,
            ]);

            return $bill;
        });
    }

    /** Pay the full outstanding balance. */
    public function markPaid(Bill $bill, User $user): Bill
    {
        $balance = round((float) $bill->amount - (float) $bill->amount_paid, 2);

        return $balance > 0 ? $this->recordPayment($bill, $balance, $user) : $bill;
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
