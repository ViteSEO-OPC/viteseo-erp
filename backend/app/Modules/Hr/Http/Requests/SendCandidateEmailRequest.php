<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\RecruitmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Sending a candidate a stage email. Most types are templated and need nothing but the
 * type; the assessment email is composed by HR, so it also carries a link, instructions
 * and an optional deadline.
 */
class SendCandidateEmailRequest extends FormRequest
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
        // The compose fields only make sense on an assessment. One `required_without`
        // there is enough to reject an email with nothing in it — put it on both and a
        // blank submission complains twice about the same thing.
        $assessment = $this->input('type') === 'assessment';

        return [
            'type' => ['required', Rule::in(RecruitmentService::EMAIL_TYPES)],
            'link' => ['nullable', 'url', 'max:500'],
            'instructions' => [
                'nullable',
                'string',
                'max:2000',
                ...($assessment ? ['required_without:link'] : []),
            ],
            // Day boundaries are Manila's, so "not in the past" is judged there too.
            'deadline' => ['nullable', 'date', 'after_or_equal:' . now('Asia/Manila')->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'instructions.required_without' => 'Give the candidate a link, instructions, or both.',
            'deadline.after_or_equal' => 'The deadline cannot be in the past.',
        ];
    }
}
