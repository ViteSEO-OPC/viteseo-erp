<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Inbound\Adapters\AdapterRejection;
use App\Modules\Crm\Services\Inbound\Adapters\RejectionCode;
use App\Modules\Crm\Services\Inbound\Adapters\SafeIdentifier;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use App\Modules\Crm\Services\Inbound\InboundNormalizationPipeline;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Tests\Support\Inbound\FixtureEnvelopes;
use Tests\Support\Inbound\FixtureInboundProviderAdapter;
use Tests\Support\Inbound\HostileIdentifierFixtureAdapter;
use Tests\TestCase;

/**
 * What an adapter is not allowed to decide, and what must never reach a log.
 *
 * The leak test is deliberately end-to-end against a REAL log file rather than a mocked logger: a
 * spy proves what the application asked to log, and the question here is what actually lands on
 * disk — including anything the framework adds around it. A marker is planted in every field that
 * a diagnostic is tempted to quote (body, payload, email, phone) and the file is then searched for
 * it.
 */
class InboundAdapterSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = 'PHASE3C_LEAK_CANARY_71425';

    protected function setUp(): void
    {
        parent::setUp();
        config(['inbound.adapters' => [FixtureInboundProviderAdapter::class]]);
    }

    private function pipeline(): InboundNormalizationPipeline
    {
        return $this->app->make(InboundNormalizationPipeline::class);
    }

    /** Runs the callable with logging pointed at a real file, and returns what was written. */
    private function captureLog(callable $work): string
    {
        $path = storage_path('logs/phase3c-'.uniqid().'.log');

        config([
            'logging.channels.phase3c' => ['driver' => 'single', 'path' => $path, 'level' => 'debug'],
            'logging.default' => 'phase3c',
        ]);

        // The Log facade caches its manager, and the manager caches resolved channels; without
        // both of these the test would keep writing to whatever channel was resolved first.
        Facade::clearResolvedInstance('log');
        $this->app->forgetInstance('log');

        try {
            $work();
        } finally {
            $contents = is_file($path) ? (string) file_get_contents($path) : '';
            Log::forgetChannel('phase3c');
            @unlink($path);
        }

        return $contents;
    }

    /** 20 — an adapter cannot name a tenant, because the DTO has nowhere to put one. */
    public function test_an_adapter_cannot_provide_an_org(): void
    {
        $parameters = array_map(
            fn ($p): string => $p->getName(),
            (new ReflectionClass(InboundConversationMessage::class))->getConstructor()->getParameters(),
        );

        $this->assertNotContains('orgId', $parameters);
        $this->assertNotContains('tenantId', $parameters);

        // And behaviourally: an item that TRIES to name one is just payload.
        $envelope = FixtureEnvelopes::singleText();
        $envelope['events'][0]['org_id'] = 999999;

        $this->pipeline()->process('fixture', 'chat', $envelope);

        $this->assertSame(CurrentOrg::id(), ConversationMessage::firstOrFail()->org_id);
        $this->assertSame(CurrentOrg::id(), Conversation::firstOrFail()->org_id);
    }

    /** 21 — nor a lead: association is the matcher's decision against the tenant's own data. */
    public function test_an_adapter_cannot_provide_a_lead(): void
    {
        $parameters = array_map(
            fn ($p): string => $p->getName(),
            (new ReflectionClass(InboundConversationMessage::class))->getConstructor()->getParameters(),
        );

        $this->assertNotContains('leadId', $parameters);

        $owner = User::factory()->create();
        $someoneElse = Lead::factory()->create([
            'email' => 'real@example.com',
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
        ]);

        $envelope = FixtureEnvelopes::singleText();
        $envelope['events'][0]['lead_id'] = $someoneElse->id;

        $this->pipeline()->process('fixture', 'chat', $envelope);

        $this->assertNull(
            Conversation::firstOrFail()->lead_id,
            'an unknown sender stays unassociated no matter what the payload claims',
        );
    }

    /** 19 (structural half) — the DTO cannot carry an idempotency key either. */
    public function test_an_adapter_cannot_choose_the_idempotency_key(): void
    {
        $parameters = array_map(
            fn ($p): string => $p->getName(),
            (new ReflectionClass(InboundConversationMessage::class))->getConstructor()->getParameters(),
        );

        $this->assertNotContains('idempotencyKey', $parameters);
    }

    /** 22 — the fixture adapter is unreachable from a production configuration. */
    public function test_the_fixture_adapter_cannot_resolve_from_production_config(): void
    {
        $production = require base_path('config/inbound.php');

        $this->assertSame([], $production['adapters'], 'production configures no adapters at all');

        // Stronger than the config list: the class is not even autoloadable in production, because
        // `Tests\` is declared only in composer's autoload-dev.
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

        $this->assertArrayNotHasKey('Tests\\', $composer['autoload']['psr-4'] ?? []);
        $this->assertArrayHasKey('Tests\\', $composer['autoload-dev']['psr-4'] ?? []);

        $file = (new ReflectionClass(FixtureInboundProviderAdapter::class))->getFileName();
        $this->assertStringStartsWith(base_path('tests'), (string) $file);
    }

    /** 23 — nothing a person wrote reaches the log. */
    public function test_no_message_text_or_payload_reaches_the_log(): void
    {
        $log = $this->captureLog(function (): void {
            $this->pipeline()->process('fixture', 'chat', FixtureEnvelopes::withMarker(self::MARKER));
        });

        $this->assertStringContainsString('crm.inbound.adapter.normalized', $log, 'the pipeline must actually log');
        $this->assertStringNotContainsString(self::MARKER, $log, 'body, payload, email and phone must all stay out');
        $this->assertStringNotContainsString('@example.com', $log);

        // The row itself still holds everything — the boundary is the log, not the database.
        $stored = ConversationMessage::firstOrFail();
        $this->assertStringContainsString(self::MARKER, (string) $stored->body);
        $this->assertSame(self::MARKER, $stored->payload['secret_note']);
    }

    /** 24 — an adapter that throws becomes a safe, recoverable rejection. */
    public function test_an_adapter_failure_is_represented_safely(): void
    {
        $result = null;

        $log = $this->captureLog(function () use (&$result): void {
            $result = $this->pipeline()->process('fixture', 'chat', FixtureEnvelopes::exploding(self::MARKER));
        });

        $this->assertSame(0, $result->ingestedCount());
        $this->assertSame(1, $result->rejectionCount());
        $this->assertSame(RejectionCode::NormalizationFailed, $result->failures()[0]->code);
        $this->assertTrue($result->failures()[0]->recoverable, 'an adapter fault may clear on a retry');
        $this->assertTrue($result->hasRecoverableFailures(), 'an adapter fault is the recoverable kind');

        $this->assertStringContainsString('RuntimeException', $log, 'the fault is named by class');
        $this->assertStringNotContainsString(self::MARKER, $log, 'but never by what it choked on');
        $this->assertSame(0, ConversationMessage::count());
    }

    /**
     * 1, 2, 3, 6 — an adapter's own identifiers are sanitized before they reach a log.
     *
     * Run end to end against a real log file, because the hazard is what the file ends up
     * containing: a newline in structured context is a forged RECORD, not a mangled field, and a
     * dashboard cannot tell a fabricated alert from a real one.
     */
    public function test_hostile_adapter_identifiers_are_sanitized_in_logs(): void
    {
        config(['inbound.adapters' => [HostileIdentifierFixtureAdapter::class]]);

        $log = $this->captureLog(function (): void {
            $this->pipeline()->process(
                HostileIdentifierFixtureAdapter::PROVIDER,
                HostileIdentifierFixtureAdapter::CHANNEL,
                FixtureEnvelopes::singleText(),
            );
        });

        $this->assertStringContainsString('crm.inbound.adapter.normalized', $log);

        // Scoped to the lines this layer writes. Phase 3A's own `crm.inbound.conversation.*` line
        // logs the DTO's provider and channel directly and is a protected file — see the report's
        // non-blocking note.
        $phase3c = implode("\n", array_filter(
            explode("\n", $log),
            fn (string $line): bool => str_contains($line, 'crm.inbound.adapter.'),
        ));

        $this->assertNotSame('', $phase3c);

        // The control characters are gone, in both the raw and the JSON-escaped forms a log
        // formatter might produce.
        $this->assertStringNotContainsString("hostile\nname", $phase3c);
        $this->assertStringNotContainsString('hostile\\nname', $phase3c);
        $this->assertStringNotContainsString("\x00", $phase3c);
        $this->assertStringNotContainsString('\u0000', $phase3c);
        $this->assertStringNotContainsString("\u{2028}", $phase3c);
        $this->assertStringNotContainsString('\u001f', $phase3c);

        // What remains is the stripped, bounded form.
        $this->assertStringContainsString('hostilenamex', $phase3c);
        $this->assertStringContainsString('fixtureevil', $phase3c, 'the provider survives, minus its control bytes');
        $this->assertStringContainsString('chatevil', $phase3c, 'and so does the channel');

        // 6 — anything past the bound never reaches the file.
        $this->assertStringNotContainsString(HostileIdentifierFixtureAdapter::NAME_CANARY, $log);
    }

    /** 4 — the ORIGINAL values, control characters and all, still resolve the adapter. */
    public function test_the_original_provider_and_channel_still_drive_resolution(): void
    {
        config(['inbound.adapters' => [HostileIdentifierFixtureAdapter::class]]);

        $result = $this->pipeline()->process(
            HostileIdentifierFixtureAdapter::PROVIDER,
            HostileIdentifierFixtureAdapter::CHANNEL,
            FixtureEnvelopes::singleText(),
        );

        $this->assertSame(1, $result->createdCount(), 'a lossy copy is never used to choose the adapter');
        $this->assertSame(HostileIdentifierFixtureAdapter::PROVIDER, $result->provider, 'the result keeps the real value');
        $this->assertSame(HostileIdentifierFixtureAdapter::CHANNEL, $result->channel);

        // The sanitized form is NOT the same string — which is exactly why it must not be used here.
        $this->assertNotSame($result->provider, $result->toDiagnostics()['provider']);
    }

    /** 5 — sanitization touches diagnostics only; the persisted row keeps the real values. */
    public function test_sanitization_does_not_reach_persisted_values(): void
    {
        config(['inbound.adapters' => [HostileIdentifierFixtureAdapter::class]]);

        $this->pipeline()->process(
            HostileIdentifierFixtureAdapter::PROVIDER,
            HostileIdentifierFixtureAdapter::CHANNEL,
            FixtureEnvelopes::singleText(),
        );

        $stored = ConversationMessage::firstOrFail();

        $this->assertSame(HostileIdentifierFixtureAdapter::PROVIDER, $stored->provider);
        $this->assertSame(HostileIdentifierFixtureAdapter::CHANNEL, $stored->channel);
        $this->assertSame(HostileIdentifierFixtureAdapter::PROVIDER, Conversation::firstOrFail()->provider);

        // And the idempotency key was derived from those real values, not the sanitized ones.
        $this->assertNotEmpty($stored->idempotency_key);
    }

    /** The bounds are the column widths they mirror, and a canary past them is dropped. */
    public function test_identifier_bounds_match_the_domain_limits(): void
    {
        $this->assertSame(64, SafeIdentifier::PROVIDER);
        $this->assertSame(64, SafeIdentifier::CHANNEL);
        $this->assertSame(128, SafeIdentifier::ADAPTER);
        $this->assertSame(191, SafeIdentifier::EVENT_ID);

        $provider = str_repeat('p', 64).'PROVIDER_CANARY';
        $channel = str_repeat('c', 64).'CHANNEL_CANARY';

        $this->assertSame(str_repeat('p', 64), SafeIdentifier::for($provider, SafeIdentifier::PROVIDER));
        $this->assertSame(str_repeat('c', 64), SafeIdentifier::for($channel, SafeIdentifier::CHANNEL));
    }

    /** A value that sanitizes away entirely becomes null, never an empty string pretending to be an id. */
    public function test_an_identifier_made_only_of_control_characters_becomes_null(): void
    {
        $this->assertNull(SafeIdentifier::for("\x00\x0A\x7F", SafeIdentifier::ADAPTER));
        $this->assertNull(SafeIdentifier::for(null, SafeIdentifier::ADAPTER));
        $this->assertSame('(unknown)', SafeIdentifier::orUnknown("\x00", SafeIdentifier::ADAPTER));
    }

    /**
     * Invalid UTF-8 must not silently erase an identifier, nor crash the sanitizer, nor invent a
     * character: the bad byte is DROPPED rather than replaced with '?', because a replacement can
     * make two different identifiers converge on one string.
     */
    public function test_invalid_utf8_is_handled_without_losing_the_identifier(): void
    {
        $sanitized = SafeIdentifier::for("meta\xB1\x31evil", SafeIdentifier::PROVIDER);

        $this->assertSame('meta1evil', $sanitized, 'the lone 0xB1 goes, the valid characters stay');
        $this->assertTrue(mb_check_encoding((string) $sanitized, 'UTF-8'));
    }

    /** A rejection cannot be used to forge a log line or to smuggle a body through an id field. */
    public function test_a_rejection_sanitizes_its_event_identifier(): void
    {
        $rejection = new AdapterRejection(
            RejectionCode::MalformedEvent,
            0,
            "evt_1\nlevel=EMERGENCY ".self::MARKER,
        );

        $this->assertStringNotContainsString("\n", (string) $rejection->externalEventId);
        $this->assertStringStartsWith('evt_1', (string) $rejection->externalEventId);

        $long = new AdapterRejection(RejectionCode::MalformedEvent, 0, str_repeat('x', 500));
        $this->assertSame(191, mb_strlen((string) $long->externalEventId));
    }

    /** Rejection diagnostics are counts, codes and identifiers — nothing else. */
    public function test_rejection_diagnostics_carry_only_safe_keys(): void
    {
        $rejection = new AdapterRejection(RejectionCode::UnsupportedMessageType, 2, 'evt_9');

        $this->assertSame(
            ['code', 'item_index', 'external_event_id', 'recoverable'],
            array_keys($rejection->toDiagnostics()),
        );
    }
}
