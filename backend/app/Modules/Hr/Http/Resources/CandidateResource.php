<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'requisition_id' => $this->requisition_id,
            'requisition' => $this->requisition ? [
                'id' => $this->requisition->id,
                'title' => $this->requisition->title,
            ] : null,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source,
            'experience_years' => $this->experience_years,
            'stage' => $this->stage,
            'rating' => $this->rating !== null ? (float) $this->rating : null,
            'expected_salary' => $this->expected_salary,
            'location' => $this->location,
            'feedback' => $this->feedback,
            'notes' => $this->notes,
            'emails_sent' => $this->emails_sent ?? [],
            // What HR actually sent in the assessment email, kept so it can be read
            // back on the candidate and repeated on a re-send.
            'assessment_link' => $this->assessment_link,
            'assessment_instructions' => $this->assessment_instructions,
            'assessment_deadline' => $this->assessment_deadline?->toDateString(),
            'assessment_sent_at' => $this->assessment_sent_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'hired_employee_id' => $this->hired_employee_id,
            'resume_path' => $this->resume_path,
            'resume_url' => $this->resume_path ? Storage::disk('public')->url($this->resume_path) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
