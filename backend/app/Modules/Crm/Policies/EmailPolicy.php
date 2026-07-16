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

    /**
     * RC3 Send-Later pre-execution actions. Permitted ONLY while the email is still `scheduled`
     * (not yet claimed by the sender job) and only for its author or an Admin — once sent it is
     * immutable history, so these are denied. Send-Now uses the send permission.
     */
    public function cancel(User $user, Email $email): bool
    {
        return $user->can('crm.follow-ups.edit') && $this->scheduledOwnedBy($user, $email);
    }

    public function reschedule(User $user, Email $email): bool
    {
        return $user->can('crm.follow-ups.edit') && $this->scheduledOwnedBy($user, $email);
    }

    public function sendNow(User $user, Email $email): bool
    {
        return $user->can('crm.follow-ups.create') && $this->scheduledOwnedBy($user, $email);
    }

    /** Only a draft, and only its author or an Admin, may be mutated/sent. */
    private function draftOwnedBy(User $user, Email $email): bool
    {
        return $email->status === 'draft'
            && ($email->user_id === $user->id || $user->hasRole('crm_admin'));
    }

    /** Only a still-scheduled email, and only its author or an Admin, may be cancelled/rescheduled/sent-now. */
    private function scheduledOwnedBy(User $user, Email $email): bool
    {
        return $email->status === 'scheduled'
            && ($email->user_id === $user->id || $user->hasRole('crm_admin'));
    }
}
