<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ReimbursementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Absolute URLs so the frontend can link/preview each receipt directly.
        $receiptUrls = array_map(
            static fn (string $path): string => Storage::disk('public')->url($path),
            $this->allReceiptPaths(),
        );

        return [
            'id' => $this->id,
            'employee' => [
                'id' => $this->employee->id,
                'name' => $this->employee->user?->name,
            ],
            'category' => $this->category,
            'amount' => $this->amount,
            'expense_date' => $this->expense_date?->format('Y-m-d'),
            'description' => $this->description,
            'receipt_urls' => $receiptUrls,
            // Kept for back-compat: the first receipt, or null.
            'receipt_url' => $receiptUrls[0] ?? null,
            'status' => $this->status,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->reviewer?->name,
            'review_note' => $this->review_note,
            'created_at' => $this->created_at,
        ];
    }
}
