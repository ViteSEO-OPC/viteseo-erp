<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional location a browser can attach to a clock-in.
 *
 * Every field is nullable, and that is load-bearing rather than lax: a clock-in with no
 * location is a legitimate, common case — the geofence is switched off, the browser denied
 * permission, or the machine simply can't fix its position. GeofenceService decides what an
 * absent reading MEANS; this class only says what a well-formed one looks like.
 *
 * The employee is resolved from the token, never from the body, so there is nothing here to
 * authorize beyond being signed in.
 */
class ClockInRequest extends FormRequest
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
        return [
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            // The browser's own radius of confidence, in metres. No upper bound: a junk
            // 50 km reading is meaningful input — it tells the server not to trust the fix.
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
