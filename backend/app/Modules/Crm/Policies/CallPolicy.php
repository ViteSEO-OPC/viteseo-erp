<?php

namespace App\Modules\Crm\Policies;

use App\Models\User;
use App\Modules\Crm\Models\Call;

/**
 * Authorization for call attempts. A call's visibility follows its LEAD's ownership (exactly like notes
 * and emails): a Business Development Manager may see or act on a call only when its lead is visible to
 * them; Admin sees/acts on all; Super Admin bypasses every check via Gate::before. `crm.calls.view` gates
 * reading (Call History); `crm.calls.log` gates concluding a call (complete / discard). STARTING a call
 * authorizes on the parent route (the lead or planner event), mirroring how NotePolicy leaves create to
 * the parent-lead route — so there is no create() here.
 */
class CallPolicy
{
    /** Read a call (Call History). */
    public function view(User $user, Call $call): bool
    {
        return $user->can('crm.calls.view') && ($call->lead?->isVisibleTo($user) ?? false);
    }

    /** Conclude a call — complete or discard (the two mutating actions on an existing call). */
    public function update(User $user, Call $call): bool
    {
        return $user->can('crm.calls.log') && ($call->lead?->isVisibleTo($user) ?? false);
    }
}
