<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\RecruitmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:hr_officer on the route
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'pipeline' => ['nullable', Rule::in(RecruitmentService::PIPELINES)],
            'employment_type' => ['nullable', Rule::in(RecruitmentService::EMPLOYMENT_TYPES)],
            'salary_range' => ['nullable', 'string', 'max:100'],
            // OJT pays an allowance instead. The period is meaningless without a
            // figure, so it is only required once one is given.
            'allowance_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'allowance_period' => [
                'nullable', 'required_with:allowance_amount',
                Rule::in(RecruitmentService::ALLOWANCE_PERIODS),
            ],
            'department_id' => ['required', 'integer', 'exists:hr_departments,id'],
            'hiring_manager_id' => ['nullable', 'integer', 'exists:hr_employees,id'],
            'openings' => ['required', 'integer', 'min:1'],
            'stage' => ['nullable', Rule::in(RecruitmentService::REQ_STAGES)],
            'opened_at' => ['nullable', 'date'],
            'target_start_date' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'job_description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
