<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesTemplateContent;
use App\Modules\Crm\Requests\Concerns\ValidatesTemplatePlaceholders;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating an email template. Authorization is the route's `role:super_admin|crm_admin`; this class
 * keeps purpose and tone inside the controlled catalog and the placeholders inside the renderer's
 * whitelist. Uniqueness and automation protection live in EmailTemplateManagementService.
 *
 * A template is either plain text (`body`) or designed: `content` (a JSON-encoded string) shown in the email
 * design `email_design_id` names, or in the organization's default design when it names none. A designed
 * template's body is derived on the server, so a body sent alongside content is dropped before validation
 * and never stored.
 */
class StoreEmailTemplateRequest extends FormRequest
{
    use ValidatesTemplateContent;
    use ValidatesTemplatePlaceholders;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', Rule::in(EmailTemplateCatalog::purposes())],
            'tone' => ['required', 'string', Rule::in(EmailTemplateCatalog::TONES)],
            'subject' => ['required', 'string', 'max:255'],
            'body' => $this->input('content') !== null ? ['exclude'] : ['required', 'string', 'max:'.EmailTemplateCatalog::BODY_MAX],
            'content' => ['nullable', 'string'],
            'email_design_id' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->contentMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validatePlaceholders($validator);
            $this->validateContent($validator, null);
        });
    }
}
