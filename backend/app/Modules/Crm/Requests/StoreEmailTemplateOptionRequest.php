<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Services\Outreach\EmailTemplateOptionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding a custom purpose or tone. Authorization is the route's `role:super_admin|crm_admin`. These rules only bound
 * the request; EmailTemplateOptionService cleans the value and refuses invalid values, duplicates and collisions
 * with built-in purposes, tones and automation keys.
 */
class StoreEmailTemplateOptionRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(EmailTemplateOptionService::TYPES)],
            'value' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'type.in' => 'Choose purpose or tone.',
            'value.required' => EmailTemplateOptionService::EMPTY,
            'value.max' => EmailTemplateOptionService::LENGTH,
        ];
    }
}
