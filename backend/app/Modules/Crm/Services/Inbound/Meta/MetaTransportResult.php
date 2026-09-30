<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Meta;

/**
 * What one signed Meta delivery did, as counts.
 *
 * WHY THE HTTP DECISION LIVES AT THIS LAYER AND NOT IN PHASE 3C. The normalization layer reports
 * facts and deliberately refuses to recommend a transport action; deciding what a provider should do
 * next needs the provider's own retry semantics, which only the Meta layer knows. Meta retries on
 * 5xx and will eventually DISABLE a subscription that keeps failing — and the subscription is
 * per-app, so one tenant's unmapped Page answering 5xx forever would take every tenant's webhook
 * down with it. Hence one narrow rule: retry only what a retry can fix.
 *
 * Counts, codes and nothing else — so the object is safe to log whole, and safe to shape a response
 * body from (it does not, but it could not leak if it did).
 */
final class MetaTransportResult
{
    public function __construct(
        public readonly int $processed = 0,
        public readonly int $replayed = 0,
        public readonly int $ignored = 0,
        public readonly int $rejected = 0,
        public readonly int $deferredLeadgen = 0,
        public readonly int $unknownMapping = 0,
        public readonly bool $hasRecoverableFailures = false,
    ) {}

    public function merge(self $other): self
    {
        return new self(
            $this->processed + $other->processed,
            $this->replayed + $other->replayed,
            $this->ignored + $other->ignored,
            $this->rejected + $other->rejected,
            $this->deferredLeadgen + $other->deferredLeadgen,
            $this->unknownMapping + $other->unknownMapping,
            $this->hasRecoverableFailures || $other->hasRecoverableFailures,
        );
    }

    public function withRejections(int $count): self
    {
        return $count === 0 ? $this : $this->merge(new self(rejected: $count));
    }

    /**
     * The only transport judgement in the codebase: ask Meta to send this delivery again.
     *
     * True only for a recoverable failure — an infrastructure fault that may well have cleared by
     * the next attempt, and one the Phase 3A idempotency guarantees make safe to repeat. Everything
     * else is acknowledged: a malformed item, an unsupported event and an unmapped Page are all
     * permanent for this delivery, and asking for them again is how a subscription gets disabled.
     */
    public function shouldAskForRedelivery(): bool
    {
        return $this->hasRecoverableFailures;
    }

    /** @return array<string, scalar> */
    public function toDiagnostics(): array
    {
        return [
            'processed_count' => $this->processed,
            'replayed_count' => $this->replayed,
            'ignored_count' => $this->ignored,
            'rejected_count' => $this->rejected,
            'deferred_leadgen_count' => $this->deferredLeadgen,
            'unknown_mapping_count' => $this->unknownMapping,
            'has_recoverable_failures' => $this->hasRecoverableFailures,
        ];
    }
}
