<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Requests\Concerns\ValidatesTemplateContent;
use App\Modules\Crm\Requests\Concerns\ValidatesTemplatePlaceholders;
use App\Modules\Crm\Services\Outreach\EmailTemplateOptionService;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Editing an email template — any subset of its fields. The same catalog and placeholder rules as
 * creating one; whether a purpose may change, and whether a template may be switched off, are decided
 * by EmailTemplateManagementService, which knows what the automation endpoint depends on.
 *
 * `content` replaces a template's content (a JSON-encoded string) and a body sent with it is dropped;
 * `content: null` makes it a plain-text template again. `email_design_id` alone moves a designed template to
 * another design. Refusing a body edited on its own for a designed template needs the stored row, so that
 * rule lives in the service.
 */
class UpdateEmailTemplateRequest extends FormRequest
{
    use ValidatesTemplateContent;
    use ValidatesTemplatePlaceholders;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'purpose' => ['sometimes', 'required', 'string', Rule::in(app(EmailTemplateOptionService::class)->purposes())],
            'tone' => ['sometimes', 'required', 'string', Rule::in(app(EmailTemplateOptionService::class)->tones())],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => $this->input('content') !== null ? ['exclude'] : ['sometimes', 'required', 'string', 'max:'.EmailTemplateCatalog::BODY_MAX],
            'content' => ['sometimes', 'nullable', 'string'],
            'email_design_id' => ['sometimes', 'nullable', 'integer'],
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

            $template = $this->route('emailTemplate');
            $this->validateContent($validator, $template instanceof EmailTemplate ? $template : null);
        });
    }
}
