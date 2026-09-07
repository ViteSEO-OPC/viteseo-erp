<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Models\Bill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Bill
 */
class BillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bill_no' => $this->bill_no,
            'vendor_name' => $this->vendor_name,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'description' => $this->description,
            'subtotal' => (float) $this->subtotal,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'vat_class' => $this->vat_class,
            'amount' => (float) $this->amount,
            'amount_paid' => (float) $this->amount_paid,
            'status' => $this->status,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'account_id' => $l->account_id,
                'line_total' => (float) $l->line_total,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
