<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Services\RecruitmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequisitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'pipeline' => $this->pipeline,
            'employment_type' => $this->employment_type,
            'salary_range' => $this->salary_range,
            // Set only on an OJT requisition, which quotes an allowance rather than a salary.
            'allowance_amount' => $this->allowance_amount !== null ? (float) $this->allowance_amount : null,
            'allowance_period' => $this->allowance_period,
            'location' => $this->location,
            'job_description' => $this->job_description,
            'target_start_date' => $this->target_start_date?->format('Y-m-d'),
            // The ordered stage flow the board renders its columns from.
            'stages' => RecruitmentService::stagesFor($this->pipeline),
            'department' => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null,
            'hiring_manager' => $this->hiringManager ? [
                'id' => $this->hiringManager->id,
                'name' => $this->hiringManager->user?->name,
            ] : null,
            'openings' => $this->openings,
            'stage' => $this->stage,
            'opened_at' => $this->opened_at?->format('Y-m-d'),
            'candidates_count' => $this->candidates_count,
        ];
    }
}
