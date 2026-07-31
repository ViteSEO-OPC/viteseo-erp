<?php

namespace App\Modules\Crm\Services\Mail\Contracts;

use App\Modules\Crm\Services\Mail\Inbound\MailboxFetchResult;

/**
 * Inbound mailbox synchronization — the inbound counterpart to EmailProvider, kept as a
 * SEPARATE contract on purpose (Interface Segregation): send-only providers (Resend, SES)
 * must not be forced to implement receiving. A provider class may implement both interfaces.
 *
 * The business layer (InboundEmailService) depends only on this contract + the Inbound DTOs,
 * so Gmail / Microsoft Graph / IMAP are interchangeable with no business-layer change.
 *
 * PULL-BASED. The contract used to carry `watch()`, `unwatch()` and `parseNotification()` for
 * provider push subscriptions (Gmail → Pub/Sub → webhook). Inbound is now driven by a scheduled
 * poll, so a provider needs to answer exactly one question — "what has arrived since this cursor?"
 * — and nothing about subscriptions or push envelopes. That also widens the contract's reach:
 * plain IMAP could not have implemented the push methods at all.
 */
interface MailboxSyncProvider
{
    /**
     * Fetch messages newer than $cursor, normalized to InboundMessage, plus the advanced cursor.
     *
     * A NULL cursor means "this mailbox has never been synced" and the provider is expected to
     * BOOTSTRAP: ingest a bounded recent window and return a usable cursor to resume from. It must
     * not return an empty result, or a fresh mailbox would never begin syncing. Returning a null
     * cursor in the result means "nothing to advance to"; the caller then keeps the cursor it has.
     *
     * Implementations must tolerate being called again over a range they have already returned —
     * ingest is idempotent on (provider, message_id), and both bootstrap and stale-cursor recovery
     * deliberately re-offer messages.
     */
    public function fetchSince(string $mailbox, ?string $cursor): MailboxFetchResult;
}
