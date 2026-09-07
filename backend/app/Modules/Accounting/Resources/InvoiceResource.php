<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'customer_name' => $this->customer_name,
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
            'sent_at' => $this->sent_at?->toIso8601String(),
            // Seller identity for the letterhead (detail / print view only).
            'org' => $this->whenLoaded('org', fn () => [
                'name' => $this->org?->name,
                'tin' => $this->org?->tin,
                'address' => $this->org?->address,
                'is_vat_registered' => (bool) $this->org?->is_vat_registered,
            ]),
            'contact_email' => $this->whenLoaded('contact', fn () => $this->contact?->email),
            'contact_phone' => $this->whenLoaded('contact', fn () => $this->contact?->phone),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'account_id' => $l->account_id,
                'account_name' => $l->account?->name,
                'line_total' => (float) $l->line_total,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
