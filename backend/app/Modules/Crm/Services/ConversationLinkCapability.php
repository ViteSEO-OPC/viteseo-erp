<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Crm\Controllers\LeadController;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Services\Inbound\ConversationLeadLinker;
use App\Modules\Crm\Services\Outbound\ReplyCapability;

/**
 * Whether the UI should presently offer a "Link lead" control on one conversation.
 *
 * A PRESENTATION HINT, NOT AN AUTHORIZATION DECISION — the same contract as {@see ReplyCapability},
 * deliberately. Every real check still happens where it already does: the picker re-asks these
 * abilities, the link endpoint re-asks them through route middleware AND `LeadPolicy@update`, and
 * {@see ConversationLeadLinker} re-asserts tenancy and refuses to
 * overwrite an existing association. A client that ignores this field and posts anyway gets exactly
 * the refusals it would have got otherwise.
 *
 * WHY IT LIVES HERE AND NOT UNDER Outbound/ OR Inbound/. Associating a thread with a lead is
 * neither outbound messaging nor inbound transport — it is CRM bookkeeping about a record. Filing
 * it beside ReplyCapability would have put a non-messaging concern inside the messaging namespace
 * purely because a similar-looking class lives there.
 *
 * ================== WHY A SERVER-COMPUTED CAPABILITY AT ALL ==================
 *
 * The frontend already has a permission mechanism — `usePermissions().can(name)`, backed by the
 * auth payload's `permissions` array, which the platform UserResource fills from Spatie's
 * `getAllPermissions()` (role ∪ direct). For this ability that mechanism gives the WRONG ANSWER for
 * the most privileged users in the system.
 *
 * `crm.conversations.link_lead` is held by exactly one role, `crm_admin`. `super_admin` does NOT
 * hold a `role_has_permissions` row for it — it is authorized by the blanket
 * `Gate::before(super_admin => true)` registered in CrmServiceProvider, which answers before any
 * table is consulted and therefore never appears in `getAllPermissions()`. A browser-side
 * `permissions.includes('crm.conversations.link_lead')` would consequently HIDE the control from
 * every super_admin, who are precisely the people who may use it.
 *
 * So the question is answered here, through `can()`, where the Gate applies. This is the same
 * reason Phase 3H.0 computed the reply capability on the server rather than in the browser.
 *
 * DELIBERATELY QUERY-FREE, AND THAT IS A CORRECTNESS PROPERTY. It reads `lead_id` off the
 * conversation already in hand and asks the guard four abilities it has cached. It NEVER resolves
 * `$conversation->lead`, which matters beyond performance: an archived lead makes that relation
 * return null, and a capability that inferred "unlinked" from a null relation would offer a Link
 * button on a thread whose `lead_id` is non-null — an action the server is guaranteed to refuse
 * with a 409. `lead_id` is the only honest signal, so it is the only one read.
 */
final class ConversationLinkCapability
{
    /** The viewer may read this thread but may not associate it with a lead. */
    public const PERMISSION_DENIED = 'permission_denied';

    /** The thread already names a lead. Phase 3H.3A ships LINK only — no relink, no unlink. */
    public const ALREADY_LINKED = 'already_linked';

    /**
     * Every ability the link workflow needs, in one place.
     *
     * SHARED WITH THE PICKER ON PURPOSE. {@see LeadController::index()} authorizes `scope=linkable`
     * against this same constant, so the search that finds candidates and the hint that offers the
     * button can never disagree about who may link. A user who is shown the control can reach the
     * picker, and a user who can reach the picker is shown the control.
     *
     * The four, and what each one stops:
     *   - crm.conversations.view      reading the inbox at all. Load-bearing: in production crm_bd
     *                                 holds crm.leads.edit but NOT this, so omitting it would let a
     *                                 BD file threads they cannot read.
     *   - crm.conversations.link_lead linking specifically, named separately from `reply` because
     *                                 speaking to a customer and filing a thread are different
     *                                 powers.
     *   - crm.leads.view              seeing leads at all — the picker enumerates them.
     *   - crm.leads.edit              the ability half of LeadPolicy@update, which the link
     *                                 endpoint enforces per-lead. The OWNERSHIP half
     *                                 (`Lead::isVisibleTo`) is per-lead and cannot be answered
     *                                 without a lead, so it is enforced by the picker query and by
     *                                 the endpoint's route binding — not here.
     *
     * @var list<string>
     */
    public const ABILITIES = [
        'crm.conversations.view',
        'crm.conversations.link_lead',
        'crm.leads.view',
        'crm.leads.edit',
    ];

    /**
     * May this user initiate linking at all, ignoring any particular conversation or lead?
     *
     * `can()` rather than any permission-array inspection, so `Gate::before(super_admin)` is
     * honoured. `hasDirectPermission()` or `$user->permissions->contains(...)` would both answer
     * false for a super_admin and are the specific mistakes this method exists to prevent.
     */
    public function authorizes(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        foreach (self::ABILITIES as $ability) {
            if (! $user->can($ability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{enabled: bool, reason: string|null}
     */
    public function for(Conversation $conversation, ?User $viewer): array
    {
        /*
         * PRECEDENCE: STATE OF THE THREAD FIRST, THEN THE VIEWER.
         *
         * This is the opposite order to ReplyCapability, which asks permission first so a refused
         * caller cannot probe the org's outbound configuration. The inversion is deliberate and
         * discloses nothing new: `leadLinked` is already on this same resource for every viewer, so
         * answering `already_linked` tells them only what the payload beside it already says. What
         * it buys is an accurate reason — a crm_admin looking at a linked thread should be told the
         * thread is already linked, not that they lack permission, which would be false.
         *
         * NOTE `lead_id`, NOT `lead`. See the class docblock: on an archived lead the relation is
         * null while `lead_id` is not, and treating that as "unlinked" would advertise a button the
         * server answers with a 409.
         */
        if ($conversation->lead_id !== null) {
            return self::refused(self::ALREADY_LINKED);
        }

        if (! $this->authorizes($viewer)) {
            return self::refused(self::PERMISSION_DENIED);
        }

        return ['enabled' => true, 'reason' => null];
    }

    /**
     * @return array{enabled: bool, reason: string|null}
     */
    private static function refused(string $reason): array
    {
        return ['enabled' => false, 'reason' => $reason];
    }
}
