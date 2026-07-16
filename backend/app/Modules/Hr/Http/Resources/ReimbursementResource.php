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
            // Absolute URL so the frontend can link/preview it directly.
            'receipt_url' => $this->receipt_path
                ? Storage::disk('public')->url($this->receipt_path)
                : null,
            'status' => $this->status,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->reviewer?->name,
            'review_note' => $this->review_note,
            'created_at' => $this->created_at,
        ];
    }
}
