<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeePhotoRequest extends FormRequest
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
            // `image` rejects anything the server can't decode as a picture, so a
            // renamed executable can't slip through on extension alone.
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.max' => 'The photo must be 5 MB or smaller.',
            'photo.mimes' => 'The photo must be a JPG, PNG or WebP image.',
        ];
    }
}
