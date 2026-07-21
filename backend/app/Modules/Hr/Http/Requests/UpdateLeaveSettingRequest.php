<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\LeaveSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaveSettingRequest extends FormRequest
{
    // Authorization handled by route middleware (auth:sanctum + role:hr_officer).
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
            'allotment_mode' => ['required', 'string', Rule::in(LeaveSetting::MODES)],
        ];
    }
}
