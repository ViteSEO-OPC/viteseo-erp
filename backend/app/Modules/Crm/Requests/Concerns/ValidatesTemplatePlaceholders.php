<?php

namespace App\Modules\Crm\Requests\Concerns;

use App\Modules\Crm\Support\EmailTemplateCatalog;
use Illuminate\Validation\Validator;

/**
 * A template's subject and body may use only the placeholders TemplateRenderer substitutes. Anything
 * else would reach a customer either blank or as literal `{{…}}` text, so it is refused at save time.
 */
trait ValidatesTemplatePlaceholders
{
    protected function validatePlaceholders(Validator $validator): void
    {
        $allowed = implode(', ', array_map(
            fn (string $key): string => '{{'.$key.'}}',
            array_keys(EmailTemplateCatalog::TOKENS),
        ));

        foreach (['subject', 'body'] as $field) {
            // A body sent with content is dropped, not stored: the content's own text is validated instead.
            if ($field === 'body' && $this->input('content') !== null) {
                continue;
            }

            $value = $this->input($field);

            // A field already failing its own rules (missing, too long) reports that first.
            if (! is_string($value) || $validator->errors()->has($field)) {
                continue;
            }

            $unknown = EmailTemplateCatalog::unknownTokens($value);

            if ($unknown !== []) {
                $validator->errors()->add($field, 'Unknown placeholder '.implode(', ', $unknown).". Use only: {$allowed}.");
            } elseif (EmailTemplateCatalog::hasStrayBraces($value)) {
                $validator->errors()->add($field, 'A placeholder is missing its opening {{ or closing }}.');
            }
        }
    }
}
