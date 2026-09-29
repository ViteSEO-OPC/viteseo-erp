<?php

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Switch Copilot AI on or off. The toggle is the only field; nothing else can be written here. */
class UpdateCopilotSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level `role:super_admin` authorizes.
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'ai_enabled' => ['required', 'boolean'],
        ];
    }
}
