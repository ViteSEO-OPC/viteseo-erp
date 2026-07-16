<?php

namespace App\Modules\Crm\Services\Mail\Contracts;

use App\Modules\Crm\Services\Mail\Inbound\MailboxFetchResult;
use App\Modules\Crm\Services\Mail\Inbound\NotificationCursor;
use App\Modules\Crm\Services\Mail\Inbound\WatchRegistration;

/**
 * Inbound mailbox synchronization — the inbound counterpart to EmailProvider, kept as a
 * SEPARATE contract on purpose (Interface Segregation): send-only providers (Resend, SES)
 * must not be forced to implement receiving. A provider class may implement both interfaces.
 *
 * The business layer (InboundEmailService) depends only on this contract + the Inbound DTOs,
 * so Gmail / Microsoft Graph / IMAP are interchangeable with no business-layer change.
 */
interface MailboxSyncProvider
{
    /** Arm the push watch / subscription for a mailbox; returns the initial cursor + expiry. */
    public function watch(string $mailbox): WatchRegistration;

    /** Best-effort teardown of the watch. */
    public function unwatch(string $mailbox): void;

    /** Fetch messages newer than $cursor, normalized to InboundMessage, plus the advanced cursor. */
    public function fetchSince(string $mailbox, ?string $cursor): MailboxFetchResult;

    /**
     * Decode a provider push payload (e.g. a Pub/Sub envelope) into {mailbox, cursor}.
     * Pull providers (IMAP) return the mailbox with a null cursor.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseNotification(array $payload): NotificationCursor;
}
