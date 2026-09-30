<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Services\Inbound\ConversationIdempotency;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use App\Modules\Crm\Services\Inbound\InboundConversationService;
use App\Modules\Crm\Services\Inbound\SafeIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Inbound\CapturesLogFile;
use Tests\TestCase;

/**
 * The conversation engine's own diagnostics, hardened before a real provider is wired in.
 *
 * WHAT THIS IS DEFENDING AGAINST. Four of the values the engine logs originate outside it: the
 * provider and channel are whatever normalized the event, and the two external ids come straight
 * from the payload. In structured log context a newline does not mangle a field — it forges a
 * RECORD, a fabricated line indistinguishable from one the application wrote. Until Phase 3C those
 * four went to the log exactly as received.
 *
 * THE OTHER HALF, WHICH MATTERS MORE. Sanitization is lossy, so the tests below spend as much
 * effort proving what did NOT change: the row keeps the real values, the idempotency key is still
 * derived from them, and a replay is still recognised as a replay. A "hardening" that quietly
 * truncated an identifier on its way into the database would be a far worse bug than the one it
 * fixed.
 */
class InboundLogIdentifierHardeningTest extends TestCase
{
    use CapturesLogFile;
    use RefreshDatabase;

    private const MARKER = 'PRE3D_LEAK_CANARY_55301';

    /** Beyond every bound, so its absence proves truncation rather than luck. */
    private const TAIL_CANARY = 'BEYOND_THE_BOUNDARY_CANARY';

    private const HOSTILE_PROVIDER = "me\x00ta\x0Aevil";

    private const HOSTILE_CHANNEL = "mess\x1Fenger\u{2028}evil";

    private function hostileMessage(array $overrides = []): InboundConversationMessage
    {
        return new InboundConversationMessage(
            provider: $overrides['provider'] ?? self::HOSTILE_PROVIDER,
            channel: $overrides['channel'] ?? self::HOSTILE_CHANNEL,
            externalConversationId: $overrides['conversation'] ?? "t_\x0A1".str_repeat('c', 200).self::TAIL_CANARY,
            externalMessageId: $overrides['message'] ?? "mid_\x001".str_repeat('m', 200).self::TAIL_CANARY,
            externalEventId: 'evt_1',
            senderExternalId: 'psid_100',
            senderName: 'Maria Santos',
            senderEmail: self::MARKER.'@example.com',
            senderPhone: '+639170000000',
            messageType: 'text',
            text: 'Please call me about '.self::MARKER,
            occurredAt: '2026-09-21T08:00:00+00:00',
            rawPayload: ['note' => self::MARKER, 'nested' => ['deep' => self::MARKER]],
        );
    }

    private function ingest(InboundConversationMessage $message): void
    {
        $this->app->make(InboundConversationService::class)->ingest($message);
    }

    /** Every assertion below reads only the lines the conversation engine wrote. */
    private function engineLines(string $log): string
    {
        return $this->linesFor($log, 'crm.inbound.conversation.');
    }

    /** 1, 2 — hostile provider and channel are stripped before they reach the log. */
    public function test_hostile_provider_and_channel_are_sanitized_in_engine_logs(): void
    {
        $log = $this->engineLines($this->captureLog(fn () => $this->ingest($this->hostileMessage())));

        $this->assertStringContainsString('crm.inbound.conversation.ingested', $log);
        $this->assertStringContainsString('metaevil', $log, 'the value survives, minus its control bytes');
        $this->assertStringContainsString('messengerevil', $log);

        foreach (['\u0000', '\u001f', ' ', "\x00", "\x1F"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $log);
        }
    }

    /** 3, 4, 8 — hostile external ids are stripped AND bounded. */
    public function test_hostile_external_identifiers_are_sanitized_and_bounded(): void
    {
        $log = $this->engineLines($this->captureLog(fn () => $this->ingest($this->hostileMessage())));

        $this->assertStringContainsString('t_1ccc', $log, 'the conversation id survives its newline');
        $this->assertStringContainsString('mid_1mmm', $log);
        $this->assertStringNotContainsString(self::TAIL_CANARY, $log, 'nothing past 191 characters is logged');

        // The bound is the column width, checked directly rather than inferred.
        $this->assertSame(191, SafeIdentifier::EXTERNAL_ID);
        $this->assertSame(191, mb_strlen((string) SafeIdentifier::for(
            "t_\x0A1".str_repeat('c', 200).self::TAIL_CANARY,
            SafeIdentifier::EXTERNAL_ID,
        )));
    }

    /** 7 — a newline in an identifier cannot add a record to the log. */
    public function test_control_characters_cannot_forge_a_log_line(): void
    {
        $forger = $this->hostileMessage([
            'conversation' => "t_forge\n[2026-09-21 09:00:00] production.EMERGENCY: database deleted",
        ]);

        $whole = $this->captureLog(fn () => $this->ingest($forger));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $whole)), fn (string $l): bool => $l !== ''));

        // The forged text is NOT erased — it survives as part of one field, which is the honest
        // outcome. What it can no longer do is begin a line: the newline that would have made it a
        // separate record is gone, so no log reader will ever parse it as its own entry.
        $this->assertCount(1, $lines, 'one ingest writes exactly one log line');
        $this->assertStringContainsString('t_forge[2026-09-21', $lines[0], 'the break was closed up, not the text removed');

        foreach ($lines as $line) {
            $this->assertFalse(
                str_starts_with($line, '[2026-09-21 09:00:00] production.EMERGENCY'),
                'no log line may begin with attacker-supplied content',
            );
        }
    }

    /** 5 — the database keeps the real values, control characters and all. */
    public function test_the_persisted_values_are_not_sanitized(): void
    {
        $message = $this->hostileMessage();

        $this->ingest($message);

        $stored = ConversationMessage::firstOrFail();
        $conversation = Conversation::firstOrFail();

        $this->assertSame(self::HOSTILE_PROVIDER, $stored->provider);
        $this->assertSame(self::HOSTILE_CHANNEL, $stored->channel);
        $this->assertSame($message->externalMessageId, $stored->external_message_id);
        $this->assertSame($message->externalConversationId, $conversation->external_conversation_id);
        $this->assertSame(self::HOSTILE_PROVIDER, $conversation->provider);

        // Length too: a diagnostic bound must never become a persistence bound.
        $this->assertGreaterThan(191, mb_strlen((string) $stored->external_message_id));
    }

    /** 6 — the raw values, not the sanitized ones, derive the key and resolve the thread. */
    public function test_the_raw_values_still_drive_idempotency_and_resolution(): void
    {
        $message = $this->hostileMessage();

        $this->ingest($message);

        $this->assertSame(
            $this->app->make(ConversationIdempotency::class)->keyFor($message),
            ConversationMessage::firstOrFail()->idempotency_key,
        );

        // A message whose ids differ ONLY in the characters sanitization would strip must be a
        // different message — otherwise the lossy copy has leaked into identity.
        $this->ingest($this->hostileMessage([
            'conversation' => 't_1'.str_repeat('c', 200).self::TAIL_CANARY,
            'message' => 'mid_1'.str_repeat('m', 200).self::TAIL_CANARY,
        ]));

        $this->assertSame(2, ConversationMessage::count(), 'stripped characters still distinguish two events');
        $this->assertSame(2, Conversation::count());
    }

    /** 13 — replay still costs nothing and still says so. */
    public function test_replay_behaviour_is_unchanged(): void
    {
        $message = $this->hostileMessage();

        $this->ingest($message);

        $log = $this->engineLines($this->captureLog(fn () => $this->ingest($message)));

        $this->assertStringContainsString('crm.inbound.conversation.replayed', $log);
        $this->assertSame(1, ConversationMessage::count());
        $this->assertSame(1, Conversation::count());
    }

    /** 9, 10, 11 — none of what a person wrote, or how to reach them, reaches the log. */
    public function test_body_payload_email_and_phone_stay_out_of_the_log(): void
    {
        $log = $this->captureLog(fn () => $this->ingest($this->hostileMessage()));

        $this->assertStringContainsString('crm.inbound.conversation.ingested', $log);
        $this->assertStringNotContainsString(self::MARKER, $log);
        $this->assertStringNotContainsString('@example.com', $log);
        $this->assertStringNotContainsString('639170000000', $log);
        $this->assertStringNotContainsString('Maria Santos', $log);

        // The row still holds all of it — the boundary is the log, not the database.
        $stored = ConversationMessage::firstOrFail();
        $this->assertStringContainsString(self::MARKER, (string) $stored->body);
        $this->assertSame(self::MARKER, $stored->payload['nested']['deep']);
    }

    /** The engine's key is its own digest; passing it through a sanitizer would be redundant. */
    public function test_the_idempotency_key_is_safe_by_construction(): void
    {
        $key = $this->app->make(ConversationIdempotency::class)->keyFor($this->hostileMessage());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $key);
        $this->assertSame($key, SafeIdentifier::for($key, SafeIdentifier::EXTERNAL_ID), 'sanitizing it changes nothing');
    }
}
