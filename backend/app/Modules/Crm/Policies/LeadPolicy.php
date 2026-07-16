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

    /**
     * Restore a soft-deleted ("archived") lead — Archive Management. Management-only
     * (leads.restore is granted to Admin/Super Admin, never BD); ownership boundary still applies
     * (isVisibleTo is a pass-through for Admin/SA, so they may restore any archived lead).
     */
    public function restore(User $user, Lead $lead): bool
    {
        return $user->can('crm.leads.restore') && $lead->isVisibleTo($user);
    }

    /**
     * Permanently delete a lead (bypasses soft delete). Architecture-ready but intentionally
     * DISABLED — Permanent Delete is a future milestone. No permission grants it today, so this
     * always denies; the route is left commented in routes/api.php.
     */
    public function forceDelete(User $user, Lead $lead): bool
    {
        return false;
    }

    public function import(User $user): bool
    {
        return $user->can('crm.leads.import');
    }

    public function assign(User $user, Lead $lead): bool
    {
        return $user->can('crm.leads.assign');
    }

    /**
     * Qualify a discovered prospect into the CRM. Prospects are a SHARED pool (unassigned), so this is
     * deliberately NOT gated by ownership visibility — qualification is the act that assigns ownership.
     * Granted to BD / Admin / Super Admin.
     */
    public function qualify(User $user, Lead $lead): bool
    {
        return $user->can('crm.leads.qualify');
    }
}
