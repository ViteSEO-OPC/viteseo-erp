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
            // B13 item 4 — an allowance's badge is DERIVED, not the stored string. `status` is a
            // free-text field HR types, and an allowance appearing on this page is already proof
            // that it is live (a deleted one is not returned), so the two could only disagree.
            // Company plans keep theirs: "Coming soon" versus "Active" is a real difference there.
            'status' => $this->kind === 'allowance' ? 'Active' : $this->status,
            'details' => $this->details ?? [],
            'progress' => $this->progress,
            'footer_label' => $this->footer_label,
        ];
    }
}
