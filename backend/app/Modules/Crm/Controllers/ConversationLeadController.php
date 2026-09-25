<?php

declare(strict_types=1);

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Requests\LinkConversationLeadRequest;
use App\Modules\Crm\Resources\LinkedLeadResource;
use App\Modules\Crm\Services\Inbound\ConversationLeadLinker;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one HTTP door to attaching a conversation to a lead.
 *
 * A THIRD CONTROLLER, FOR THE SAME REASON THERE WAS A SECOND. {@see ConversationController} says in
 * its own docblock that it is "read-only by construction, not by convention", and Phase 3H.1 put
 * the reply write in {@see ConversationReplyController} rather than weaken that sentence into a
 * caveat. This is the same trade: the read path stays untouched and the boundary is visible in the
 * file listing.
 *
 * IT IS ALSO A DIFFERENT KIND OF WRITE FROM A REPLY, and keeping them apart matters. A reply leaves
 * the building and reaches a real person under the company's name; a link is internal bookkeeping
 * that no customer ever sees. They deserve separate abilities, separate throttles and separate
 * audit vocabulary, and sharing a controller would have invited them to share a policy by accident.
 *
 * THIS CONTROLLER DECIDES ALMOST NOTHING. Both participants arrive by route-model binding, which is
 * where tenancy is actually enforced; the ordering rules, the race guard and the audit record live
 * in {@see ConversationLeadLinker}. What is left here is the tenancy re-assertion below and the
 * translation of three outcomes into three status codes.
 */
class ConversationLeadController extends Controller
{
    public function __construct(private readonly ConversationLeadLinker $linker) {}

    public function store(
        LinkConversationLeadRequest $request,
        Conversation $conversation,
        Lead $lead,
    ): JsonResponse {
        // Consumed for its prohibitions — a caller that tried to name the lead, the thread or the
        // tenant in the body has already been answered with a 422 before this line.
        $request->validated();

        $orgId = (int) CurrentOrg::id();

        /*
         * TENANCY, ASSERTED A SECOND TIME AND DELIBERATELY REDUNDANTLY.
         *
         * Both models were bound through `BelongsToOrg`, so a foreign id is a 404 before this
         * method exists, and the lead was additionally filtered by `SoftDeletes`, so an archived
         * lead is a 404 too. None of that is in doubt. It is restated because the alternative is a
         * system whose tenant isolation rests on one mechanism, and because this endpoint is the
         * first place two independently-bound records from two different tables must agree with
         * each other — a question binding does not ask.
         *
         * 404 rather than 403: a caller who somehow reached a mismatch learns only that there is
         * nothing here, which is the same thing they would learn about a thread in another tenant
         * that does exist. This mirrors the reply endpoint's choice to answer TenantMismatch with
         * 404 rather than confirm the row.
         */
        if ((int) $conversation->org_id !== $orgId
            || (int) $lead->org_id !== $orgId
            || (int) $conversation->org_id !== (int) $lead->org_id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $outcome = $this->linker->link($conversation, $lead, $request->user(), $orgId);

        return match ($outcome) {
            ConversationLeadLinker::OUTCOME_LINKED => $this->ok($lead, changed: true),
            ConversationLeadLinker::OUTCOME_UNCHANGED => $this->ok($lead, changed: false),
            default => $this->conflict($conversation, $lead),
        };
    }

    /**
     * A link that landed, or a repeat of one that already had.
     *
     * BOTH ARE 200 AND BOTH RETURN THE LEAD. A client that retried — a double-click, a flaky
     * connection, a replayed request — gets the same answer as the client that won, and can render
     * the same thing. `changed` is there for anything that wants to tell them apart (a toast, an
     * analytics event); nothing about correctness depends on reading it.
     */
    private function ok(Lead $lead, bool $changed): JsonResponse
    {
        return response()->json([
            'changed' => $changed,
            'lead' => (new LinkedLeadResource($lead))->toArray(request()),
        ], Response::HTTP_OK);
    }

    /**
     * The thread already belongs to a different lead.
     *
     * `lead` IS NULL HERE ON PURPOSE. Naming the lead that won would answer a question the caller
     * did not ask and may not be authorized to ask: `LeadPolicy@view` gates which leads a BD may
     * see, and a conflict response that volunteered a business name would be a way to read one
     * around that boundary. The operator is told the state of the thread, and can look at the
     * thread to learn the rest.
     *
     * The internal detail goes to the log, where an engineer looks, and not to the browser — the
     * same split {@see ConversationReplyController::refused()} makes.
     */
    private function conflict(Conversation $conversation, Lead $lead): JsonResponse
    {
        Log::warning('crm.conversation.link_conflict', [
            'conversation_id' => (int) $conversation->id,
            'org_id' => (int) $conversation->org_id,
            'requested_lead_id' => (int) $lead->id,
        ]);

        return response()->json([
            'changed' => false,
            'lead' => null,
            'message' => 'This conversation is already linked to a different lead.',
        ], Response::HTTP_CONFLICT);
    }
}
