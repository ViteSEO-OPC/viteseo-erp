<?php

declare(strict_types=1);

namespace App\Modules\Crm\Events;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A NEW inbound conversation turn was persisted.
 *
 * Fired exactly once per message, and only for a newly created row — a provider replay produces
 * no event, which is what keeps at-least-once delivery from turning into at-least-once side
 * effects. Carries the models, not the raw payload.
 *
 * Phase 3A registers NO listeners on purpose. The event exists so a later phase (AI reply,
 * notification, n8n emission) attaches without reopening the ingest pipeline — the same seam
 * InboundReplyReceived gives the email path.
 *
 * ShouldDispatchAfterCommit: the event is dispatched from INSIDE the ingest transaction, and the
 * framework holds it until the OUTERMOST transaction commits (Dispatcher::dispatch → the database
 * transactions manager). A successful inner savepoint is not enough — a batch ingest that wraps
 * many messages in one outer transaction and then rolls back must emit nothing, or listeners would
 * act on a message that no longer exists. On rollback the queued callback is discarded.
 */
class InboundConversationMessageReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly ConversationMessage $message,
        public readonly Conversation $conversation,
    ) {}
}
