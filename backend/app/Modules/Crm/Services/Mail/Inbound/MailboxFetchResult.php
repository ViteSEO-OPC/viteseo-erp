<?php

namespace App\Modules\Crm\Services\Mail\Inbound;

/**
 * Result of a provider fetch: the new inbound messages plus the advanced sync cursor to persist.
 */
final class MailboxFetchResult
{
    /**
     * @param  list<InboundMessage>  $messages
     */
    public function __construct(
        public readonly array $messages,
        public readonly ?string $cursor,
    ) {}
}
