<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

use App\Modules\Crm\Services\Inbound\Adapters\RejectionCode;

/**
 * Why an outbound send did not happen.
 *
 * SEPARATE FROM THE INBOUND VOCABULARY ON PURPOSE. Inbound's {@see RejectionCode}
 * answers "why did this arriving item produce no message", which is a question about someone else's
 * data. These answer "why did we decline to speak", which is a question about our own configuration
 * and permissions. Sharing one enum would force each side to carry cases the other can never emit,
 * and a reader of a log line could no longer tell which direction failed.
 *
 * EVERY CASE IS A REFUSAL, NOT A DELIVERY FAILURE. Nothing here means "the provider rejected it" —
 * that outcome does not exist yet because Phase 3F.1 makes no provider call at all. When a real
 * transport lands, provider-reported failures get their own cases and their own retry semantics;
 * these stay what they are, which is "we stopped before the wire".
 */
enum OutboundFailureCode: string
{
    /** The actor may not reply in this org. Checked before anything else is resolved. */
    case Unauthorized = 'unauthorized';

    /** The conversation does not belong to the org the caller is acting in. */
    case TenantMismatch = 'tenant_mismatch';

    /** No adapter claims this provider/channel pair, or more than one does. */
    case UnsupportedChannel = 'unsupported_channel';

    /** The adapter rejected the normalized request — empty body, oversize, wrong message type. */
    case InvalidRequest = 'invalid_request';

    /** The conversation's receiving Page/IG account is not mapped to this org, or is inactive. */
    case ResourceUnmapped = 'resource_unmapped';

    /** The org owns several candidate resources and nothing identifies which one to speak from. */
    case ResourceAmbiguous = 'resource_ambiguous';

    /**
     * The resource exists and belongs to this org, but its grant has been withdrawn.
     *
     * Distinct from ResourceUnmapped on purpose: "you never connected a Page" and "the Page you
     * connected has been disconnected" send an operator to two different screens.
     */
    case ResourceUnavailable = 'resource_unavailable';

    /**
     * No credential is available for the resolved resource.
     *
     * This is the case Phase 3F.1 always ends on, because the only credential resolver wired in
     * this phase returns nothing by construction. It is a refusal, never a retry: retrying a
     * missing credential just burns the queue until an operator connects an account.
     */
    case NotConfigured = 'not_configured';

    /*
    |--------------------------------------------------------------------------
    | Phase 3G — outcomes that only exist once there is a transport
    |--------------------------------------------------------------------------
    | Every case below is derived from an HTTP status and Meta's NUMERIC error
    | code. None of them ever carries Meta's prose: a Graph error message can
    | quote the customer's own message back at us, and these values are written
    | into `crm_messages.failure_code` and into log context.
    */

    /** The provider could not be reached, or answered 5xx. The attempt did not happen. */
    case ProviderUnavailable = 'provider_unavailable';

    /** The provider is throttling us. Distinct from unavailable: the fix is time, not repair. */
    case ProviderRateLimited = 'provider_rate_limited';

    /**
     * Refused locally because the standard reply window has closed, or never opened.
     *
     * Emitted BEFORE any HTTP request and before anything is persisted. Meta enforces its own
     * window regardless; this exists so the operator learns at the keyboard rather than from a
     * failed delivery, and so no phantom outbound row appears in a thread the customer can see.
     */
    case OutsideMessagingWindow = 'outside_messaging_window';

    /** Meta refused on policy or permission grounds. Not retryable, and not a bug to work around. */
    case PolicyViolation = 'policy_violation';

    /**
     * The credential was rejected by the provider — expired, revoked, or missing a permission.
     *
     * Deliberately NOT folded into NotConfigured. "You never connected an account" and "the token
     * you connected no longer works" are different problems with different fixes, and collapsing
     * them would send an operator to reconnect an account that is already connected.
     */
    case CredentialRejected = 'credential_rejected';

    /**
     * THE IMPORTANT ONE. The request may or may not have reached Meta; we never saw the answer.
     *
     * A connection or read timeout on a POST leaves delivery genuinely unknown, and the Send API
     * gives us no idempotency token with which to ask. So this is neither `sent` (we would be
     * claiming a delivery we cannot prove) nor `failed` (we would be denying one that may have
     * happened). The row stays `pending` carrying this code, and a replay of the same client
     * request id refuses rather than posting a second time. A human resolves it.
     */
    case DeliveryUnknown = 'delivery_unknown';

    /**
     * Phase 3H.5A — an operator looked at the real thread (in Meta's inbox) and confirmed that an
     * unresolved message never reached the customer.
     *
     * A HUMAN JUDGEMENT, NOT A PROVIDER ANSWER, and named so it can never be mistaken for one. No
     * provider code fits: every other code here was derived from something Meta returned, and
     * reusing one would claim evidence we do not have. Written only by the manual resolution
     * endpoint, never by the send path, and it turns the row `failed` — which is the honest
     * statement of "not delivered" and keeps the existing semantics that a definitively failed row
     * may be deliberately attempted again.
     *
     * 27 characters, inside `failure_code`'s varchar(32).
     */
    case OperatorConfirmedNotSent = 'operator_confirmed_not_sent';

    /**
     * Whether this outcome leaves it genuinely unknown whether the customer got the message.
     *
     * THE DIVIDING LINE OF THIS WHOLE PATH, and it is about EVIDENCE, not about severity. A 4xx
     * with a Graph error envelope is proof that Meta received the request, processed it and
     * refused: the message was not delivered, and we know it. A 5xx is not proof of anything. A
     * Graph 500 may be raised after the send was queued, and a 502/504 from an edge in front of
     * Graph may mean the request never reached the backend OR that it was processed and the answer
     * was lost on the way home. That is the same epistemic position as a timeout, so it gets the
     * same treatment.
     *
     * A code that returns true here must never be written as `failed`: doing so would let a replay
     * of the same client request id reach the transport again, and the cost of being wrong is a
     * real customer receiving the same message twice from a business.
     *
     * ProviderUnavailable and DeliveryUnknown stay SEPARATE CASES even though they are handled
     * identically, because the operator-facing question differs: "Meta returned an error" sends
     * someone to Meta's status page, while "we never heard back" sends them to the thread.
     */
    public function leavesDeliveryUnresolved(): bool
    {
        return match ($this) {
            self::DeliveryUnknown, self::ProviderUnavailable => true,
            default => false,
        };
    }

    /**
     * Whether a caller may send this again, unchanged, on the same client request id.
     *
     * TRUE ONLY WHERE NON-DELIVERY IS ESTABLISHED. A 429 is rejected by the throttle before the
     * message is processed, so replaying it cannot duplicate anything — that is the one case left.
     *
     * ProviderUnavailable USED TO BE LISTED HERE and is not any more. Nothing consumed this method
     * at the time, so it never caused a double send; but it advertised the ambiguous branch as
     * retry-safe, and the first queue written against it would have inherited that mistake. The
     * invariant is now stated once, structurally: anything ambiguous is never retryable.
     */
    public function isRetryable(): bool
    {
        if ($this->leavesDeliveryUnresolved()) {
            return false;
        }

        return $this === self::ProviderRateLimited;
    }
}
