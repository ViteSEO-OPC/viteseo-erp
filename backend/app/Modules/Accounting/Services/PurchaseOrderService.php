<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\PurchaseOrder;
use App\Modules\Accounting\Support\AccountingClock;
use App\Support\CurrentOrg;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders: create the commitment, then (when the goods + bill arrive)
 * convert it to a real bill. Creating/cancelling a PO posts nothing to the
 * ledger; conversion delegates to BillService, which records Dr Expense / Cr A/P.
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly BillService $bills,
    ) {}

    /**
     * @param  array{contact_id:int, order_date:string, expected_date?:string|null, description?:string|null, tax_rate?:float, lines:array<int,array{description?:string|null,quantity?:float,unit_price?:float,account_id:int}>}  $data
     */
    public function create(array $data): PurchaseOrder
    {
        // Two concurrent creates can compute the same next po_no; the unique
        // (org_id, po_no) index rejects the loser, who retries the whole
        // transaction and takes the next number.
        return retry(
            3,
            fn (): PurchaseOrder => $this->createOnce($data),
            0,
            fn (\Throwable $e): bool => $e instanceof UniqueConstraintViolationException,
        );
    }

    /**
     * @param  array{contact_id:int, order_date:string, expected_date?:string|null, description?:string|null, tax_rate?:float, lines:array<int,array{description?:string|null,quantity?:float,unit_price?:float,account_id:int}>}  $data
     */
    private function createOnce(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $orgId = CurrentOrg::id();

            $lines = $this->normalizeLines($data['lines']);
            $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
            $taxRate = (float) ($data['tax_rate'] ?? 0);
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $total = round($subtotal + $taxAmount, 2);

            $no = 'PO-' . str_pad(
                (string) (PurchaseOrder::withTrashed()->where('org_id', $orgId)->count() + 1),
                4,
                '0',
                STR_PAD_LEFT,
            );

            $po = PurchaseOrder::create([
                'contact_id' => $data['contact_id'],
                'po_no' => $no,
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'description' => $data['description'] ?? null,
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'amount' => $total,
                'status' => PurchaseOrder::OPEN,
            ]);

            foreach ($lines as $line) {
                $po->lines()->create($line);
            }

            return $po->load('lines');
        });
    }

    /**
     * Convert an open PO into a bill (posts the AP entry) and mark it billed.
     * @param  array{issue_date?:string, due_date?:string|null}  $opts
     */
    public function convertToBill(PurchaseOrder $po, User $actor, array $opts = []): Bill
    {
        return DB::transaction(function () use ($po, $actor, $opts): Bill {
            // Lock + re-check inside the txn so two concurrent conversions can't
            // both pass the OPEN check and post two bills for one PO.
            $po = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== PurchaseOrder::OPEN) {
                throw ValidationException::withMessages([
                    'status' => 'Only an open purchase order can be converted to a bill.',
                ]);
            }
            $po->load('lines');

            $bill = $this->bills->create([
                'contact_id' => $po->contact_id,
                'issue_date' => $opts['issue_date'] ?? AccountingClock::today(),
                'due_date' => $opts['due_date'] ?? null,
                'description' => $po->description ?? "From {$po->po_no}",
                'tax_rate' => (float) $po->tax_rate,
                'lines' => $po->lines->map(fn ($l) => [
                    'description' => $l->description,
                    'quantity' => (float) $l->quantity,
                    'unit_price' => (float) $l->unit_price,
                    'account_id' => $l->account_id,
                ])->all(),
            ], $actor);

            $po->update(['status' => PurchaseOrder::BILLED, 'bill_id' => $bill->id]);

            return $bill;
        });
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status === PurchaseOrder::BILLED) {
            throw ValidationException::withMessages([
                'status' => 'A billed purchase order can’t be cancelled.',
            ]);
        }
        $po->update(['status' => PurchaseOrder::CANCELLED]);

        return $po;
    }

    /**
     * @param  array<int,array{description?:string|null,quantity?:float,unit_price?:float,account_id:int}>  $lines
     * @return array<int,array{description:string|null,quantity:float,unit_price:float,account_id:int,line_total:float}>
     */
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
}
