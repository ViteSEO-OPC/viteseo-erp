<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Contracts;

use App\Modules\Crm\Services\Inbound\Adapters\AdapterNormalizationResult;

/**
 * What a real provider adapter implements: {@see InboundConversationProvider} plus the two things
 * a live webhook needs that the narrow contract cannot express.
 *
 * WHY EXTEND RATHER THAN REPLACE. The Phase 3A interface is not wrong — it is the minimum an
 * engine needs, and everything downstream depends only on it. What it cannot say is what happened
 * to the items that produced no message, and for a real provider that is most of the traffic:
 * delivery receipts, read receipts, echoes of our own replies. Widening `normalize()` would force
 * that vocabulary onto every consumer of the smaller contract; extending leaves the original
 * untouched and adds the richer answer beside it.
 *
 * ONE IMPLEMENTATION, TWO DOORS. `normalize()` must return exactly
 * `normalizeEnvelope($payload)->messages` — never a second parsing path. Two code paths over the
 * same payload is how a provider's quirk gets fixed in one of them and the other keeps producing
 * the old shape for months, and the difference only ever surfaces as a data question nobody can
 * answer.
 *
 * NO STATE BETWEEN CALLS. Adapters are resolved once and reused for every delivery, so anything
 * remembered on the instance would leak one tenant's envelope into another's normalization. They
 * are pure functions with a class around them: the payload in the argument is the only input.
 *
 * WHAT AN ADAPTER STILL MAY NOT DO, unchanged from Phase 3A: no org or tenant (the DTO has no such
 * field and tenancy is resolved by the caller), no lead (the matcher decides against the tenant's
 * own data), no idempotency key (ConversationIdempotency owns it, so one rule governs every
 * provider), no persistence and no HTTP.
 */
interface InboundProviderAdapter extends InboundConversationProvider
{
    /**
     * A short, stable identifier for logs and for naming a duplicate-claim conflict.
     *
     * Stable because it is read back in dashboards and alerts: renaming it silently breaks every
     * saved query built on it. Lowercase, no spaces, no provider secrets — it is an adapter's
     * name, not a credential or an account.
     */
    public function name(): string;

    /**
     * Normalize one provider envelope into turns, plus an account of everything else in it.
     *
     * MUST NOT THROW for bad data. A malformed item is a result, not an exception: one broken item
     * in a batch of ten must not cost the nine valid customer messages beside it. Exceptions are
     * reserved for the adapter itself failing, and the pipeline converts those into a recoverable
     * rejection without quoting the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function normalizeEnvelope(array $payload): AdapterNormalizationResult;
}
