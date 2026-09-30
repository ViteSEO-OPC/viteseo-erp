<?php

declare(strict_types=1);

namespace Tests\Support\Inbound;

/**
 * The envelopes the fixture adapter is fed.
 *
 * Kept as builders rather than JSON files on disk: every case is readable beside the assertion
 * that uses it, a typo is a PHP error instead of a silent empty array, and there is no file I/O in
 * a test whose subject is normalization. The shapes are arbitrary on purpose — no real provider's
 * format appears anywhere in Phase 3C.
 */
final class FixtureEnvelopes
{
    /** One event, one text message. */
    public static function singleText(string $text = 'Magkano po ang SEO package?'): array
    {
        return ['events' => [self::message('t_1', 'mid_1', ['text' => $text])]];
    }

    /** One envelope carrying three distinct messages, each with its own provider id. */
    public static function threeMessages(): array
    {
        return ['events' => [
            self::message('t_1', 'mid_1', ['text' => 'first']),
            self::message('t_1', 'mid_2', ['text' => 'second']),
            self::message('t_1', 'mid_3', ['text' => 'third']),
        ]];
    }

    /**
     * Three distinct messages with NO provider message id — the case that forces Phase 3A's
     * derived idempotency key, and the one that collapses to a single row if an adapter ever
     * hands every sibling the whole envelope as its raw payload.
     */
    public static function threeWithoutMessageIds(): array
    {
        return ['events' => [
            self::message('t_9', null, ['event_id' => 'evt_9_1', 'text' => 'una', 'at' => 1758441600]),
            self::message('t_9', null, ['event_id' => 'evt_9_2', 'text' => 'pangalawa', 'at' => 1758441660]),
            self::message('t_9', null, ['event_id' => 'evt_9_3', 'text' => 'pangatlo', 'at' => 1758441720]),
        ]];
    }

    /** An attachment turn: no text, a different message type. */
    public static function attachment(): array
    {
        return ['events' => [self::message('t_2', 'mid_att_1', [
            'message_type' => 'attachment',
            'text' => null,
            'attachment' => ['kind' => 'image', 'ref' => 'asset_77'],
        ])]];
    }

    /** Valid, malformed, valid — the per-item semantics, stated as data. */
    public static function malformedSibling(): array
    {
        return ['events' => [
            self::message('t_3', 'mid_a', ['text' => 'A']),
            ['event_id' => 'evt_b', 'no_type_here' => true],
            self::message('t_3', 'mid_c', ['text' => 'C']),
        ]];
    }

    /** A delivery receipt beside a real message. */
    public static function ignoredReceipt(): array
    {
        return ['events' => [
            ['type' => 'delivery_receipt', 'event_id' => 'evt_receipt', 'thread' => 't_4'],
            self::message('t_4', 'mid_after_receipt', ['text' => 'real one']),
        ]];
    }

    /** An item belonging to a channel this adapter does not own. */
    public static function unsupportedChannelItem(): array
    {
        return ['events' => [self::message('t_5', 'mid_voice', ['channel' => 'voice'])]];
    }

    /** A turn with no thread id at all. */
    public static function missingThread(): array
    {
        return ['events' => [['type' => 'message', 'event_id' => 'evt_no_thread', 'id' => 'mid_x', 'text' => 'orphan']]];
    }

    /** A message type the adapter has no representation for. */
    public static function unsupportedMessageType(): array
    {
        return ['events' => [self::message('t_6', 'mid_call', ['message_type' => 'call_invite'])]];
    }

    /** The envelope is not even a list of events. */
    public static function malformedEnvelope(): array
    {
        return ['events' => 'not-a-list'];
    }

    /** Plants a marker in every field a log line might be tempted to quote. */
    public static function withMarker(string $marker): array
    {
        return ['events' => [self::message('t_marker', 'mid_marker', [
            'text' => 'Please call me about '.$marker,
            'from_email' => $marker.'@example.com',
            'from_phone' => '+63917'.substr((string) crc32($marker), 0, 7),
            'secret_note' => $marker,
        ])]];
    }

    /** Makes the adapter itself throw, with the marker inside the exception message. */
    public static function exploding(string $marker): array
    {
        return ['explode' => $marker];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function message(string $thread, ?string $id, array $overrides = []): array
    {
        return array_merge([
            'type' => 'message',
            'event_id' => 'evt_'.($id ?? $thread.'_'.count($overrides)),
            'id' => $id,
            'thread' => $thread,
            'from' => 'psid_100',
            'to' => 'page_900',
            'from_name' => 'Maria Santos',
            'message_type' => 'text',
            'text' => 'hello',
            'at' => 1758441600,
        ], $overrides);
    }
}
