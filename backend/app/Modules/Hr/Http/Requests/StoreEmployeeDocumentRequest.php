<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by role:hr_officer
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Validate by the real file extension (not the sniffed MIME) so valid
            // PDFs whose MIME resolves to application/octet-stream still pass.
            'file' => ['required', 'file', 'max:10240', 'extensions:pdf,doc,docx,jpg,jpeg,png'],
            'name' => ['nullable', 'string', 'max:255'],
            'label' => ['nullable', Rule::in(['Uploaded', 'Signed'])],
        ];
    }
}
