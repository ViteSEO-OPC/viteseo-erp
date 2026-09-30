<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Contracts;

use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use App\Modules\Crm\Services\Mail\Contracts\MailboxSyncProvider;

/**
 * Normalization boundary for one inbound conversation provider (Meta Messenger, Instagram DM,
 * WhatsApp, web chat, n8n relay).
 *
 * Kept deliberately narrow, following {@see MailboxSyncProvider}:
 * an adapter answers exactly one question — "what conversation turns are in this payload?" — and
 * nothing about transport, subscriptions, verification handshakes or sending. A provider that can
 * only receive is not forced to implement replying, and the Meta webhook verification handshake
 * (a transport concern) stays out of the business contract entirely.
 *
 * The pipeline depends only on this interface and on InboundConversationMessage, so adding a
 * provider touches no business logic.
 */
interface InboundConversationProvider
{
    /**
     * Whether this adapter owns the given provider/channel pair.
     *
     * Both are required because a single vendor exposes several channels whose payload shapes
     * differ — Messenger and Lead Ads arrive from the same Meta app and normalize differently.
     */
    public function supports(string $provider, string $channel): bool;

    /**
     * Map one raw provider payload to zero or more normalized turns.
     *
     * One webhook delivery legitimately carries many messages (Meta batches `entry[].messaging[]`),
     * so the return is iterable. Implementations MUST be pure: no persistence, no HTTP calls, no
     * clock-dependent values beyond what the payload itself carries — the pipeline re-runs this on
     * replays and expects identical output, which is what makes the derived idempotency key stable.
     *
     * @param  array<string, mixed>  $payload
     * @return iterable<int, InboundConversationMessage>
     */
    public function normalize(array $payload): iterable;
}
