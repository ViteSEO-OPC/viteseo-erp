<?php

namespace App\Modules\Crm\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the signed-in user's own profile photo upload.
 *
 * There is no target user id to authorize: the controller resolves the user from the Sanctum token,
 * so this request cannot be pointed at somebody else's account.
 */
class StoreProfilePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Identity comes from the token; the route is behind auth:sanctum.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `image` is the rule that matters: it rejects anything the server cannot decode as a
            // picture, so a renamed executable cannot slip through on its extension alone. `mimes`
            // then narrows the decodable set to what the UI promises.
            //
            // 4 MB = 4096 KB, matching the "PNG or JPG, up to 4 MB." copy on the Settings card.
            // Note PHP's own upload_max_filesize/post_max_size still cap this independently.
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => 'Choose a photo to upload.',
            'photo.image' => 'That file is not an image.',
            'photo.mimes' => 'The photo must be a PNG or JPG image.',
            'photo.max' => 'The photo must be 4 MB or smaller.',
        ];
    }
}
