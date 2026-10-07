<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesDesignedReply;
use App\Modules\Crm\Services\Mail\EmailDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The preview of a Lead Details reply in an existing Email Design: the BD's current reply document and the
 * design they chose. Held to EXACTLY the checks a designed send is (SendEmailRequest), through the same
 * trait, so whatever previews can be sent and whatever cannot be sent says why here too. Writes nothing.
 */
class PreviewReplyDesignRequest extends FormRequest
{
    use ValidatesDesignedReply;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'email_design_id' => ['required', ...$this->emailDesignIdRules()],
            // JSON, never HTML — the same document a send would carry, checked below exactly as a send checks it.
            'body_doc' => ['required', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $doc = $this->emailDocument();
            $problem = $doc === null ? 'Write your reply before choosing a design.' : EmailDocument::problem($doc);
            if ($problem !== null) {
                $validator->errors()->add('body_doc', $problem);

                return;
            }
            if (EmailDocument::toText($doc) === '') {
                $validator->errors()->add('body_doc', 'Write a message before sending.');

                return;
            }

            $this->validateDesignedReplyDocument($validator, $doc);
        });
    }

    /**
     * The submitted reply document. Read raw, as SendEmailRequest reads it: validated as a whole above.
     *
     * @return array<string, mixed>|null
     */
    public function emailDocument(): ?array
    {
        $doc = $this->input('body_doc');

        return is_array($doc) && $doc !== [] ? $doc : null;
    }
}
