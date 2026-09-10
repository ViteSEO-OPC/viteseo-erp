<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Note;
use App\Modules\Crm\Observers\NoteObserver;
use App\Support\TiptapDocument;

/**
 * The single place an internal note is written.
 *
 * Three entry points go through here — the Lead Details composer (POST /leads/{lead}/notes), the
 * initial note captured by the Create Lead wizard, and the spreadsheet importer — so there is one
 * definition of how a note is attached: body is mass-assignable, `lead_id` comes from the
 * relationship and the author is associated explicitly, keeping both out of client control.
 *
 * Saving through the model means {@see NoteObserver} still logs the `note.created` Activity against
 * the lead, so a note created during lead creation or an import lands in the timeline and in Internal
 * Notes with no special handling.
 *
 * `$bodyDoc` is OPTIONAL, which is what keeps the wizard and the importer working untouched: a call
 * that passes only a string stores `body_doc = NULL` and behaves exactly as it did before rich text
 * existed. Imported spreadsheet cells are plain text by nature and stay that way.
 */
class LeadNoteCreator
{
    /**
     * @param  array<string, mixed>|null  $bodyDoc  A validated Tiptap document, or null for plain text.
     */
    public function create(Lead $lead, string $body, User $author, ?array $bodyDoc = null): Note
    {
        $note = $lead->notes()->make(self::attributesFor($body, $bodyDoc));
        $note->author()->associate($author);
        $note->save();

        return $note;
    }

    /**
     * Apply an edit to an existing note, with the same projection rule as a create.
     *
     * Lives here rather than in the controller so there is exactly ONE implementation of "how a
     * document becomes stored columns" — a second copy in the update path is how the two drift.
     *
     * @param  array<string, mixed>|null  $bodyDoc
     */
    public function apply(Note $note, string $body, ?array $bodyDoc = null): Note
    {
        $note->update(self::attributesFor($body, $bodyDoc));

        return $note;
    }

    /**
     * The columns to store, with `body` DERIVED SERVER-SIDE whenever a document is present.
     *
     * The client's `body` is treated as a hint and discarded here. That is the whole point: a caller
     * could otherwise post innocuous plain text alongside a rich document and have the two disagree
     * permanently — search, exports, the importer and the 5000-character rule all read `body`, so a
     * mismatch would make the stored text a lie about the stored document.
     *
     * `extractText()` is the same projection the Projects notes module uses, and the same one the
     * request's length check measured, so what was validated is what gets stored.
     *
     * @param  array<string, mixed>|null  $bodyDoc
     * @return array<string, mixed>
     */
    private static function attributesFor(string $body, ?array $bodyDoc): array
    {
        if ($bodyDoc === null) {
            return ['body' => $body, 'body_doc' => null];
        }

        return [
            'body' => TiptapDocument::extractText($bodyDoc),
            'body_doc' => $bodyDoc,
        ];
    }
}
