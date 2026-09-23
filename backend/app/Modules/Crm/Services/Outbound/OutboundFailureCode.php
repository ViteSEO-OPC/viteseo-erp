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
     * No credential is available for the resolved resource.
     *
     * This is the case Phase 3F.1 always ends on, because the only credential resolver wired in
     * this phase returns nothing by construction. It is a refusal, never a retry: retrying a
     * missing credential just burns the queue until an operator connects an account.
     */
    case NotConfigured = 'not_configured';

    /** Whether a caller should ever try this again unchanged. Every current case: no. */
    public function isRetryable(): bool
    {
        return false;
    }
}
