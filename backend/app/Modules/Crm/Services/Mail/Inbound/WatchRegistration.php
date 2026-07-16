<?php

namespace App\Modules\Crm\Services\Mail\Inbound;

/**
 * Result of arming a mailbox watch/subscription: the initial sync cursor and, for push
 * providers, when the watch expires and must be renewed (null for pull providers like IMAP).
 */
final class WatchRegistration
{
    public function __construct(
        public readonly ?string $cursor,
        public readonly ?string $expiresAt = null,
    ) {}
}
