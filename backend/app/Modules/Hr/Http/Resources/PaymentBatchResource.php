<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout of one run.
 *
 * @mixin \App\Modules\Hr\Models\PaymentBatch
 */
class PaymentBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'method' => $this->method,
            'status' => $this->status,
            'total_amount' => (float) $this->total_amount,
            'payment_count' => (int) $this->payment_count,
            'notes' => $this->notes,

            // Non-null means the file has been downloaded, which is the point of no return: the
            // company has to assume the bank has it, so the batch can no longer be rebuilt.
            'exported_at' => $this->exported_at?->toIso8601String(),
            'exported_by' => $this->whenLoaded('exporter', fn () => $this->exporter?->name),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),

            'payroll_run' => $this->whenLoaded('payrollRun', fn () => [
                'id' => $this->payrollRun->id,
                'cycle_start' => $this->payrollRun->cycle_start,
                'cycle_end' => $this->payrollRun->cycle_end,
                'frequency' => $this->payrollRun->frequency,
                'total_net' => (float) $this->payrollRun->total_net,
            ]),

            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
