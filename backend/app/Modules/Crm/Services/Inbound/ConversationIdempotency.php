<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

/**
 * Computes the idempotency key that makes inbound ingest replay-safe.
 *
 * Providers deliver at-least-once, so the same event WILL arrive again — on their retry, on our
 * re-processing, on a backfill. The key is what lets the database refuse the duplicate
 * (unique(org_id, idempotency_key)) instead of the application trying to remember.
 *
 * TWO REGIMES, one rule:
 *
 *  1. The provider issued a message id → identity is conceptually provider + channel +
 *     external_message_id. `channel` participates because one vendor issues ids from several
 *     namespaces (a Messenger mid and an Instagram mid are minted by different systems) and
 *     nothing promises they can never overlap.
 *
 *  2. No message id → a SHA-256 over every stable part of the event: provider, channel, event id,
 *     conversation id, sender, direction, provider timestamp, the normalized text, AND the
 *     canonicalized raw payload. Sender + timestamp alone is NOT enough: two messages can share a
 *     second, and a "same second, same sender" collision would silently swallow a real message.
 *     Including the canonical payload means two genuinely different events cannot collide unless
 *     they are byte-identical, in which case they are the same event.
 *
 * Both regimes hash, so the stored column has one uniform shape and one index profile.
 *
 * DETERMINISM is the contract. Canonicalization sorts object keys recursively (arrays keep their
 * order, because order is meaning there) and encodes with fixed flags, so the same event always
 * produces the same key regardless of how the provider ordered its JSON.
 */
final class ConversationIdempotency
{
    /** Bumped only if the derivation itself changes; old rows keep resolving under their own version. */
    private const VERSION = 'v1';

    public function keyFor(InboundConversationMessage $message): string
    {
        $externalMessageId = $this->trimmedOrNull($message->externalMessageId);

        if ($externalMessageId !== null) {
            return $this->hash([
                'v' => self::VERSION,
                'mode' => 'external_message_id',
                'provider' => $message->provider,
                'channel' => $message->channel,
                'external_message_id' => $externalMessageId,
            ]);
        }

        return $this->hash([
            'v' => self::VERSION,
            'mode' => 'derived',
            'provider' => $message->provider,
            'channel' => $message->channel,
            'direction' => $message->direction,
            'external_event_id' => $this->trimmedOrNull($message->externalEventId),
            'external_conversation_id' => $message->externalConversationId,
            'sender_external_id' => $this->trimmedOrNull($message->senderExternalId),
            'recipient_external_id' => $this->trimmedOrNull($message->recipientExternalId),
            'message_type' => $message->messageType,
            'occurred_at' => $this->trimmedOrNull($message->occurredAt),
            'text' => $this->normalizeText($message->text),
            'payload' => $message->rawPayload,
        ]);
    }

    /**
     * Whitespace-insensitive text identity: a provider re-delivery that re-wraps or re-pads the
     * body is the same message, and must not slip past the key as a new one.
     */
    private function normalizeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $text);

        return trim((string) $collapsed);
    }

    /** @param  array<string, mixed>  $parts */
    private function hash(array $parts): string
    {
        return hash('sha256', $this->canonicalize($parts));
    }

    /**
     * Deterministic JSON. Associative arrays are key-sorted recursively; list arrays keep their
     * order because position carries meaning in a provider payload.
     */
    private function canonicalize(mixed $value): string
    {
        return (string) json_encode(
            $this->sortRecursively($value),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                // A provider CAN send malformed UTF-8, and an exception here would drop a real
                // message over an encoding detail. Substitution replaces each bad byte with the
                // same U+FFFD every time, so the canonical form — and therefore the key — stays
                // deterministic for the same malformed input.
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
        );
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $mapped = array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);

        if (! $isList) {
            ksort($mapped);
        }

        return $mapped;
    }

    private function trimmedOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
