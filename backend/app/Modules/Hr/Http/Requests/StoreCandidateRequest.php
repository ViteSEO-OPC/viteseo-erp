<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\RecruitmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'requisition_id' => ['required', 'integer', 'exists:hr_requisitions,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'source' => ['required', Rule::in(RecruitmentService::SOURCES)],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:60'],
            // Stage is validated against the requisition's pipeline in the service.
            'stage' => ['nullable', 'string', 'max:50'],
            'rating' => ['nullable', 'numeric', 'between:0,5'],
            // Free-text salary range (e.g. "₱45,000 - 65,000").
            'expected_salary' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:255'],
            // OJT only -- the service drops both unless the requisition is an OJT.
            'school' => ['nullable', 'string', 'max:255'],
            'required_hours' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'feedback' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'resume' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
        ];
    }
}
