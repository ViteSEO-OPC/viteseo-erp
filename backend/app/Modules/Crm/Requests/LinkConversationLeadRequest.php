<?php

declare(strict_types=1);

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Services\Inbound\ConversationLeadLinker;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The manual-link payload: nothing at all, and that is the whole point.
 *
 * BOTH PARTICIPANTS ARE IN THE URL, so there is no body to validate. The conversation and the lead
 * arrive through route-model binding, which resolves each of them through `BelongsToOrg` — and, for
 * the lead, through `SoftDeletes` as well. A cross-org id, a nonexistent id and an archived lead
 * therefore all become one indistinguishable 404 before this class is constructed, with no `exists:`
 * rule to write and no difference in answer for a caller to measure.
 *
 * WHAT IS PROHIBITED IS THE CONTRACT. Every field below is decided by the server or supplied by the
 * URL, so none of them could take effect even if accepted — {@see ConversationLeadLinker}
 * takes two models and an org id, and reads nothing from the request. They are listed so that a
 * client which believes it is choosing the lead, the tenant or the thread FAILS LOUDLY with a 422
 * instead of being silently ignored by `validated()` and quietly linking something else.
 *
 * This mirrors {@see ConversationReplyRequest}, which states the same rule for the same reason.
 */
class LinkConversationLeadRequest extends FormRequest
{
    /**
     * Authorization is the route's: auth:sanctum, the CRM gates, `permission:crm.conversations.view`,
     * `permission:crm.conversations.link_lead`, and `can:update,lead` (LeadPolicy@update — the
     * `crm.leads.edit` ability AND the per-BD ownership boundary).
     */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * The lead is the route's, not the caller's. Accepting an id here would reintroduce
             * precisely the unscoped lookup the route-bound design exists to avoid.
             */
            'leadId' => ['prohibited'],
            'lead_id' => ['prohibited'],
            'lead' => ['prohibited'],

            // Likewise the thread.
            'conversationId' => ['prohibited'],
            'conversation_id' => ['prohibited'],
            'conversation' => ['prohibited'],

            // The tenant comes from the authenticated session, never from a payload.
            'orgId' => ['prohibited'],
            'org_id' => ['prohibited'],

            /*
             * Provider identity. A link says nothing about where the thread lives, and a caller
             * that tries to restate it has misunderstood the operation — these are recorded once at
             * ingest from a signature-verified payload and are never client input.
             */
            'provider' => ['prohibited'],
            'channel' => ['prohibited'],
            'provider_account_id' => ['prohibited'],
            'providerAccountId' => ['prohibited'],
            'external_contact_id' => ['prohibited'],
            'externalContactId' => ['prohibited'],
            'external_conversation_id' => ['prohibited'],
            'externalConversationId' => ['prohibited'],
        ];
    }
}
