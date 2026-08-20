<?php

namespace App\Modules\Hr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** The optional location a browser can attach to a clock-in. */
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
