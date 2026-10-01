<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Checking a logo upload before it is cropped. Stores nothing; the same limits as the upload itself. */
class InspectEmailLogoRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048', 'extensions:png,svg'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a PNG or SVG logo to upload.',
            'file.max' => 'The logo must be 2 MB or smaller.',
            'file.extensions' => 'Upload a PNG or SVG logo.',
        ];
    }
}
