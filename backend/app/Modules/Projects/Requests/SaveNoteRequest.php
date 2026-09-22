<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Support\TiptapDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates an autosave payload. Authorization is NOT done here — a note is
 * always resolved from the authenticated user, and the project routes gate on
 * project membership in the controller.
 */
class SaveNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'array'],
            'content.type' => ['required', 'string', 'in:doc'],
            'base_version' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * The full Tiptap document.
     *
     * NOT `validated('content')` — that returns only the keys covered by a rule
     * (here just `content.type`), which would silently strip the document's
     * children. The document is validated as a whole in withValidator() below,
     * then read raw.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return (array) $this->input('content', []);
    }

    public function baseVersion(): int
    {
        return (int) $this->input('base_version');
    }

    /**
     * Size, depth, and node-type limits — cheap guards that keep a hostile or
     * runaway document out of the database.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $content = $this->input('content');
            if (! is_array($content)) {
                return;
            }

            $encoded = json_encode($content);
            if ($encoded === false || strlen($encoded) > TiptapDocument::MAX_BYTES) {
                $validator->errors()->add('content', 'This note is too large to save (limit 512 KB).');

                return;
            }

            if (TiptapDocument::depth($content) > TiptapDocument::MAX_DEPTH) {
                $validator->errors()->add('content', 'This note is nested too deeply.');

                return;
            }

            $disallowed = TiptapDocument::disallowedTypes($content);
            if ($disallowed !== []) {
                $validator->errors()->add(
                    'content',
                    'This note contains formatting that is not supported: '.implode(', ', $disallowed).'.',
                );
            }
        });
    }
}
