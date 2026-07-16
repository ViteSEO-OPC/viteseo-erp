<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Employee "My Benefits" view — card content only, no cross-employee enrolment data.
class MyBenefitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'status' => $this->status,
            'details' => $this->details ?? [],
            'progress' => $this->progress,
            'footer_label' => $this->footer_label,
        ];
    }
}
