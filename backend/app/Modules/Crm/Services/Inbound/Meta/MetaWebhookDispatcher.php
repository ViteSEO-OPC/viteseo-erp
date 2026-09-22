<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Meta;

use App\Modules\Crm\Services\Inbound\Adapters\InboundAdapterException;
use App\Modules\Crm\Services\Inbound\InboundNormalizationPipeline;
use App\Modules\Crm\Services\Inbound\SafeIdentifier;
use App\Support\CurrentOrg;
use Illuminate\Support\Facades\Log;

/**
 * Takes one verified Meta delivery from classification to persisted turns, one resource at a time.
 *
 *   decoded envelope → classify → [per (channel, resource)] resolve org → runAs → pipeline
 *
 * THE FAN-OUT CEILING IS CHECKED BEFORE ANYTHING IS PROCESSED, and that ordering is the point: each
 * surviving event becomes its own transaction in the Phase 3A engine, so an unbounded delivery is
 * unbounded write amplification from a single unauthenticated-by-anything-but-HMAC request. Counting
 * first means an over-limit delivery is refused whole, instead of leaving half of itself written
 * across two tenants.
 *
 * ONE RESOURCE, ONE TENANT, ONE SCOPE. Every group is resolved independently and processed inside
 * `CurrentOrg::runAs()`. Two Pages belonging to two customers arriving in the same delivery are two
 * groups, two resolutions and two scopes — they never share a tenant context, and `runAs` restores
 * the previous pin afterwards so nothing leaks into whatever runs next.
 *
 * LEADGEN IS RECOGNISED AND DEFERRED. A leadgen webhook carries identifiers only: the submitted
 * answers live behind a Graph API call this phase does not make. There is nothing faithful to
 * persist, so nothing is persisted — no conversation, no message, no lead. It is counted, logged by
 * safe identifier, and left for the phase that has real Meta access and a lead-creation policy.
 * Recording it as a conversation turn would put an empty "message" in a customer's thread and
 * invite someone to read intent into it.
 *
 * WHAT THIS CLASS MUST NEVER DO, each already owned elsewhere: derive an idempotency key
 * (ConversationIdempotency), match or create leads (ConversationLeadMatcher — and creation is
 * forbidden outright), assign owners or touch lead status, choose a tenant from adapter output (the
 * DTO has no org field), call the Phase 3B HTTP endpoint, or send anything to Meta.
 */
final class MetaWebhookDispatcher
{
    public function __construct(
        private readonly MetaEnvelopeClassifier $classifier,
        private readonly MetaAccountResolver $resolver,
        private readonly InboundNormalizationPipeline $pipeline,
    ) {}

    /**
     * @param  array<string, mixed>  $envelope  decoded from the bytes whose signature was verified
     *
     * @throws MetaDeliveryTooLargeException when the delivery exceeds the configured event ceiling
     */
    public function dispatch(array $envelope): MetaTransportResult
    {
        ['groups' => $groups, 'rejections' => $rejections] = $this->classifier->classify($envelope);

        $events = array_sum(array_map(static fn (MetaResourceGroup $g): int => $g->count(), $groups));
        $ceiling = (int) config('meta.limits.max_events', 200);

        if ($events > $ceiling) {
            Log::warning('crm.inbound.meta.rejected', [
                'provider' => 'meta',
                'code' => 'delivery_too_many_events',
                'event_count' => $events,
                'ceiling' => $ceiling,
            ]);

            throw new MetaDeliveryTooLargeException($events, $ceiling);
        }

        $result = (new MetaTransportResult)->withRejections(count($rejections));

        foreach ($groups as $group) {
            $result = $result->merge($this->dispatchGroup($group));
        }

        Log::info('crm.inbound.meta.received', [
            'provider' => 'meta',
            'group_count' => count($groups),
            'event_count' => $events,
            ...$result->toDiagnostics(),
        ]);

        return $result;
    }

    private function dispatchGroup(MetaResourceGroup $group): MetaTransportResult
    {
        $orgId = $this->resolver->orgFor($group->resourceType, $group->resourceExternalId);

        if ($orgId === null) {
            // A Page or account subscribed to our app but not connected to any tenant. Refusing to
            // guess is the whole point; acknowledging rather than failing is what keeps one
            // unonboarded Page from disabling the subscription for every tenant.
            $this->logGroup('crm.inbound.meta.rejected', $group, null, [
                'code' => 'unknown_resource_mapping',
                'event_count' => $group->count(),
            ]);

            return new MetaTransportResult(unknownMapping: 1);
        }

        if (! $group->isMessaging()) {
            return $this->deferLeadgen($group, $orgId);
        }

        return CurrentOrg::runAs($orgId, function () use ($group, $orgId): MetaTransportResult {
            try {
                $outcome = $this->pipeline->process('meta', $group->channel, $group->toEnvelope());
            } catch (InboundAdapterException $failure) {
                // No adapter registered for this channel, or two claiming it: a deployment fault,
                // not a property of the delivery. Recoverable, because deploying the fix fixes it.
                $this->logGroup('crm.inbound.meta.rejected', $group, $orgId, [
                    'code' => 'adapter_unavailable',
                    'exception' => $failure::class,
                ]);

                return new MetaTransportResult(rejected: $group->count(), hasRecoverableFailures: true);
            }

            return new MetaTransportResult(
                processed: $outcome->createdCount(),
                replayed: $outcome->replayedCount(),
                ignored: $outcome->ignoredCount(),
                rejected: $outcome->rejectionCount(),
                hasRecoverableFailures: $outcome->hasRecoverableFailures(),
            );
        });
    }

    /**
     * Leadgen: validated, counted, logged — and nothing written.
     *
     * The shape check is deliberately shallow, because a notification with no `leadgen_id` is the
     * only kind that could not be acted on even in a later phase; everything else about it is for
     * the phase that can fetch the answers.
     */
    private function deferLeadgen(MetaResourceGroup $group, int $orgId): MetaTransportResult
    {
        $deferred = 0;
        $rejected = 0;

        foreach ($group->items as $value) {
            $leadgenId = is_array($value) ? ($value['leadgen_id'] ?? null) : null;
            $formId = is_array($value) ? ($value['form_id'] ?? null) : null;

            if (! is_scalar($leadgenId)) {
                $rejected++;

                $this->logGroup('crm.inbound.meta.rejected', $group, $orgId, ['code' => 'malformed_leadgen']);

                continue;
            }

            $deferred++;

            $this->logGroup('crm.inbound.meta.deferred', $group, $orgId, [
                'code' => 'deferred_leadgen',
                'leadgen_id' => SafeIdentifier::for((string) $leadgenId, SafeIdentifier::EXTERNAL_ID),
                'form_id' => is_scalar($formId) ? SafeIdentifier::for((string) $formId, SafeIdentifier::EXTERNAL_ID) : null,
            ]);
        }

        return new MetaTransportResult(deferredLeadgen: $deferred, rejected: $rejected);
    }

    /**
     * Safe structured diagnostics: bounded, control-stripped identifiers and counts. Never the raw
     * body, a message body, an email, a phone number, a secret, a token or a signature.
     *
     * @param  array<string, scalar|null>  $context
     */
    private function logGroup(string $event, MetaResourceGroup $group, ?int $orgId, array $context): void
    {
        Log::warning($event, [
            'provider' => 'meta',
            'channel' => SafeIdentifier::for($group->channel, SafeIdentifier::CHANNEL),
            'resource_type' => SafeIdentifier::for($group->resourceType, SafeIdentifier::CHANNEL),
            'resource_id' => SafeIdentifier::for($group->resourceExternalId, SafeIdentifier::EXTERNAL_ID),
            'org_id' => $orgId,
            ...$context,
        ]);
    }
}
