<?php

namespace App\Modules\Crm\Observers;

use App\Modules\Crm\Models\Note;
use Illuminate\Support\Facades\Auth;

/**
 * Records an append-only Activity for every note create/update/delete, mirroring LeadObserver.
 * The Activity subject is the note's LEAD (not the note), so these events flow through the
 * existing Activity::scopeVisibleTo ownership rules and surface on Recent Activity / Activity Logs
 * exactly like every other lead event. The acting user is the causer. No new activity system.
 */
class NoteObserver
{
    public function created(Note $note): void
    {
        $this->log($note, 'note.created', 'Note added');
    }

    public function updated(Note $note): void
    {
        $this->log($note, 'note.updated', 'Note edited');
    }

    public function deleted(Note $note): void
    {
        $this->log($note, 'note.deleted', 'Note deleted');
    }

    private function log(Note $note, string $event, string $description): void
    {
        $note->lead?->activities()->create([
            'causer_id' => Auth::id(),
            'event' => $event,
            'description' => $description,
            'properties' => ['note_id' => $note->id],
        ]);
    }
}
