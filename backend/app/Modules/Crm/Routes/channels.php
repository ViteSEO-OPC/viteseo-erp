<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| CRM Broadcast Channels
|--------------------------------------------------------------------------
|
| Private channels for CRM real-time features. Authorization mirrors the REST
| layer EXACTLY — the same policy the matching endpoint uses — so channel access
| can never drift from API access. Mirrors the Projects module's channels file.
|
| A note on the ownership model this enforces: CRM lead visibility is
| ownership-scoped (Lead::isVisibleTo) — Admin / Super Admin see every lead, a BD
| sees only leads assigned to them. A leak here is worse than a leak in the REST
| layer, because a socket keeps pushing indefinitely once subscribed, so these
| authorizers deny by default and never widen what the caller may already see.
|
*/

// crm.lead.{leadId} — one lead's stream (assignment, notes, calls, email, files).
//
// Authorized by LeadPolicy::view, i.e. the `crm.leads.view` permission AND
// Lead::isVisibleTo() — the identical check behind GET /api/v1/crm/leads/{lead}.
// Deliberately NOT scoped to Lead::active(): the REST show endpoint isn't either,
// and mirroring it is the whole point.
//
// Denies when: the id isn't numeric, the lead doesn't exist, the lead is
// soft-deleted (whereKey excludes trashed), the caller lacks crm.leads.view, or
// the caller is a BD who doesn't own the lead.
//
// Re-authorization caveat: the Pusher protocol authorizes at SUBSCRIBE time only.
// A BD who is already subscribed and then loses the lead (reassignment) keeps
// receiving until their client resubscribes. Events on this channel must
// therefore carry no data a former owner may not retain — see Slice 3.
Broadcast::channel('crm.lead.{leadId}', function (User $user, string $leadId): bool {
    if (! ctype_digit($leadId)) {
        return false;
    }

    $lead = Lead::query()->whereKey((int) $leadId)->first();

    return $lead !== null && $user->can('view', $lead);
});
