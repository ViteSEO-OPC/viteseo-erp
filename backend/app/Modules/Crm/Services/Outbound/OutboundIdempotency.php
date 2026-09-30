<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

use App\Modules\Crm\Services\Inbound\ConversationIdempotency;

/**
 * The key that decides whether two send attempts are the same send.
 *
 * WHY THIS IS NOT THE INBOUND RULE. {@see ConversationIdempotency}
 * can fall back to hashing the payload, because for arriving traffic two identical bodies almost
 * always mean the provider redelivered one event. Outbound is the opposite: a user typing "ok" and
 * then "ok" again a minute later is two messages a customer should receive twice, and deduplicating
 * them would silently swallow real intent. Content is therefore NEVER part of an outbound key.
 *
 * THE CLIENT OWNS THE KEY. Only the caller knows whether a second request is a retry of the first
 * attempt or a new thing to say, so the identity comes from a `clientRequestId` minted once when
 * the user commits to sending and replayed unchanged on every retry of that attempt. This covers
 * the four cases that matter:
 *
 *  - double click — the UI reuses the id it minted for the first click;
 *  - HTTP retry — the client replays its own request, id included;
 *  - queue retry — the job carries the id it was dispatched with;
 *  - provider timeout — the ambiguous case, where we do not know whether the provider accepted it;
 *    replaying the same key is what lets a future transport ask "did this one already land?"
 *    instead of sending twice.
 *
 * NO ID MEANS NOT IDEMPOTENT, and that is a deliberate refusal to be clever. Inventing a key from
 * the body, the actor and a timestamp window would dedupe by coincidence: two legitimate identical
 * messages inside the window collapse into one, and a genuine retry outside it sends twice. Both
 * failures are silent and neither is recoverable, so a caller that supplies no id gets an honest
 * null and the attempt is treated as unique.
 *
 * SCOPED BY ORG AND CONVERSATION so a client id can only ever collide with itself: two tenants
 * generating the same UUID cannot reach each other's threads, and the same id used on a different
 * conversation is a different send.
 *
 * WHERE THE UNIQUENESS IS ENFORCED. Not here — this class only derives a string. The constraint
 * that makes it binding is `crm_messages UNIQUE(org_id, idempotency_key)`, which already exists and
 * already covers outbound rows; Phase 3F.1 persists nothing, so nothing enforces it yet. See the
 * phase report for the one column outbound persistence still needs.
 */
final class OutboundIdempotency
{
    /** Marks the derived key as outbound, so it can never collide with an inbound key. */
    private const PREFIX = 'outbound';

    /** `crm_messages.idempotency_key` is varchar(64); a hex SHA-256 is exactly 64. */
    public const MAX_LENGTH = 64;

    /**
     * The deterministic key for this attempt, or null when the caller supplied no request id.
     *
     * Null is the honest answer for "this attempt has no identity", and callers must treat it as
     * "cannot be deduplicated" rather than substituting something of their own.
     */
    public function keyFor(OutboundMessageRequest $request): ?string
    {
        $clientRequestId = $request->clientRequestId === null
            ? ''
            : trim($request->clientRequestId);

        if ($clientRequestId === '') {
            return null;
        }

        return hash('sha256', implode("\0", [
            self::PREFIX,
            (string) $request->orgId(),
            (string) $request->conversation->id,
            $clientRequestId,
        ]));
    }
}
