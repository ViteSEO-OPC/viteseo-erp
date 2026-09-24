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
     * Whether a caller should ever try this again unchanged.
     *
     * DeliveryUnknown is emphatically NOT retryable, and it is the case most likely to be
     * mis-handled: it looks like a transient network error, and treating it as one is exactly how
     * a customer receives the same message twice.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::ProviderUnavailable, self::ProviderRateLimited => true,
            default => false,
        };
    }
}
