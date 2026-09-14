<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesTemplatePlaceholders;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating an email template. Authorization is the route's `role:super_admin|crm_admin`; this class
 * keeps purpose and tone inside the controlled catalog and the placeholders inside the renderer's
 * whitelist. Uniqueness and automation protection live in EmailTemplateManagementService.
 */
class StoreEmailTemplateRequest extends FormRequest
{
    use ValidatesTemplatePlaceholders;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', Rule::in(EmailTemplateCatalog::purposes())],
            'tone' => ['required', 'string', Rule::in(EmailTemplateCatalog::TONES)],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:'.EmailTemplateCatalog::BODY_MAX],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validatePlaceholders($validator));
    }
}
