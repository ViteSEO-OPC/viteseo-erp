<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One transfer, as HR sees it.
 *
 * THE ACCOUNT NUMBER IS MASKED AND THERE IS NO FLAG TO UNMASK IT. Nothing on a payments screen
 * needs the full number: identifying which account was used takes four digits, and the one place
 * the whole thing is legitimately required — the file handed to the bank — reads it from the model
 * directly. FIN-022 asks for masking by default with the full value behind an explicit permission
 * and a per-reveal audit entry; that permission does not exist yet (Slice 6), so the honest
 * position today is that this endpoint simply does not serve it.
 *
 * @mixin \App\Modules\Hr\Models\Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payslip_id' => $this->payslip_id,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'status' => $this->status,

            'bank_name' => $this->bank_name,
            'bank_account_masked' => $this->maskedAccount(),
            'bank_account_type' => $this->bank_account_type,

            // The bank's reference and the date it cleared. Both null until reconciliation — that
            // is the difference between "we sent it" and "it arrived", and the null is meaningful.
            'reference' => $this->reference,
            'settled_at' => $this->settled_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,

            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_no' => $this->employee->employee_no,
                'name' => $this->employee->user?->name,
                'department' => $this->employee->department?->name,
            ]),
        ];
    }
}
