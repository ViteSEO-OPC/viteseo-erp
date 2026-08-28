<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenameEmployeeDocumentRequest extends FormRequest
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
            // A rename changes the LABEL on the record, never the stored file. Path separators and
            // NUL are rejected all the same: the name is what the browser saves the download as,
            // so it has to be a filename rather than a route through someone's disk.
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\/\\\\\x00]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'A document name cannot contain slashes.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->name)) {
            $this->merge(['name' => trim($this->name)]);
        }
    }
}
