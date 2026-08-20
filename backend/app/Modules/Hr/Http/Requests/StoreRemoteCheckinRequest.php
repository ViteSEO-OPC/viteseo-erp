<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** An off-site time-in: a selfie, and whatever position the browser could manage. */
class StoreRemoteCheckinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum on the route; the employee comes from the token
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // HEIC/HEIF included so a photo straight off an iPhone is accepted rather than
            // silently rejected at the door — same rule shape as reimbursement receipts.
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,heic,heif', 'max:5120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => 'A photo is needed so HR can confirm your time in.',
            'photo.mimes' => 'The photo must be a JPG, PNG, or HEIC image.',
            'photo.max' => 'The photo must be 5 MB or smaller.',
            // Fires when the file exceeds PHP's upload_max_filesize and never fully
            // arrives; the raw message is an unhelpful "failed to upload".
            'photo.uploaded' => 'The photo was too large for the server to accept. Try again, or ask an admin to raise the upload limit.',
        ];
    }
}
