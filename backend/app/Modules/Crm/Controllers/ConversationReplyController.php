<?php

declare(strict_types=1);

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Requests\ConversationReplyRequest;
use App\Modules\Crm\Resources\ConversationMessageResource;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use App\Modules\Crm\Services\Outbound\OutboundIdempotency;
use App\Modules\Crm\Services\Outbound\OutboundMessageRequest;
use App\Modules\Crm\Services\Outbound\OutboundMessageResult;
use App\Modules\Crm\Services\Outbound\OutboundMessageService;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one HTTP door to replying in a conversation.
 *
 * A SEPARATE CONTROLLER FROM {@see ConversationController} ON PURPOSE. That class states in its
 * own docblock that it is "read-only by construction, not by convention", and the two live inbox
 * endpoints depend on nobody quietly turning it into something else. Adding a write action there
 * would have meant weakening that sentence into a caveat; keeping the write here leaves the read
 * path untouched and makes the boundary visible in the file listing.
 *
 * THIS CONTROLLER DECIDES NOTHING ABOUT THE SEND. It does not choose a provider, a sending
 * account, a recipient or a credential, and it does not touch ProviderAccount, the credential
 * resolver, any Meta adapter or GraphMetaSender. It assembles a request from the bound
 * conversation, the authenticated actor and the validated body, hands it to
 * {@see OutboundMessageService} and translates the answer into HTTP. Every security decision —
 * permission, tenancy, sending resource, credential, messaging window, idempotency, persistence —
 * stays where it is already proven.
 *
 * TENANCY COMES FROM TWO INDEPENDENT PLACES AND THEY MUST AGREE. The conversation arrives by route
 * -model binding, which resolves through `BelongsToOrg` — another tenant's id is a 404 before this
 * method runs, so the endpoint never has to decide whether to disclose that a thread exists. The
 * acting org comes from `CurrentOrg::id()`, never from the payload, and the service refuses with
 * TenantMismatch if the two disagree.
 */
class ConversationReplyController extends Controller
{
    public function __construct(
        private readonly OutboundMessageService $outbound,
        private readonly OutboundIdempotency $idempotency,
    ) {}

    public function store(ConversationReplyRequest $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validated();

        $outboundRequest = new OutboundMessageRequest(
            conversation: $conversation,
            actor: $request->user(),
            body: (string) $validated['body'],
            clientRequestId: (string) $validated['clientRequestId'],
            // Hard-coded, not taken from the payload: the request prohibits the field.
            messageType: 'text',
        );

        /*
         * DERIVED HERE, NOT READ OFF THE RESULT, and that is a deliberate correction rather than a
         * shortcut. OutboundMessageResult CAN carry an idempotency key, but only the service's own
         * refusals and its already-sent replay attach one — an adapter builds its result from
         * inside GraphMetaSender, which has no idea what key the attempt was filed under. So on the
         * success path `$result->idempotencyKey` is null, and looking the row up by it would find
         * nothing.
         *
         * Asking OutboundIdempotency for the key uses the SAME object and the SAME derivation the
         * service uses, so there is no second copy of the rule to drift. The alternative — widening
         * the result type — would have meant editing a send path proven in production for a
         * convenience this call provides for free.
         */
        $key = $this->idempotency->keyFor($outboundRequest);

        $result = $this->outbound->send($outboundRequest, (int) CurrentOrg::id());

        return $result->accepted
            ? $this->accepted($conversation, $result, $key)
            : $this->refused($conversation, $result, $key);
    }

    /** The provider took it. The row is already `sent`; return it through the inbox's own shape. */
    private function accepted(Conversation $conversation, OutboundMessageResult $result, ?string $key): JsonResponse
    {
        return response()->json([
            'accepted' => true,
            'failureCode' => null,
            'reason' => null,
            'retryAllowed' => false,
            'message' => $this->message($conversation, $key),
        ], Response::HTTP_CREATED);
    }

    private function refused(Conversation $conversation, OutboundMessageResult $result, ?string $key): JsonResponse
    {
        $code = $result->failure ?? OutboundFailureCode::NotConfigured;

        return response()->json([
            'accepted' => false,
            'failureCode' => $code->value,

            /*
             * OUR sentence, never Meta's. Every `reason` on an OutboundMessageResult is composed by
             * our own code — GraphMetaSender builds it from the failure code and never reads
             * `error.message`, which can quote the customer's own text back at us. It is here for
             * a human reading a support ticket; a client must branch on `failureCode`.
             */
            'reason' => (string) $result->reason,

            /*
             * THE FIELD THAT MATTERS MOST ON THIS RESPONSE. `isRetryable()` returns true only where
             * non-delivery is established — a 429, rejected by the throttle before processing.
             * ProviderUnavailable and DeliveryUnknown both return FALSE, because either may already
             * have reached the customer, and the server has deliberately left such a row `pending`
             * rather than `failed` so nothing re-sends it. A UI that offered "Retry" on those would
             * undo that guarantee from the other end.
             */
            'retryAllowed' => $code->isRetryable(),

            // Null when the refusal happened before anything durable was written, which is every
            // refusal the gates raise — permission, tenancy, window, missing credential, disabled
            // transport. Non-null only once an attempt reached the provider.
            'message' => $this->message($conversation, $key),
        ], $this->status($code));
    }

    /**
     * The persisted row for this attempt, if one exists.
     *
     * FOUND BY IDEMPOTENCY KEY RATHER THAN RETURNED BY THE SERVICE. OutboundMessageResult carries
     * no model, and giving it one would mean changing the signature of a send path that is proven
     * in production for the sake of a convenience this lookup provides for free. The key is
     * deterministic, so this is one read on a unique index, not a search.
     *
     * The row is serialized through {@see ConversationMessageResource} — the same shape the inbox
     * thread already uses — so there is exactly one place where a message is rendered and exactly
     * one place that could ever leak a provider identifier.
     */
    private function message(Conversation $conversation, ?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        $message = ConversationMessage::query()
            ->withoutGlobalScopes()
            ->where('org_id', (int) $conversation->org_id)
            ->where('idempotency_key', $key)
            ->first();

        return $message === null
            ? null
            : (new ConversationMessageResource($message))->toArray(request());
    }

    /**
     * Our failure vocabulary, mapped onto HTTP.
     *
     * TenantMismatch is a 404 and not a 403, deliberately: a caller who reached this line already
     * passed route-model binding, so the mismatch means something is inconsistent rather than that
     * they are probing — but answering 403 would still confirm the thread exists to anyone who
     * found a way to bind it. 404 discloses nothing either way.
     *
     * The two ambiguous codes are 502 rather than 4xx because nothing about them is the caller's
     * fault and nothing about them is settled; pairing that status with `retryAllowed: false` is
     * the whole message.
     */
    private function status(OutboundFailureCode $code): int
    {
        return match ($code) {
            OutboundFailureCode::Unauthorized => Response::HTTP_FORBIDDEN,
            OutboundFailureCode::TenantMismatch => Response::HTTP_NOT_FOUND,

            OutboundFailureCode::InvalidRequest,
            OutboundFailureCode::UnsupportedChannel => Response::HTTP_UNPROCESSABLE_ENTITY,

            // The request was fine; the org's current state will not carry it.
            OutboundFailureCode::OutsideMessagingWindow,
            OutboundFailureCode::NotConfigured,
            OutboundFailureCode::ResourceUnmapped,
            OutboundFailureCode::ResourceAmbiguous,
            OutboundFailureCode::ResourceUnavailable,
            OutboundFailureCode::CredentialRejected,
            OutboundFailureCode::PolicyViolation => Response::HTTP_CONFLICT,

            OutboundFailureCode::ProviderRateLimited => Response::HTTP_TOO_MANY_REQUESTS,

            OutboundFailureCode::ProviderUnavailable,
            OutboundFailureCode::DeliveryUnknown => Response::HTTP_BAD_GATEWAY,
        };
    }
}
