<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Note;
use App\Modules\Crm\Observers\NoteObserver;

/**
 * The single place an internal note is written.
 *
 * Both entry points go through here — the Lead Details composer (POST /leads/{lead}/notes) and the
 * initial note captured by the Create Lead wizard — so there is one definition of how a note is
 * attached: body is the only mass-assignable field, `lead_id` comes from the relationship and the
 * author is associated explicitly, keeping both out of client control exactly as before.
 *
 * Saving through the model means {@see NoteObserver} still logs the
 * `note.created` Activity against the lead, so a note created during lead creation lands in the
 * timeline and in Internal Notes with no special handling.
 */
class LeadNoteCreator
{
    public function create(Lead $lead, string $body, User $author): Note
    {
        $note = $lead->notes()->make(['body' => $body]);
        $note->author()->associate($author);
        $note->save();

        return $note;
    }
}
