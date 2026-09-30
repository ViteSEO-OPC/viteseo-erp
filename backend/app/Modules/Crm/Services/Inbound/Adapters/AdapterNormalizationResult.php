<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Adapters;

use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use InvalidArgumentException;

/**
 * Everything one provider envelope turned into: the turns worth ingesting, and an account of what
 * the rest was.
 *
 * WHY THE PHASE 3A CONTRACT COULD NOT STAY THE WHOLE STORY. `normalize()` returns an iterable of
 * messages, which is exactly right for the engine behind it and unable to say anything about what
 * it left out. An empty iterable reads identically for "this delivery was three read receipts"
 * (normal, expected, hourly) and "this delivery was malformed" (a provider contract change nobody
 * has noticed yet). A system that cannot tell those apart goes quiet at precisely the moment it
 * should be loud. This object keeps the distinction without changing that contract.
 *
 * IGNORED IS NOT REJECTED. Both are represented as {@see AdapterRejection}, because both are "an
 * item that produced no message" and one list is easier to reason about than two — but they are
 * counted separately everywhere that matters. A dashboard where ignored receipts inflate an error
 * count is a dashboard people stop reading.
 */
final class AdapterNormalizationResult
{
    /**
     * @param  list<InboundConversationMessage>  $messages
     * @param  list<AdapterRejection>  $rejections
     */
    private function __construct(
        public readonly array $messages,
        public readonly array $rejections,
    ) {}

    /**
     * @param  list<InboundConversationMessage>  $messages
     * @param  list<AdapterRejection>  $rejections
     */
    public static function of(array $messages, array $rejections = []): self
    {
        foreach ($messages as $message) {
            if (! $message instanceof InboundConversationMessage) {
                throw new InvalidArgumentException('An adapter may only return normalized conversation turns.');
            }
        }

        foreach ($rejections as $rejection) {
            if (! $rejection instanceof AdapterRejection) {
                throw new InvalidArgumentException('An adapter may only report structured rejections.');
            }
        }

        return new self(array_values($messages), array_values($rejections));
    }

    /** Nothing in this envelope was for us, and nothing about it was wrong. */
    public static function empty(): self
    {
        return new self([], []);
    }

    public function messageCount(): int
    {
        return count($this->messages);
    }

    /** Items deliberately skipped: receipts, echoes, non-message events. */
    public function ignoredCount(): int
    {
        return count($this->ignored());
    }

    /** Items that SHOULD have been messages and were not. Excludes ignored, on purpose. */
    public function rejectionCount(): int
    {
        return count($this->failures());
    }

    /** @return list<AdapterRejection> */
    public function ignored(): array
    {
        return array_values(array_filter($this->rejections, fn (AdapterRejection $r): bool => $r->isIgnored()));
    }

    /** @return list<AdapterRejection> */
    public function failures(): array
    {
        return array_values(array_filter($this->rejections, fn (AdapterRejection $r): bool => ! $r->isIgnored()));
    }

    /** Whether any failure is of the kind that can clear by itself. What to do about it is the transport's call. */
    public function hasRecoverableFailures(): bool
    {
        foreach ($this->failures() as $failure) {
            if ($failure->recoverable) {
                return true;
            }
        }

        return false;
    }
}
