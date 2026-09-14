<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesTemplatePlaceholders;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Editing an email template — any subset of its fields. The same catalog and placeholder rules as
 * creating one; whether a purpose may change, and whether a template may be switched off, are decided
 * by EmailTemplateManagementService, which knows what the automation endpoint depends on.
 */
class UpdateEmailTemplateRequest extends FormRequest
{
    use ValidatesTemplatePlaceholders;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'purpose' => ['sometimes', 'required', 'string', Rule::in(EmailTemplateCatalog::purposes())],
            'tone' => ['sometimes', 'required', 'string', Rule::in(EmailTemplateCatalog::TONES)],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string', 'max:'.EmailTemplateCatalog::BODY_MAX],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validatePlaceholders($validator));
    }
}
