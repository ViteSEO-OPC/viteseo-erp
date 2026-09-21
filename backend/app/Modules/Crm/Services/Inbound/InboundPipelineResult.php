<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

use App\Modules\Crm\Services\Inbound\Adapters\AdapterRejection;
use App\Modules\Crm\Services\Inbound\Adapters\SafeIdentifier;

/**
 * What one provider envelope did to the CRM, as a whole.
 *
 * Distinct from {@see InboundIngestResult}, which answers for ONE turn. A delivery is a batch: it
 * can create two messages, replay a third, ignore two receipts and reject a malformed item, and a
 * caller that only learns "the last one was a replay" cannot decide anything. Phase 3D's webhook
 * will read this to choose between acknowledging a delivery and asking the provider to send it
 * again — which is why recoverable failures are counted apart from permanent ones.
 *
 * It holds no payload and no message body: everything here is a count, a code or an identifier,
 * so the object itself is safe to log.
 */
final class InboundPipelineResult
{
    /**
     * @param  list<InboundIngestResult>  $ingested
     * @param  list<AdapterRejection>  $rejections
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $channel,
        public readonly string $adapter,
        public readonly array $ingested,
        public readonly array $rejections,
    ) {}

    public function createdCount(): int
    {
        return count(array_filter($this->ingested, fn (InboundIngestResult $r): bool => $r->wasCreated()));
    }

    public function replayedCount(): int
    {
        return count(array_filter($this->ingested, fn (InboundIngestResult $r): bool => $r->wasReplay()));
    }

    public function ingestedCount(): int
    {
        return count($this->ingested);
    }

    public function ignoredCount(): int
    {
        return count(array_filter($this->rejections, fn (AdapterRejection $r): bool => $r->isIgnored()));
    }

    /** Genuine failures only — an ignored receipt is not one. */
    public function rejectionCount(): int
    {
        return count($this->failures());
    }

    /** @return list<AdapterRejection> */
    public function failures(): array
    {
        return array_values(array_filter($this->rejections, fn (AdapterRejection $r): bool => ! $r->isIgnored()));
    }

    /**
     * Whether any failure in this delivery was of the kind that can clear by itself.
     *
     * DELIBERATELY A FACT, NOT A RECOMMENDATION. An earlier draft of this method was called
     * `shouldBeRetried()`, and the name was the whole problem: retrying, acknowledging and
     * answering 5xx are transport decisions, and a transport-neutral layer that hands down a
     * verdict has made the decision anyway — the caller is then either obeying it or arguing with
     * it. What this layer knows is whether a failure was recoverable; what to DO about that
     * belongs to Phase 3D, which has the provider's retry semantics in front of it.
     */
    public function hasRecoverableFailures(): bool
    {
        foreach ($this->failures() as $failure) {
            if ($failure->recoverable) {
                return true;
            }
        }

        return false;
    }

    /**
     * Safe structured summary for logs.
     *
     * The three identifiers are sanitized HERE and only here: the readonly properties keep the
     * values the caller passed, because those are what resolved the adapter and what the DTOs and
     * the idempotency key were built from. Sanitization is lossy, so it belongs on the copy that
     * is written to a log and on nothing else.
     *
     * @return array<string, scalar|null>
     */
    public function toDiagnostics(): array
    {
        return [
            'provider' => SafeIdentifier::for($this->provider, SafeIdentifier::PROVIDER),
            'channel' => SafeIdentifier::for($this->channel, SafeIdentifier::CHANNEL),
            'adapter' => SafeIdentifier::for($this->adapter, SafeIdentifier::ADAPTER),
            'normalized_count' => $this->ingestedCount(),
            'created_count' => $this->createdCount(),
            'replayed_count' => $this->replayedCount(),
            'ignored_count' => $this->ignoredCount(),
            'rejection_count' => $this->rejectionCount(),
            'has_recoverable_failures' => $this->hasRecoverableFailures(),
        ];
    }
}
