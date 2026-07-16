<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Crm\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm.leads.view');
    }

    public function view(User $user, Lead $lead): bool
    {
        // Ownership boundary: a BD may only view their own leads (Lead Details, per-lead
        // activities, and the outreach email endpoint all authorize through this).
        return $user->can('crm.leads.view') && $lead->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('crm.leads.create');
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->can('crm.leads.edit') && $lead->isVisibleTo($user);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->can('crm.leads.delete') && $lead->isVisibleTo($user);
    }

    public function import(User $user): bool
    {
        return $user->can('crm.leads.import');
    }

    public function assign(User $user, Lead $lead): bool
    {
        return $user->can('crm.leads.assign');
    }
}
