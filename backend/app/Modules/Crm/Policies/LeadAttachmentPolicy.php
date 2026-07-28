<?php

namespace App\Modules\Crm\Policies;

use App\Models\User;
use App\Modules\Crm\Models\LeadAttachment;

/**
 * Attachments reuse LeadPolicy for read/create/download: those routes authorize `view` on the
 * parent lead (the ownership boundary), and upload additionally requires
 * `crm.lead.attachments.create`. This policy governs only DELETE, which — mirroring NotePolicy —
 * is restricted to the uploader, an Admin, or a Super Admin (the latter via Gate::before).
 */
class LeadAttachmentPolicy
{
    public function delete(User $user, LeadAttachment $attachment): bool
    {
        return $user->can('crm.lead.attachments.delete')
            && ($attachment->user_id === $user->id || $user->hasRole('crm_admin'));
    }
}
