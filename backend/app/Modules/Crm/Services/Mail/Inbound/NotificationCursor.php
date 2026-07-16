<?php

namespace App\Modules\Crm\Services\Mail\Inbound;

/**
 * A push notification decoded to its provider-neutral essentials: which mailbox changed and,
 * when the payload carries it, a hint cursor. The sync job resolves the rest via fetchSince().
 */
final class NotificationCursor
{
    public function __construct(
        public readonly string $mailbox,
        public readonly ?string $cursor = null,
    ) {}
}
