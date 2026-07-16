<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\DocumentRequestService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequestRequest extends FormRequest
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
        $employeeId = $this->user()?->employee?->id;

        return [
            // Access to a specific HR-uploaded document on the caller's own record.
            'employee_document_id' => [
                'nullable',
                'integer',
                Rule::exists('hr_employee_documents', 'id')->where('employee_id', $employeeId)->whereNull('deleted_at'),
            ],
            // Type is for generic requests; document-linked requests derive it.
            'type' => ['required_without:employee_document_id', Rule::in(DocumentRequestService::TYPES)],
            // Required when "Other" — the specific document being requested.
            'other_detail' => ['nullable', 'required_if:type,other', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
