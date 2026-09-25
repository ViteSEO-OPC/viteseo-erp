<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\LeadAssignmentService;
use Illuminate\Support\Facades\DB;

/**
 * Attaches an UNLINKED conversation to a lead, by hand, exactly once.
 *
 * WHY THIS EXISTS AT ALL. {@see ConversationLeadMatcher} can only attach a thread it can identify,
 * and its two identity strategies need a sender email or a sender phone number. Messenger and
 * Instagram DM supply neither — a PSID and an IGSID are opaque — so in production every inbound
 * chat thread matches `none` and stays unlinked forever. A person has to say who it is, and this is
 * where that decision lands.
 *
 * LINK ONLY. NULL → lead, and nothing else:
 *
 *   - RELINK (lead A → lead B) is REFUSED, not silently applied. {@see ConversationResolver::advance()}
 *     states the rule this upholds — "an existing association is never replaced… doing it here would
 *     silently move one prospect's conversation to another" — and a manual path that ignored it
 *     would be the first code in the module able to move a private conversation onto the wrong
 *     company's record.
 *   - UNLINK (lead → NULL) is not implemented here at all. There is no method for it, which is the
 *     honest way to ship "not yet" — see the linking note on Phase 3H.5.
 *
 * THE LINK IS STICKIER THAN IT LOOKS, AND WHOEVER CALLS THIS SHOULD KNOW. Beyond making THIS thread
 * sticky (the matcher short-circuits on a non-null `lead_id`), the new association becomes EVIDENCE:
 * {@see ConversationLeadMatcher::byContactIdentity()} reads other conversations' `lead_id` for the
 * same provider/channel/contact, so every FUTURE thread from that same person auto-attaches to the
 * lead chosen here. Until unlink exists, a wrong choice cannot be corrected in the product and it
 * replicates. That is a product decision taken deliberately for 3H.3A, not an oversight — the UI
 * that calls this owes the operator a confirmation step.
 *
 * NOTHING ABOUT PROVIDERS HAPPENS HERE. No Meta call, no credential, no provider account, no
 * outbound anything. Linking is a CRM bookkeeping act.
 */
final class ConversationLeadLinker
{
    /** The thread was unlinked and now names this lead. One Activity was written. */
    public const OUTCOME_LINKED = 'linked';

    /** The thread already named this exact lead. Idempotent no-op; no Activity. */
    public const OUTCOME_UNCHANGED = 'unchanged';

    /** The thread already names a DIFFERENT lead. Nothing was written; no Activity. */
    public const OUTCOME_CONFLICT = 'conflict';

    /**
     * @param  int  $actingOrgId  the tenant the caller is acting in, resolved from server state —
     *                            never from a payload, and never read from CurrentOrg in here
     *                            (this class must stay callable from a job, where that is null)
     * @return self::OUTCOME_*
     */
    public function link(Conversation $conversation, Lead $lead, User $actor, int $actingOrgId): string
    {
        return DB::transaction(function () use ($conversation, $lead, $actor, $actingOrgId): string {
            /*
             * THE RACE GUARD IS `lead_id IS NULL` IN THE UPDATE ITSELF, and it has to be, because
             * the obvious implementation is wrong. Reading `$conversation->lead_id`, deciding, then
             * calling save() is last-write-wins: two operators looking at the same unlinked thread
             * both read NULL, both decide "link", and the second one's write silently replaces the
             * first one's lead. The database is the only thing that can arbitrate that, so the
             * decision is expressed as a predicate it evaluates while holding the row.
             *
             * `org_id` is restated on purpose. The query builder carries no `BelongsToOrg` scope —
             * that is an Eloquent global scope — so without this line a foreign conversation id
             * would be updated on a bare primary key. The controller has already proven tenancy;
             * this is the second, independent place, and tenant isolation should not rest on one.
             *
             * InnoDB takes a CURRENT read for an UPDATE, not a snapshot one, so this blocks on
             * (and then sees) whatever ConversationResolver::lockedLookup() committed under its
             * SELECT … FOR UPDATE. A concurrent ingest that attaches a matched lead therefore makes
             * this update affect 0 rows and fall through to the conflict branch, rather than
             * overwriting it.
             */
            $affected = DB::table('crm_conversations')
                ->where('id', $conversation->id)
                ->where('org_id', $actingOrgId)
                ->whereNull('lead_id')
                ->update([
                    'lead_id' => $lead->id,
                    'updated_at' => now(),
                ]);

            if ($affected === 1) {
                $this->recordActivity($conversation, $lead, $actor, $actingOrgId);

                // The in-memory model was mutated behind Eloquent's back; leaving it stale would
                // hand the caller a conversation that still claims to be unlinked.
                $conversation->refresh();

                return self::OUTCOME_LINKED;
            }

            /*
             * Zero rows. Either this thread was already linked before we arrived, or a concurrent
             * writer took it between the caller's page load and this statement. Re-read the
             * CURRENT value — not the one on the stale in-memory model — and let it decide which.
             */
            $current = DB::table('crm_conversations')
                ->where('id', $conversation->id)
                ->where('org_id', $actingOrgId)
                ->value('lead_id');

            return $current !== null && (int) $current === (int) $lead->id
                ? self::OUTCOME_UNCHANGED
                : self::OUTCOME_CONFLICT;
        });
    }

    /**
     * The durable record of the decision, on the LEAD's timeline.
     *
     * Subject is the lead, not the conversation, following `lead.assigned`: this is a change to what
     * the lead is, and it belongs beside the emails, calls and handovers a person reads when they
     * ask "what happened to this account?". `causer_id` is the operator — unlike an inbound turn,
     * which has no CRM user behind it and is logged with a null causer.
     *
     * WRITTEN ONLY WHEN SOMETHING CHANGED, and inside the same transaction as the change. An
     * idempotent repeat writes nothing, mirroring {@see LeadAssignmentService::assign()},
     * which returns early on a no-op handover for exactly this reason: an audit trail that records
     * non-events is one a person stops reading.
     *
     * PROPERTIES ARE IDENTIFIERS ONLY — the same rule {@see InboundConversationService::logActivity()}
     * follows. No message body, no `external_contact_id`, no payload, no provider account, no
     * customer email or phone, no credential material of any kind.
     *
     * `previous_lead_id` is recorded as null rather than omitted: it is always null today, because
     * only the NULL → lead transition exists, and stating it makes this row readable if relink is
     * ever built rather than ambiguous in hindsight.
     */
    private function recordActivity(Conversation $conversation, Lead $lead, User $actor, int $actingOrgId): void
    {
        /** @var Activity $activity */
        $activity = $lead->activities()->make([
            'event' => 'conversation.linked',
            'description' => sprintf(
                '%s linked a %s conversation to %s',
                $actor->name,
                $conversation->channel,
                $lead->business_name,
            ),
            'properties' => [
                'conversation_id' => (int) $conversation->id,
                'provider' => $conversation->provider,
                'channel' => $conversation->channel,
                'previous_lead_id' => null,
            ],
            'causer_id' => $actor->id,
        ]);

        // BelongsToOrg would stamp this from CurrentOrg, which is correct in a request and NULL in
        // a job. The org was already proven twice above; use the proven value.
        $activity->org_id = $actingOrgId;
        $activity->save();
    }
}
