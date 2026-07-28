<?php

namespace App\Modules\Crm\Policies;

use App\Models\User;
use App\Modules\Crm\Models\LeadAttachment;

/**
 * Attachments reuse LeadPolicy for read/create/download: those routes authorize `view` on the
 * parent lead (the ownership boundary), and upload additionally requires
 * `crm.lead.attachments.create`. This policy governs only DELETE.
 *
 * Deliberately NOT author-scoped, unlike NotePolicy. A note is a personal statement, so only its
 * author edits it; a lead's attachments are shared working documents, and anyone who can open the
 * lead manages them equally — Admin and BD have identical rights here (🔒 PO decision 2026-07-28).
 *
 * The lead-visibility check is load-bearing: the delete route authorizes through THIS policy alone
 * (no `can('view', 'lead')`), so without it the permission would let a BD delete a file on a lead
 * they cannot see. Row deletion is soft, so the audit trail survives.
 */
class LeadAttachmentPolicy
{
    public function delete(User $user, LeadAttachment $attachment): bool
    {
        return $user->can('crm.lead.attachments.delete')
            && $attachment->lead->isVisibleTo($user);
    }
}
