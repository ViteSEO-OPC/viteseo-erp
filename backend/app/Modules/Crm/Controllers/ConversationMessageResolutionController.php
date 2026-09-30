<?php

declare(strict_types=1);

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Requests\ResolveConversationMessageRequest;
use App\Modules\Crm\Resources\ConversationMessageResource;
use App\Modules\Crm\Services\Outbound\OutboundDeliveryResolver;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 3H.5A — record an operator's verdict on an outbound message whose delivery is unknown.
 *
 * NEVER SENDS ANYTHING. There is no path from here to a provider: the verdict changes one row and
 * writes one Activity, through {@see OutboundDeliveryResolver}.
 *
 * TENANCY FROM TWO PLACES. Both models arrive by route-model binding under `BelongsToOrg`, and the
 * route binds `{message}` through the conversation's own messages, so a message from another thread
 * or another tenant is a 404 before this runs. It is re-asserted below anyway, because this is a
 * write and two independently-bound records must agree.
 */
class ConversationMessageResolutionController extends Controller
{
    public function __construct(private readonly OutboundDeliveryResolver $resolver) {}

    public function store(
        ResolveConversationMessageRequest $request,
        Conversation $conversation,
        ConversationMessage $message,
    ): JsonResponse {
        $orgId = (int) CurrentOrg::id();

        if ((int) $conversation->org_id !== $orgId
            || (int) $message->org_id !== $orgId
            || (int) $message->conversation_id !== (int) $conversation->id
            || $message->direction !== 'outbound') {
            abort(Response::HTTP_NOT_FOUND);
        }

        $outcome = (string) $request->validated('outcome');

        $resolved = $this->resolver->resolve($conversation, $message, $outcome, $request->user(), $orgId);

        $row = (new ConversationMessageResource($message->refresh()))->toArray($request);

        if (! $resolved) {
            /*
             * Someone or something settled it first — another operator, or the original request
             * finally recording its result — or it was never unresolved. The current row is returned
             * so the client can show the truth instead of its own stale view.
             */
            return response()->json([
                'resolved' => false,
                'outcome' => null,
                'reason' => 'This message is no longer waiting for confirmation.',
                'message' => $row,
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'resolved' => true,
            'outcome' => $outcome,
            'reason' => null,
            'message' => $row,
        ], Response::HTTP_OK);
    }
}
