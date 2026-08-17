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
            // Only on the cross-employee list, where a row has to say WHOSE loan it is. The
            // per-record list leaves it unloaded and the key resolves to null, so that payload
            // is byte-identical to before.
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee?->id,
                'name' => $this->employee?->user?->name,
            ]),
            'type' => $this->type,
            'reference' => $this->reference,
            'principal' => (float) $this->principal,
            'per_period_amount' => (float) $this->per_period_amount,
            // FIN-032 — the terms. `per_period_amount` is the PRINCIPAL instalment; interest and any
            // fee are charged on top, so the employee's actual deduction is larger than this figure
            // and the UI has to say so rather than let them assume it is the total.
            'interest_rate' => (float) $this->interest_rate,
            'interest_period' => $this->interest_period,
            'fees' => (float) $this->fees,
            'priority' => (int) $this->priority,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'status' => $this->status,
            'notes' => $this->notes,
            // Slice B15 — the policy it was issued under, and the consent that makes any deduction
            // lawful. `authorized_at` being null is the whole reason a loan may sit uncollected.
            'benefit_program_id' => $this->benefit_program_id,
            'policy_name' => $this->whenLoaded('policy', fn () => $this->policy?->name),
            'requested_at' => $this->requested_at,
            'approved_at' => $this->approved_at,
            'decision_note' => $this->decision_note,
            'authorized_at' => $this->authorized_at,
            // What the screen needs to decide which button to show, without re-deriving the rules.
            'needs_authorization' => $this->status === 'pending_authorization',
            'is_collectible' => $this->status === 'active',
            // Derived from the payment ledger — never a stored column (see EmployeeLoan).
            'paid' => $this->paid(),
            // Outstanding PRINCIPAL — what interest is charged on. Interest already collected does
            // not reduce it (see EmployeeLoan::outstanding()).
            'outstanding' => $this->outstanding(),
            'outstanding_fees' => $this->outstandingFees(),
            'paid_principal' => $this->paidPrincipal(),
            'paid_interest' => $this->paidInterest(),
            'paid_fees' => $this->paidFees(),
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
