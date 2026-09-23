<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

/**
 * The outcome of asking the outbound service to say something.
 *
 * TWO SHAPES, ONE TYPE. Either the provider accepted it (and we hold the provider's own id for the
 * message) or we refused (and we hold a code and a safe reason). Returning null for failure would
 * push the caller into inventing its own vocabulary; throwing for a refusal would make "this org
 * has not connected a Page yet" an exceptional condition, which it is not — it is the normal state
 * of every org on the day it signs up.
 *
 * NO MESSAGE BODY LIVES HERE. `reason` is operator-facing text assembled from identifiers only.
 * The thing the user typed never enters a result object, because result objects end up in logs.
 */
final class OutboundMessageResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly ?OutboundFailureCode $failure = null,
        public readonly ?string $reason = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    /**
     * The provider took it.
     *
     * Unreachable in Phase 3F.1 — no adapter can reach a transport — and present so that the
     * success path is designed now rather than bolted on beside a failure-only type later.
     */
    public static function accepted(string $providerMessageId, ?string $idempotencyKey = null): self
    {
        return new self(
            accepted: true,
            providerMessageId: $providerMessageId,
            idempotencyKey: $idempotencyKey,
        );
    }

    /** We declined, for a reason an operator can act on. */
    public static function refused(OutboundFailureCode $code, string $reason, ?string $idempotencyKey = null): self
    {
        return new self(
            accepted: false,
            failure: $code,
            reason: $reason,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Named `wasRefused` rather than `refused` because {@see self::refused()} is the static
     * constructor for the failure case, and PHP will not carry both under one name.
     */
    public function wasRefused(): bool
    {
        return ! $this->accepted;
    }
}
