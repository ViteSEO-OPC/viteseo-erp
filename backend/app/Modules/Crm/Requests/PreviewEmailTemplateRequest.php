<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesTemplateContent;
use App\Modules\Crm\Requests\Concerns\ValidatesTemplatePlaceholders;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Previewing a template's unsaved content in a design. Authorization is the route's
 * `role:super_admin|crm_admin`. A preview is refused for exactly what saving would refuse — the same content
 * rules, design check and Gmail size guard, and the same placeholder whitelist for the subject — so content
 * never previews successfully and then fails to save.
 */
class PreviewEmailTemplateRequest extends FormRequest
{
    use ValidatesTemplateContent;
    use ValidatesTemplatePlaceholders;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'content' => ['required', 'string'],
            'email_design_id' => ['nullable', 'integer'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
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
