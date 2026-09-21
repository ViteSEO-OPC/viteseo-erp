<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;

/**
 * The outcome of one ingest call, stated explicitly so the caller — and the pipeline itself —
 * can tell a newly persisted message from a replay.
 *
 * This distinction is the whole reason the type exists. Returning a bare model would make the two
 * cases indistinguishable, and every side effect downstream (Activity, domain event,
 * post-processing, conversation recency) would run again on every provider retry. Side effects are
 * gated on {@see wasCreated()} and nothing else.
 */
final class InboundIngestResult
{
    private function __construct(
        public readonly ConversationMessage $message,
        public readonly Conversation $conversation,
        public readonly bool $created,
        public readonly string $leadMatchStrategy,
    ) {}

    public static function created(
        ConversationMessage $message,
        Conversation $conversation,
        string $leadMatchStrategy,
    ): self {
        return new self($message, $conversation, true, $leadMatchStrategy);
    }

    /**
     * A message that was already on file. The lead-match strategy is reported as `replay` rather
     * than re-derived: re-running matching on a duplicate could attach a lead that the first
     * ingest deliberately left unmatched, which is a reconciliation decision, not an ingest one.
     */
    public static function replayed(ConversationMessage $message, Conversation $conversation): self
    {
        return new self($message, $conversation, false, 'replay');
    }

    public function wasCreated(): bool
    {
        return $this->created;
    }

    public function wasReplay(): bool
    {
        return ! $this->created;
    }
}
