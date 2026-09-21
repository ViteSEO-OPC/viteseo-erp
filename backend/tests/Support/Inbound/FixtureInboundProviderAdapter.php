<?php

declare(strict_types=1);

namespace Tests\Support\Inbound;

use App\Modules\Crm\Services\Inbound\Adapters\AdapterNormalizationResult;
use App\Modules\Crm\Services\Inbound\Adapters\AdapterRejection;
use App\Modules\Crm\Services\Inbound\Adapters\RejectionCode;
use App\Modules\Crm\Services\Inbound\Contracts\InboundProviderAdapter;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A provider that does not exist, so the framework can be tested without one that does.
 *
 * WHY IT LIVES UNDER tests/ AND NOT app/. This is the isolation control, not a matter of taste:
 * `Tests\` is declared in composer's `autoload-dev`, which a production `composer install
 * --no-dev` does not generate. In production this class is not merely unlisted in
 * `config/inbound.php` — it cannot be autoloaded at all, so no configuration mistake, no
 * environment variable and no crafted request can reach it. A fake adapter under app/ would be one
 * config line away from normalizing real customer traffic.
 *
 * Its envelope shape is deliberately NOT Meta's. Phase 3C is the framework; encoding a real
 * provider's payload here would invite the registry and pipeline to grow knowledge of a shape they
 * must stay ignorant of, and would make these tests quietly wrong the day Meta changes it.
 *
 * It is pure: no clock, no persistence, no HTTP, no state between calls. Same envelope in, same
 * result out — which is what keeps the derived idempotency key stable across a replay.
 */
class FixtureInboundProviderAdapter implements InboundProviderAdapter
{
    public const PROVIDER = 'fixture';

    public const CHANNEL = 'chat';

    /** Everything this fake knows how to represent; anything else is an honest rejection. */
    private const MESSAGE_TYPES = ['text', 'attachment'];

    public function name(): string
    {
        return 'fixture';
    }

    public function supports(string $provider, string $channel): bool
    {
        return $provider === static::PROVIDER && $channel === static::CHANNEL;
    }

    /**
     * The Phase 3A contract, satisfied by delegation — never by a second parse of the payload.
     *
     * @param  array<string, mixed>  $payload
     * @return iterable<int, InboundConversationMessage>
     */
    public function normalize(array $payload): iterable
    {
        return $this->normalizeEnvelope($payload)->messages;
    }

    /** @param  array<string, mixed>  $payload */
    public function normalizeEnvelope(array $payload): AdapterNormalizationResult
    {
        // Simulates the adapter ITSELF failing — a bug or an infrastructure fault, as opposed to
        // bad data. The message carries a marker so a test can prove the pipeline never repeats it.
        if (isset($payload['explode'])) {
            throw new RuntimeException('fixture adapter exploded on '.(string) $payload['explode']);
        }

        $items = $payload['events'] ?? null;

        if (! is_array($items)) {
            // The envelope itself is unusable, so there is no item to point at.
            return AdapterNormalizationResult::of([], [
                new AdapterRejection(RejectionCode::MalformedEvent),
            ]);
        }

        $messages = [];
        $rejections = [];

        foreach (array_values($items) as $index => $item) {
            $outcome = $this->normalizeItem($index, $item);

            if ($outcome instanceof AdapterRejection) {
                $rejections[] = $outcome;

                continue;
            }

            $messages[] = $outcome;
        }

        return AdapterNormalizationResult::of($messages, $rejections);
    }

    private function normalizeItem(int $index, mixed $item): InboundConversationMessage|AdapterRejection
    {
        if (! is_array($item)) {
            return new AdapterRejection(RejectionCode::MalformedEvent, $index);
        }

        $eventId = $this->stringOrNull($item['event_id'] ?? null);
        $type = $this->stringOrNull($item['type'] ?? null);

        if ($type === null) {
            return new AdapterRejection(RejectionCode::MalformedEvent, $index, $eventId);
        }

        if ($type !== 'message') {
            // A delivery receipt, a read receipt, an echo of our own reply. Normal traffic, and
            // the single most common thing in a real webhook batch.
            return new AdapterRejection(RejectionCode::IgnoredNonMessage, $index, $eventId);
        }

        $channel = $this->stringOrNull($item['channel'] ?? null) ?? static::CHANNEL;

        if ($channel !== static::CHANNEL) {
            return new AdapterRejection(RejectionCode::UnsupportedChannel, $index, $eventId);
        }

        $thread = $this->stringOrNull($item['thread'] ?? null);

        if ($thread === null) {
            // With no thread there is nothing to attach the turn to, and inventing one would put
            // two unrelated conversations in the same row.
            return new AdapterRejection(RejectionCode::MissingProviderIdentity, $index, $eventId);
        }

        $messageType = $this->stringOrNull($item['message_type'] ?? null) ?? 'text';

        if (! in_array($messageType, self::MESSAGE_TYPES, true)) {
            return new AdapterRejection(RejectionCode::UnsupportedMessageType, $index, $eventId);
        }

        return new InboundConversationMessage(
            provider: static::PROVIDER,
            channel: static::CHANNEL,
            externalConversationId: $thread,
            externalMessageId: $this->stringOrNull($item['id'] ?? null),
            externalEventId: $eventId,
            senderExternalId: $this->stringOrNull($item['from'] ?? null),
            recipientExternalId: $this->stringOrNull($item['to'] ?? null),
            senderName: $this->stringOrNull($item['from_name'] ?? null),
            senderEmail: $this->stringOrNull($item['from_email'] ?? null),
            senderPhone: $this->stringOrNull($item['from_phone'] ?? null),
            messageType: $messageType,
            text: $this->stringOrNull($item['text'] ?? null),
            occurredAt: $this->occurredAt($item['at'] ?? null),
            // THE ITEM'S OWN SLICE, never the envelope. Phase 3A's derived idempotency key hashes
            // this payload for turns with no provider message id, so sharing one envelope across
            // siblings would make them hash identically and collapse into a single replay.
            rawPayload: $item,
        );
    }

    /**
     * Provider clocks arrive as epoch seconds or as an ISO-8601 string; both normalize to the same
     * ISO-8601 UTC string, with no reference to the current time — a value that changed between
     * two runs would change the idempotency key with it.
     */
    private function occurredAt(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return CarbonImmutable::createFromTimestampUTC((int) $value)->toIso8601String();
        }

        return $this->stringOrNull(is_string($value) ? $value : null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
