<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Requests\Concerns\ValidatesNoteDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateNoteRequest extends FormRequest
{
    use ValidatesNoteDocument;

    public function authorize(): bool
    {
        // The route authorizes edit via ->can('update', 'note') (NotePolicy@update).
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
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
