<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Crm\Models\Note;
use App\Models\User;

/**
 * Notes reuse LeadPolicy for read/create: the index and store routes authorize `view` on the
 * parent lead (ownership boundary) plus the `lead.notes.create` permission. This policy governs
 * only EDIT and DELETE, which are restricted to the note's author, an Admin, or a Super Admin.
 * (Super Admin also passes via Gate::before; kept in the check for clarity is unnecessary.)
 */
class NotePolicy
{
    public function update(User $user, Note $note): bool
    {
        return $user->can('crm.lead.notes.edit') && $this->authorOrManager($user, $note);
    }

    public function delete(User $user, Note $note): bool
    {
        return $user->can('crm.lead.notes.delete') && $this->authorOrManager($user, $note);
    }

    /** Author of the note, or an Admin (Super Admin is already granted everything via Gate::before). */
    private function authorOrManager(User $user, Note $note): bool
    {
        return $note->user_id === $user->id || $user->hasRole('crm_admin');
    }
}
