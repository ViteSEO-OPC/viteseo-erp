<?php

declare(strict_types=1);

namespace App\Modules\Crm\Requests\Concerns;

use App\Support\TiptapDocument;
use Illuminate\Validation\Validator;

/**
 * Shared `body_doc` validation for the note write requests, so Store and Update cannot drift.
 *
 * ── The check ORDER is a security requirement, not a style choice ──
 * `TiptapDocument::disallowedTypes()` and `::depth()` share the same private `walk()`, which stops
 * descending past `MAX_DEPTH`. A disallowed node buried below that cutoff is therefore INVISIBLE to
 * `disallowedTypes()` — it returns an empty violation list (pinned in TiptapDocumentTest as
 * `test_a_disallowed_node_buried_below_the_depth_cutoff_is_NOT_reported`).
 *
 * So depth MUST be rejected before node types are inspected. A validator that checked types first,
 * or that continued after a depth failure, would accept a document containing arbitrary node types.
 * Hence bytes → depth → types, each with a hard `return`.
 *
 * The character limit is deliberately checked LAST and against the extracted TEXT, because that is
 * the quantity `crm_notes.body` is constrained on. A document can sit far under the 512 KB byte
 * ceiling while its readable text runs well past 5000 characters; measuring the blob instead of the
 * projection would silently retire the limit.
 */
trait ValidatesNoteDocument
{
    /** Mirrors `body`'s `max:5000` — the ceiling applies to readable text, not to the document. */
    public const BODY_MAX_LENGTH = 5000;

    /** @return array<string, mixed> Rules to merge into the request's own. */
    protected function noteDocumentRules(): array
    {
        return [
            // Absent or null → a plain-text note, exactly as before. Present → it must be a document.
            'body_doc' => ['sometimes', 'nullable', 'array'],
            'body_doc.type' => ['required_with:body_doc', 'string', 'in:doc'],
        ];
    }

    /** The submitted document, or null when this is a plain-text write. */
    public function noteDocument(): ?array
    {
        $doc = $this->input('body_doc');

        // NOT `validated('body_doc')`: a nested rule set returns only the keys it covers (here just
        // `type`), which would silently strip the document's children. Validated as a whole below,
        // then read raw.
        return is_array($doc) && $doc !== [] ? $doc : null;
    }

    protected function validateNoteDocument(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $doc = $this->input('body_doc');
            if (! is_array($doc) || $doc === []) {
                return; // plain-text write — nothing to check here.
            }

            // 1 ── bytes
            $encoded = json_encode($doc);
            if ($encoded === false || strlen($encoded) > TiptapDocument::MAX_BYTES) {
                $validator->errors()->add('body_doc', 'This note is too large to save (limit 512 KB).');

                return;
            }

            // 2 ── depth. Must precede the type check; see the class docblock.
            if (TiptapDocument::depth($doc) > TiptapDocument::MAX_DEPTH) {
                $validator->errors()->add('body_doc', 'This note is nested too deeply.');

                return;
            }

            // 3 ── node/mark types
            $disallowed = TiptapDocument::disallowedTypes($doc);
            if ($disallowed !== []) {
                $validator->errors()->add(
                    'body_doc',
                    'This note contains formatting that is not supported: '.implode(', ', $disallowed).'.',
                );

                return;
            }

            // 4 ── the readable length, measured on the projection the server will actually store.
            if (mb_strlen(TiptapDocument::extractText($doc)) > self::BODY_MAX_LENGTH) {
                $validator->errors()->add(
                    'body_doc',
                    'This note is too long (limit '.self::BODY_MAX_LENGTH.' characters).',
                );
            }
        });
    }
}
