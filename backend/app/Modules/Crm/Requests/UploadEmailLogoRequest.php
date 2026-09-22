<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Uploading the organization logo. Authorization is the route's `role:super_admin|crm_admin`.
 *
 * These rules only bound the request. The file's real format is decided from its bytes by EmailAssetService,
 * which decodes a PNG, sanitizes an SVG, checks the crop and the SVG's PNG rendering, and refuses anything else.
 * 2 MB matches PHP's upload limit and the "PNG or SVG, up to 2 MB" copy.
 */
class UploadEmailLogoRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048', 'extensions:png,svg'],
            'crop' => ['required', 'string', 'max:1000'],
            'rendition' => ['nullable', 'file', 'max:2048', 'extensions:png'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a PNG or SVG logo to upload.',
            'file.max' => 'The logo must be 2 MB or smaller.',
            'file.extensions' => 'Upload a PNG or SVG logo.',
            'crop.required' => 'Choose the part of the logo to keep.',
            'rendition.max' => 'The logo rendering must be 2 MB or smaller.',
            'rendition.extensions' => 'The logo rendering must be a PNG.',
        ];
    }
}
