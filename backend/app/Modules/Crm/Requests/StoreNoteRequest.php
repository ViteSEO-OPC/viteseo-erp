<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesNoteDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreNoteRequest extends FormRequest
{
    use ValidatesNoteDocument;

    public function authorize(): bool
    {
        // The route authorizes: ->can('view', 'lead') + permission:lead.notes.create.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // `body` is trimmed by the global TrimStrings middleware, so 'required' rejects
        // whitespace-only. It stays REQUIRED even when `body_doc` is present: the client's copy is
        // only a hint, and the server overwrites it with its own projection of the document
        // (LeadNoteCreator). Keeping it required means a plain-text client is unaffected, and the
        // column is never null.
        return [
            'body' => ['required', 'string', 'max:'.self::BODY_MAX_LENGTH],
            ...$this->noteDocumentRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateNoteDocument($validator);
    }
}
