<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is gated by role:hr_officer|super_admin
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `image` rejects anything the server can't decode as a picture, so a renamed
            // executable can't slip through on extension alone. No SVG: it is a document
            // format that can carry script, and it is inlined into the payslip PDF.
            'logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.max' => 'The logo must be 2 MB or smaller.',
            'logo.mimes' => 'The logo must be a JPG, PNG or WebP image.',
        ];
    }
}
