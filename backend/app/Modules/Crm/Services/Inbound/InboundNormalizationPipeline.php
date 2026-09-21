<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

use App\Modules\Crm\Services\Inbound\Adapters\AdapterNormalizationResult;
use App\Modules\Crm\Services\Inbound\Adapters\AdapterRejection;
use App\Modules\Crm\Services\Inbound\Adapters\AmbiguousAdapterException;
use App\Modules\Crm\Services\Inbound\Adapters\InboundAdapterException;
use App\Modules\Crm\Services\Inbound\Adapters\InboundAdapterRegistry;
use App\Modules\Crm\Services\Inbound\Adapters\RejectionCode;
use App\Modules\Crm\Services\Inbound\Adapters\SafeIdentifier;
use App\Modules\Crm\Services\Inbound\Adapters\UnsupportedProviderChannelException;
use App\Modules\Crm\Services\Inbound\Contracts\InboundProviderAdapter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one provider envelope from raw payload to persisted turns.
 *
 *   provider + channel → resolve adapter → normalize envelope → ingest each turn → collect
 *
 * WHAT IT DELIBERATELY DOES NOT DO, because each of these already has an owner and a second
 * implementation of any of them is how two ingest paths drift apart: idempotency
 * ({@see ConversationIdempotency}), lead matching ({@see ConversationLeadMatcher}), conversation
 * persistence ({@see ConversationResolver}), tenancy (the caller pins CurrentOrg; the service
 * refuses to guess), provider authentication and webhook signature verification (transport, Phase
 * 3D), and the HTTP status a provider retry deserves (also transport — this class reports facts).
 *
 * PER ITEM, NOT PER ENVELOPE. Each turn is ingested on its own through the Phase 3A service, which
 * already opens one transaction per message. Wrapping the envelope in a single transaction would
 * mean one malformed or unlucky item discarding every valid customer message beside it — and on
 * the provider's retry the same item would fail again, so those messages would never land at all.
 * A failure here costs exactly the item it belongs to.
 *
 * THE ADAPTER IS NOT TRUSTED TO BEHAVE. It is our code, but it parses someone else's data, so an
 * exception escaping `normalizeEnvelope()` is treated as an infrastructure fault: converted to a
 * recoverable rejection, logged by code and exception CLASS only, and never allowed to carry the
 * payload it choked on into a log line.
 */
final class InboundNormalizationPipeline
{
    public function __construct(
        private readonly InboundAdapterRegistry $registry,
        private readonly InboundConversationService $service,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws UnsupportedProviderChannelException
     * @throws AmbiguousAdapterException
     */
    public function process(string $provider, string $channel, array $payload): InboundPipelineResult
    {
        // Resolution failures are NOT swallowed into the result: an unconfigured or ambiguously
        // configured channel is a deployment problem, not a property of this delivery, and a
        // caller that quietly recorded "0 messages" for it would hide an outage behind a metric.
        $adapter = $this->registry->for($provider, $channel);

        $normalized = $this->normalize($adapter, $provider, $channel, $payload);

        $ingested = [];
        $rejections = $normalized->rejections;

        foreach ($normalized->messages as $index => $message) {
            try {
                $ingested[] = $this->service->ingest($message);
            } catch (InboundConversationPersistenceException) {
                // Already sanitized at the source — it carries diagnostics, never bindings. The
                // sibling turns continue; this one is worth re-delivering.
                // The index is this turn's position among the NORMALIZED turns, not among the raw
                // envelope items — the adapter already dropped receipts and malformed entries, so
                // the two no longer line up. The event id is the identifier that survives both.
                $rejections[] = new AdapterRejection(
                    RejectionCode::PersistenceFailed,
                    $index,
                    $message->externalEventId,
                );
            }
        }

        $result = new InboundPipelineResult(
            $provider,
            $channel,
            $adapter->name(),
            array_values($ingested),
            array_values($rejections),
        );

        $this->logOutcome($result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalize(
        InboundProviderAdapter $adapter,
        string $provider,
        string $channel,
        array $payload,
    ): AdapterNormalizationResult {
        try {
            return $adapter->normalizeEnvelope($payload);
        } catch (Throwable $failure) {
            // The class name says what broke; the message would say what it broke ON, which is the
            // payload. See InboundAdapterException for why the original is not chained either.
            // Sanitized copies: the adapter's name is the adapter's to choose, and provider and
            // channel will reach this class from a provider classifier once Phase 3D exists. The
            // originals are untouched — they already resolved the adapter above.
            Log::error('crm.inbound.adapter.rejected', [
                'provider' => SafeIdentifier::for($provider, SafeIdentifier::PROVIDER),
                'channel' => SafeIdentifier::for($channel, SafeIdentifier::CHANNEL),
                'adapter' => SafeIdentifier::for($adapter->name(), SafeIdentifier::ADAPTER),
                'code' => RejectionCode::NormalizationFailed->value,
                'exception' => $failure::class,
                'recoverable' => true,
            ]);

            return AdapterNormalizationResult::of([], [
                new AdapterRejection(RejectionCode::NormalizationFailed),
            ]);
        }
    }

    /**
     * Counts and codes only. Never the text, the payload, the sender's email or phone, a token, a
     * signature or a provider credential — the same boundary the Phase 3A service keeps.
     */
    private function logOutcome(InboundPipelineResult $result): void
    {
        Log::info('crm.inbound.adapter.normalized', $result->toDiagnostics());

        // toDiagnostics() sanitizes the identifiers it returns; the rejection's own diagnostics
        // are safe by construction.
        $envelope = $result->toDiagnostics();

        foreach ($result->failures() as $failure) {
            Log::warning('crm.inbound.adapter.rejected', [
                'provider' => $envelope['provider'],
                'channel' => $envelope['channel'],
                'adapter' => $envelope['adapter'],
                ...$failure->toDiagnostics(),
            ]);
        }
    }
}
