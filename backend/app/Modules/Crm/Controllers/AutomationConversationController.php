<?php

declare(strict_types=1);

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Requests\IngestConversationMessageRequest;
use App\Modules\Crm\Resources\InboundIngestResultResource;
use App\Modules\Crm\Services\Inbound\InboundConversationService;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;

/**
 * The trusted ingest boundary for integrations (Phase 3B).
 *
 * NOT A META WEBHOOK. It accepts an ALREADY NORMALIZED conversation turn and nothing
 * provider-shaped: no `hub.challenge` handshake, no `entry[].messaging[]`, no signature header.
 * Translating a vendor's payload is an adapter's job in a later phase; if that translation lived
 * here, every new provider would mean editing the authorization boundary.
 *
 * Authorized ONLY by `crm.conversations.ingest` — deliberately not by `can:view,lead`, exactly as
 * the RC3 automation send endpoint is, because an ownerless integration account legitimately acts
 * without owning anything. That ability is granted to no human role; it is held by the per-org
 * service accounts `crm:integration-account` provisions.
 *
 * THIN BY CONTRACT. Idempotency, conversation resolution, lead matching, activity and events all
 * belong to InboundConversationService and are not repeated here — a second copy of that logic on
 * the HTTP side is how two ingest paths drift apart.
 */
class AutomationConversationController extends Controller
{
    public function ingest(
        IngestConversationMessageRequest $request,
        InboundConversationService $service,
    ): JsonResponse {
        /*
         * The tenant comes from the authenticated account, never from the body (the request
         * refuses org_id/tenant_id outright). `set-current-org` has already pinned it from the
         * account's single org membership.
         *
         * A service account with NO membership resolves to null, and the ingest service would
         * throw — a 500 that says nothing. This is the one condition worth naming: an integration
         * whose identity was provisioned incompletely gets told so.
         */
        if (CurrentOrg::id() === null) {
            return response()->json([
                'message' => 'This integration account is not a member of any organisation.',
                'code' => 'integration_account_has_no_org',
            ], 403);
        }

        $result = $service->ingest($request->toDomainMessage());

        return (new InboundIngestResultResource($result))
            ->response()
            // 201 for work that was done, 200 for a replay that changed nothing — so a retrying
            // integration can tell the two apart without parsing the body.
            ->setStatusCode($result->wasCreated() ? 201 : 200);
    }
}
