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

    /**
     * Read the website-evidence trail behind a lead's website conclusion.
     *
     * Ownership is deliberately NOT required for a PROSPECT, for the same reason as {@see qualify()}:
     * the prospect pool is shared and unassigned, so `isVisibleTo` would deny every BD on precisely
     * the businesses this evidence exists to explain. A qualified lead has an owner, and the ordinary
     * visibility boundary applies to it again.
     */
    public function viewWebsiteEvidence(User $user, Lead $lead): bool
    {
        return $user->can('crm.leads.view') && ($lead->isProspect() || $lead->isVisibleTo($user));
    }

    /**
     * Confirm, from that evidence, that a business genuinely has no website.
     *
     * Gated on the ordinary EDIT permission, because it writes `website_status` — the same field and
     * the same authority as a manual lead edit. The prospect carve-out matches
     * {@see viewWebsiteEvidence()}; the evidence guards in
     * {@see \App\Modules\Crm\Services\Website\WebsiteEvidenceService::confirmAbsent()} apply on
     * top of this and are what stop an unfounded claim.
     */
    public function confirmWebsiteAbsence(User $user, Lead $lead): bool
    {
        return $user->can('crm.leads.edit') && ($lead->isProspect() || $lead->isVisibleTo($user));
    }

    /**
     * Link Planner work (an event, or a deal reference on one) to this lead — CEO audit crm-04.
     *
     * Deliberately NOT {@see view()}, and not a second visibility system: it starts from the canonical
     * ownership boundary (Lead::isVisibleTo) and widens it in exactly two places.
     *
     *  - An UNCLAIMED prospect (no owner yet) may be linked by anyone who may create Planner events,
     *    because Nearby "Plan visit" schedules a visit before the business is qualified (the frozen
     *    Nearby → Planner flow). A prospect that already has an owner belongs to that owner — stricter
     *    than the website-evidence carve-out above, which only reads.
     *  - The n8n automation account (the only holder of `crm.outreach.automation.send`; it owns no
     *    leads) may link a lead only for that lead's current owner, which is how its follow-up and email
     *    task workflows call this endpoint.
     *
     * There is no permission check here: the Planner routes already require `crm.calendar.create` /
     * `crm.calendar.edit`, and what this ability enforces is whose lead it is.
     */
    public function link(User $user, Lead $lead, ?int $eventOwnerId = null): bool
    {
        if ($lead->isVisibleTo($user)) {
            return true;
        }

        if ($lead->isProspect() && $lead->assigned_to === null) {
            return true;
        }

        return $lead->assigned_to !== null
            && $eventOwnerId === $lead->assigned_to
            && $user->can('crm.outreach.automation.send');
    }
}
