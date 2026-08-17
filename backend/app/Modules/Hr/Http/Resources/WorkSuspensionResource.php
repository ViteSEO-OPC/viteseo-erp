<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkSuspensionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->format('Y-m-d'),
            'name' => $this->name,
            'is_paid' => (bool) $this->is_paid,
            'note' => $this->note,
            // The attendance status this day carries, computed by the same method the roster
            // uses — so a screen never has to re-derive it from the boolean and get it wrong.
            'status' => $this->status(),
            'declared_by' => $this->declarer?->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
