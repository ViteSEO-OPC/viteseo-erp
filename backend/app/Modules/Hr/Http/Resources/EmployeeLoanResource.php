<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeLoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'type' => $this->type,
            'reference' => $this->reference,
            'principal' => (float) $this->principal,
            'per_period_amount' => (float) $this->per_period_amount,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'status' => $this->status,
            'notes' => $this->notes,
            // Derived from the payment ledger — never a stored column (see EmployeeLoan).
            'paid' => $this->paid(),
            'outstanding' => $this->outstanding(),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments
                ->sortByDesc('created_at')
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'payroll_run_id' => $p->payroll_run_id,
                    'amount' => (float) $p->amount,
                    'created_at' => $p->created_at,
                ])->values()),
            'created_at' => $this->created_at,
        ];
    }
}
