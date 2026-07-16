<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Crm\Models\Email;
use App\Models\User;

/**
 * Governs mutation of an Email as a DRAFT (its editable lifecycle state). Reads/creates are
 * authorized elsewhere (index is self-scoped to the author; create authorizes `view` on the
 * parent lead + `follow-ups.create` on the route). This policy allows update/delete/send ONLY
 * while the email is still a draft — sent/received rows are immutable history — and only for the
 * draft's author or an Admin (Super Admin already passes via Gate::before). Mirrors NotePolicy.
 */
class EmailPolicy
{
    public function update(User $user, Email $email): bool
    {
        return $user->can('crm.follow-ups.edit') && $this->draftOwnedBy($user, $email);
    }

    public function delete(User $user, Email $email): bool
    {
        return $user->can('crm.follow-ups.edit') && $this->draftOwnedBy($user, $email);
    }

    public function send(User $user, Email $email): bool
    {
        return $user->can('crm.follow-ups.create') && $this->draftOwnedBy($user, $email);
    }

    /** Only a draft, and only its author or an Admin, may be mutated/sent. */
    private function draftOwnedBy(User $user, Email $email): bool
    {
        return $email->status === 'draft'
            && ($email->user_id === $user->id || $user->hasRole('crm_admin'));
    }
}
