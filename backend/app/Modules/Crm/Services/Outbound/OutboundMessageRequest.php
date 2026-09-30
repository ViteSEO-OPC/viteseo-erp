<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use App\Modules\Crm\Models\Conversation;

/**
 * What the CRM wants to say, expressed only in things the server already trusts.
 *
 * THE TENANCY ARGUMENT IS THE WHOLE POINT OF THIS CLASS. It takes a Conversation MODEL, not a
 * conversation id, and certainly not an org id. A model can only have been loaded through
 * {@see BelongsToOrg}, whose global scope already appended
 * `where(org_id, CurrentOrg::id())` — so by the time one exists, the tenancy question has been
 * answered by the query, not by a field a caller filled in. There is deliberately no `orgId`
 * property to set, no `pageId`, no `instagramAccountId`, no `accessToken` and no sender identity:
 * a request that cannot express those cannot smuggle them.
 *
 * CHANNEL IS NOT AN INPUT EITHER. It is read from the conversation. Accepting it separately would
 * create two sources for one fact with no defined winner, and the losing one would eventually be
 * the one a security check read.
 *
 * THE BODY IS THE ONLY FREE TEXT, and it never reaches a log. {@see OutboundMessageService} and
 * every adapter describe failures with identifiers; the text exists to be delivered, not to be
 * diagnosed with.
 *
 * WHO WROTE IT IS NOT WHO IT IS SENT AS. `$actor` is the CRM user whose permission authorizes the
 * send and who will own the audit trail. The provider-side sender is always the org's connected
 * resource, resolved server-side — a user cannot elect to speak as a different Page.
 */
final class OutboundMessageRequest
{
    public function __construct(
        public readonly Conversation $conversation,
        public readonly User $actor,
        public readonly string $body,
        /**
         * A client-generated id for this send attempt, used for idempotency.
         *
         * Optional at the type level and required in practice by
         * {@see OutboundIdempotency}: see that class for why a missing id makes an attempt
         * non-idempotent rather than silently deduplicated.
         */
        public readonly ?string $clientRequestId = null,
        /** Mirrors the `crm_messages.message_type` vocabulary. Only `text` is servable today. */
        public readonly string $messageType = 'text',
    ) {}

    /** The provider that owns this thread, from server state. */
    public function provider(): string
    {
        return (string) $this->conversation->provider;
    }

    /** The medium, from server state. */
    public function channel(): string
    {
        return (string) $this->conversation->channel;
    }

    /** The org that owns the thread. Read from the model, never from a caller. */
    public function orgId(): int
    {
        return (int) $this->conversation->org_id;
    }

    /** Length in characters, for validation and for logs that must not quote the text. */
    public function bodyLength(): int
    {
        return mb_strlen($this->body);
    }
}
