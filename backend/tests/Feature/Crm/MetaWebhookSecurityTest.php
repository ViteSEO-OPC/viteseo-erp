<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Inbound\CapturesLogFile;
use Tests\Support\Inbound\Meta\MetaEnvelopes;
use Tests\Support\Inbound\Meta\SignsMetaDeliveries;
use Tests\TestCase;

/**
 * The only thing standing between a public URL and the conversation engine.
 *
 * There is no Sanctum token on this route, so every test here is the authentication test. The ones
 * that matter most are the negatives: a webhook endpoint that accepts an unsigned body, or accepts
 * one while the integration is switched off, is not a smaller feature — it is an open write API
 * against every tenant.
 */
class MetaWebhookSecurityTest extends TestCase
{
    use CapturesLogFile;
    use RefreshDatabase;
    use SignsMetaDeliveries;

    private const MARKER = 'META_LEAK_CANARY_88104';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMetaWebhook();

        // A mapped Page, so nothing below can pass merely for want of a tenant.
        $this->connectMetaResource($this->tenant()->id, MetaEnvelopes::PAGE_A);
    }

    private function assertNothingIngested(): void
    {
        $this->assertSame(0, ConversationMessage::count());
        $this->assertSame(0, Conversation::count());
    }

    public function test_a_correctly_signed_delivery_is_accepted(): void
    {
        $this->postSigned(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]))
            ->assertOk();

        $this->assertSame(1, ConversationMessage::count());
    }

    public function test_a_wrong_digest_is_rejected(): void
    {
        $body = $this->encode(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]));

        $this->postRaw($body, 'sha256='.str_repeat('a', 64))->assertForbidden();

        $this->assertNothingIngested();
    }

    public function test_a_missing_signature_is_rejected(): void
    {
        $this->postRaw($this->encode(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()])), null)
            ->assertForbidden();

        $this->assertNothingIngested();
    }

    /** Malformed grammar, in every shape a lenient parser would have accepted. */
    public function test_malformed_signature_headers_are_rejected(): void
    {
        $body = $this->encode(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]));
        $digest = hash_hmac('sha256', $body, self::FIXTURE_APP_SECRET);

        $malformed = [
            $digest,                                  // no prefix
            'sha256='.substr($digest, 0, 63),         // short
            'sha256='.$digest.'a',                    // long
            'sha256=='.$digest,                       // doubled separator
            'sha256=not-hex-'.substr($digest, 15),    // non-hex
            'sha1='.sha1($body),                      // legacy primitive
            'sha256='.$digest.', sha1='.sha1($body),  // two proofs in one header
            '',                                       // empty
            'sha256='.str_repeat('a', 5000),          // oversized
        ];

        foreach ($malformed as $header) {
            $this->postRaw($body, $header)->assertForbidden();
        }

        $this->assertNothingIngested();
    }

    /** The signature covers the bytes; changing one of them must invalidate it. */
    public function test_an_altered_body_is_rejected(): void
    {
        $original = $this->encode(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]));
        $signature = $this->signature($original);
        $altered = str_replace('Magkano', 'Tampered', $original);

        $this->assertNotSame($original, $altered);

        $this->postRaw($altered, $signature)->assertForbidden();

        $this->assertNothingIngested();
    }

    /** No secret, no trust — never "no secret, no check". */
    public function test_a_missing_app_secret_fails_closed(): void
    {
        config(['meta.app_secret' => '']);

        $body = $this->encode(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]));

        // Even a signature that is correct for an EMPTY secret must not be accepted.
        $this->postRaw($body, 'sha256='.hash_hmac('sha256', $body, ''))->assertForbidden();

        $this->assertNothingIngested();
    }

    /** The feature flag turns the feature off, not the authentication. */
    public function test_a_disabled_integration_refuses_even_a_valid_signature(): void
    {
        config(['meta.enabled' => false]);

        $this->postSigned(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]))
            ->assertForbidden();

        $this->assertNothingIngested();
    }

    /** The byte ceiling is measured on the real body and applied before any hashing. */
    public function test_an_oversized_body_is_rejected_before_verification(): void
    {
        config(['meta.limits.raw_body_bytes' => 512]);

        $body = $this->encode(MetaEnvelopes::oversizedItem(4096));

        $this->assertGreaterThan(512, strlen($body));

        $this->postRaw($body, $this->signature($body))->assertStatus(413);

        $this->assertNothingIngested();
    }

    /** Content-Length is the sender's claim; the guard trusts the bytes it actually received. */
    public function test_a_lying_content_length_does_not_bypass_the_ceiling(): void
    {
        config(['meta.limits.raw_body_bytes' => 512]);

        $body = $this->encode(MetaEnvelopes::oversizedItem(4096));

        $this->call('POST', self::WEBHOOK, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '10',
            'HTTP_X_HUB_SIGNATURE_256' => $this->signature($body),
        ], $body)->assertStatus(413);

        $this->assertNothingIngested();
    }

    public function test_malformed_json_is_rejected(): void
    {
        $body = '{"object":"page","entry":[';

        $this->postRaw($body, $this->signature($body))->assertStatus(400);

        $this->assertNothingIngested();
    }

    /** json_decode's depth is a hard limit, so "too deep" is an error rather than a truncation. */
    public function test_an_over_deep_document_is_rejected(): void
    {
        config(['meta.limits.json_depth' => 6]);

        $body = $this->encode(MetaEnvelopes::deepItem(20));

        $this->postRaw($body, $this->signature($body))->assertStatus(400);

        $this->assertNothingIngested();
    }

    /** The fan-out ceiling refuses the delivery WHOLE, before any group is written. */
    public function test_an_over_sized_event_count_is_refused_atomically(): void
    {
        config(['meta.limits.max_events' => 3]);

        $this->postSigned(MetaEnvelopes::manyItems(10))->assertStatus(413);

        $this->assertNothingIngested();
    }

    // ------------------------------------------------------------------ handshake

    public function test_the_handshake_echoes_the_challenge_for_the_right_token(): void
    {
        $this->get(self::WEBHOOK.'?hub.mode=subscribe&hub.verify_token='.self::FIXTURE_VERIFY_TOKEN.'&hub.challenge=chal_12345')
            ->assertOk()
            ->assertSee('chal_12345');
    }

    public function test_the_handshake_rejects_a_wrong_missing_or_malformed_token(): void
    {
        $cases = [
            '?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=chal',
            '?hub.mode=subscribe&hub.challenge=chal',
            '?hub.verify_token='.self::FIXTURE_VERIFY_TOKEN.'&hub.challenge=chal',
            '?hub.mode=unsubscribe&hub.verify_token='.self::FIXTURE_VERIFY_TOKEN.'&hub.challenge=chal',
            '?hub.mode=subscribe&hub.verify_token='.self::FIXTURE_VERIFY_TOKEN,
            // Array-shaped input: the classic way a string comparison is bypassed.
            '?hub.mode=subscribe&hub.verify_token[]='.self::FIXTURE_VERIFY_TOKEN.'&hub.challenge=chal',
        ];

        foreach ($cases as $query) {
            $this->get(self::WEBHOOK.$query)->assertForbidden();
        }
    }

    public function test_the_handshake_fails_closed_without_a_configured_token(): void
    {
        config(['meta.verify_token' => '']);

        $this->get(self::WEBHOOK.'?hub.mode=subscribe&hub.verify_token=&hub.challenge=chal')->assertForbidden();
    }

    public function test_the_handshake_is_refused_while_the_integration_is_disabled(): void
    {
        config(['meta.enabled' => false]);

        $this->get(self::WEBHOOK.'?hub.mode=subscribe&hub.verify_token='.self::FIXTURE_VERIFY_TOKEN.'&hub.challenge=chal')
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ logging

    /** Nothing secret, nothing private and nothing a person wrote may reach the log. */
    public function test_no_secret_body_or_pii_reaches_the_log(): void
    {
        $envelope = MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [
            MetaEnvelopes::textItem('mid.canary', 'Please email '.self::MARKER.'@example.com or call 09170000000'),
        ]);
        $body = $this->encode($envelope);
        $signature = $this->signature($body);

        $log = $this->captureLog(function () use ($body, $signature): void {
            $this->postRaw($body, $signature);
            // ... and a rejected one, so the failure path is covered too.
            $this->postRaw($body, 'sha256='.str_repeat('b', 64));
        });

        $this->assertStringContainsString('crm.inbound.meta', $log, 'the Meta layer must actually log');
        $this->assertStringNotContainsString(self::MARKER, $log, 'no message text or email');
        $this->assertStringNotContainsString('09170000000', $log, 'no phone');
        $this->assertStringNotContainsString(self::FIXTURE_APP_SECRET, $log, 'no app secret');
        $this->assertStringNotContainsString(self::FIXTURE_VERIFY_TOKEN, $log, 'no verify token');
        $this->assertStringNotContainsString($signature, $log, 'no signature value');
        $this->assertStringNotContainsString('sha256=', $log);
        $this->assertStringNotContainsString('"object":"page"', $log, 'no raw body');
    }

    /** A hostile resource id is bounded and stripped before it is logged. */
    public function test_hostile_resource_identifiers_are_sanitized_in_logs(): void
    {
        $hostile = "12345\n[2026-09-22 09:00:00] production.EMERGENCY: forged"."\x00".str_repeat('z', 300).'TAIL_CANARY';
        $envelope = ['object' => 'page', 'entry' => [['id' => $hostile, 'messaging' => [MetaEnvelopes::textItem()]]]];

        $log = $this->captureLog(fn () => $this->postSigned($envelope));

        $lines = array_values(array_filter(array_map('trim', explode("\n", $log)), fn ($l) => $l !== ''));

        $this->assertStringNotContainsString('TAIL_CANARY', $log, 'bounded');
        $this->assertStringNotContainsString('\u0000', $log, 'control characters stripped');

        foreach ($lines as $line) {
            $this->assertFalse(
                str_starts_with($line, '[2026-09-22 09:00:00] production.EMERGENCY'),
                'no log line may begin with attacker-supplied content',
            );
        }
    }
}
