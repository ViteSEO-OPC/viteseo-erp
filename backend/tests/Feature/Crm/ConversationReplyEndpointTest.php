<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Models\ProviderConnectionCredential;
use App\Modules\Crm\Requests\ConversationReplyRequest;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use App\Modules\Crm\Services\Outbound\OutboundMessagePersister;
use App\Modules\Crm\Services\Outbound\OutboundMessageService;
use App\Modules\Crm\Support\ReplyFailureCopy;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 3H.1 — the reply endpoint.
 *
 * THE ENDPOINT DECIDES NOTHING, AND MOST OF THIS FILE PROVES THAT. It cannot be told which account
 * to speak from, which tenant to act as, or whom to address; those are server decisions, and the
 * tests below attack each one rather than assert the happy path twice.
 *
 * NO REAL META REQUEST IS POSSIBLE HERE. `Http::preventStrayRequests()` is installed before every
 * test, so any request not matching a stub registered by the test itself fails the test instead of
 * leaving the building. Every identifier is synthetic.
 */
class ConversationReplyEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'SYNTHETIC-PAGE-TOKEN-NOT-REAL-000';

    private const APP_SECRET = 'SYNTHETIC-APP-SECRET-NOT-REAL-000';

    private const PAGE_ID = '900000000000001';

    private const IG_ID = '900000000000002';

    private const HOST = 'https://graph.test.invalid';

    /** Implementation detail that must never appear in a browser-visible response. */
    private const INTERNAL_MARKERS = [
        'meta-messenger-outbound',
        'meta-instagram-outbound',
        'GraphMetaSender',
        'OutboundAdapter',
        'ProviderCredential',
        'ProviderAccount',
        'ProviderConnection',
        'access_token',
        'appsecret_proof',
        'external_message_id',
        'sender_external_id',
        'recipient_external_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'meta.oauth.graph_host' => self::HOST,
            'meta.oauth.graph_version' => 'v26.0',
            'meta.app_secret' => self::APP_SECRET,
            // Mirrors production: absent means false. Individual tests opt in.
            'meta.outbound.enabled' => false,
            'meta.outbound.window_hours' => 24,
        ]);

        Http::preventStrayRequests();
    }

    // ------------------------------------------------------------- fixtures

    private function tenantId(): int
    {
        $org = $this->tenant();
        CurrentOrg::set($org->id);

        return (int) $org->id;
    }

    private function user(bool $mayReply = true, bool $superAdmin = false): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('crm.conversations.view', 'web'));

        if ($mayReply) {
            $user->givePermissionTo(Permission::findOrCreate(OutboundMessageService::PERMISSION, 'web'));
        }

        if ($superAdmin) {
            $user->assignRole('super_admin');
        }

        return $user;
    }

    /** A fully wired tenant: connection, account, credential, and a thread inside the window. */
    private function wired(string $channel = 'messenger', string $externalId = self::PAGE_ID): array
    {
        $org = $this->tenantId();
        $connection = new ProviderConnection([
            'provider' => 'meta',
            'status' => ProviderConnection::CONNECTED,
            'external_business_id' => '900000000000999',
            'connected_at' => now()->subDay(),
        ]);
        $connection->org_id = $org;
        $connection->save();

        $account = new ProviderAccount([
            'provider' => 'meta',
            'resource_type' => $channel === 'messenger' ? 'page' : 'instagram_business_account',
            'external_id' => $externalId,
            'label' => 'Synthetic Asset',
            'is_active' => true,
        ]);
        $account->org_id = $org;
        $account->connection_id = $connection->id;
        $account->save();

        $credential = new ProviderConnectionCredential;
        $credential->org_id = $org;
        $credential->provider_connection_id = $connection->id;
        $credential->provider_account_id = $account->id;
        $credential->credential_type = ProviderConnectionCredential::PAGE_ACCESS_TOKEN;
        $credential->access_token = self::TOKEN;
        $credential->save();

        return [$org, $this->conversation($channel, $externalId)];
    }

    private function conversation(string $channel = 'messenger', string $recipient = self::PAGE_ID, ?\Illuminate\Support\Carbon $inboundAt = null): Conversation
    {
        $conversation = Conversation::query()->create([
            'provider' => 'meta',
            'channel' => $channel,
            'external_conversation_id' => 'thread_'.uniqid(),
            'external_contact_id' => 'contact_'.uniqid(),
            'status' => 'open',
            'started_at' => now()->subHours(2),
            'last_message_at' => now(),
        ]);

        $message = new ConversationMessage([
            'provider' => 'meta',
            'channel' => $channel,
            'external_message_id' => 'mid_'.uniqid(),
            'direction' => 'inbound',
            'sender_external_id' => $conversation->external_contact_id,
            'recipient_external_id' => $recipient,
            'message_type' => 'text',
            'body' => 'Synthetic inbound turn',
            'occurred_at' => $inboundAt ?? now()->subMinutes(5),
            'received_at' => $inboundAt ?? now()->subMinutes(5),
        ]);
        $message->conversation_id = $conversation->id;
        $message->org_id = $conversation->org_id;
        $message->idempotency_key = hash('sha256', $conversation->id.uniqid());
        $message->save();

        return $conversation->refresh();
    }

    private function url(Conversation $conversation): string
    {
        return '/api/v1/crm/conversations/'.$conversation->id.'/reply';
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'body' => 'Synthetic reply',
            'clientRequestId' => (string) Str::uuid(),
        ], $overrides);
    }

    private function fakeAccepted(): void
    {
        $call = 0;
        Http::fake(['*' => function () use (&$call) {
            $call++;

            return Http::response(['message_id' => 'mid_synthetic_out_'.$call, 'recipient_id' => 'x'], 200);
        }]);
    }

    // ------------------------------------------------------------ VALIDATION

    public function test_a_missing_or_blank_body_is_rejected(): void
    {
        [, $conversation] = $this->wired();
        Sanctum::actingAs($this->user());

        $this->postJson($this->url($conversation), ['clientRequestId' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors('body');

        $this->postJson($this->url($conversation), $this->payload(['body' => '   ']))
            ->assertStatus(422)->assertJsonValidationErrors('body');

        Http::assertNothingSent();
    }

    public function test_a_body_beyond_the_ceiling_is_rejected(): void
    {
        [, $conversation] = $this->wired();
        Sanctum::actingAs($this->user());

        $this->postJson($this->url($conversation), $this->payload([
            'body' => str_repeat('a', ConversationReplyRequest::MAX_BODY + 1),
        ]))->assertStatus(422)->assertJsonValidationErrors('body');

        Http::assertNothingSent();
    }

    public function test_the_client_request_id_is_required_and_must_be_a_uuid(): void
    {
        [, $conversation] = $this->wired();
        Sanctum::actingAs($this->user());

        $this->postJson($this->url($conversation), ['body' => 'Synthetic reply'])
            ->assertStatus(422)->assertJsonValidationErrors('clientRequestId');

        $this->postJson($this->url($conversation), $this->payload(['clientRequestId' => 'not-a-uuid']))
            ->assertStatus(422)->assertJsonValidationErrors('clientRequestId');

        Http::assertNothingSent();
    }

    /**
     * A caller cannot name the sender, the tenant or the recipient — and is TOLD so.
     *
     * These fields have no property on OutboundMessageRequest, so none could take effect even if
     * accepted; the prohibition exists so a client that believes it is choosing finds out now.
     */
    #[DataProvider('forbiddenFields')]
    public function test_a_caller_cannot_supply_server_owned_fields(string $field): void
    {
        [, $conversation] = $this->wired();
        Sanctum::actingAs($this->user());

        $this->postJson($this->url($conversation), $this->payload([$field => 'anything']))
            ->assertStatus(422)->assertJsonValidationErrors($field);

        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function forbiddenFields(): array
    {
        return [
            'message type' => ['messageType'],
            'provider' => ['provider'],
            'channel' => ['channel'],
            'org id' => ['org_id'],
            'provider account' => ['provider_account_id'],
            'recipient' => ['recipient_external_id'],
            'lead' => ['lead_id'],
            'access token' => ['access_token'],
        ];
    }

    public function test_an_unknown_field_cannot_influence_the_send(): void
    {
        [$org, $conversation] = $this->wired();
        config(['meta.outbound.enabled' => true]);
        $this->fakeAccepted();
        Sanctum::actingAs($this->user());

        // A field nobody validates: it must be ignored, not honoured.
        $this->postJson($this->url($conversation), $this->payload(['somethingElse' => 'x']))
            ->assertStatus(201);

        Http::assertSent(function (\Illuminate\Http\Client\Request $r) use ($conversation): bool {
            $this->assertStringContainsString('/'.self::PAGE_ID.'/messages', $r->url());
            $this->assertSame($conversation->external_contact_id, $r->data()['recipient']['id']);

            return true;
        });

        $row = ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->firstOrFail();
        $this->assertSame($org, (int) $row->org_id);
    }

    // ----------------------------------------------------------------- AUTH

    public function test_an_unauthenticated_request_is_refused(): void
    {
        [, $conversation] = $this->wired();

        $this->postJson($this->url($conversation), $this->payload())->assertStatus(401);
        Http::assertNothingSent();
        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->count());
    }

    /**
     * The permission row is ABSENT in production, so this is the case that matters most.
     *
     * Spatie's middleware calls Laravel's `canAny()`, which returns false for an ability nothing
     * defines — it does NOT consult the permissions table directly and does NOT raise
     * PermissionDoesNotExist. The refusal is a clean 403 with no stack trace and no disclosure.
     */
    public function test_a_viewer_without_the_reply_ability_is_refused_cleanly(): void
    {
        [, $conversation] = $this->wired();
        Permission::where('name', OutboundMessageService::PERMISSION)->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->user(mayReply: false));
        $response = $this->postJson($this->url($conversation), $this->payload());

        $response->assertStatus(403);
        $this->assertStringNotContainsString('PermissionDoesNotExist', $response->getContent());
        $this->assertStringNotContainsString('Stack trace', $response->getContent());

        Http::assertNothingSent();
        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->count());
    }

    public function test_a_super_admin_passes_the_ability_check_without_an_explicit_grant(): void
    {
        [, $conversation] = $this->wired();
        config(['meta.outbound.enabled' => true]);
        $this->fakeAccepted();

        // No explicit reply grant: the pass comes from Gate::before(super_admin).
        Sanctum::actingAs($this->user(mayReply: false, superAdmin: true));

        $this->postJson($this->url($conversation), $this->payload())->assertStatus(201);
        Http::assertSentCount(1);
    }

    /**
     * A thread belonging to another tenant is a 404, not a filtered 200 or a 403.
     *
     * `tenant()` is memoized per test, so a genuine second tenant has to be created outright and
     * written through `CurrentOrg::runAs` — the same shape ConversationInboxTest uses. My first
     * attempt called `tenantId()` twice and got one org back, which quietly tested nothing.
     */
    public function test_another_tenants_conversation_is_unreachable(): void
    {
        $orgA = $this->tenantId();

        $orgB = \App\Models\Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        $foreign = CurrentOrg::runAs($orgB->id, fn () => $this->conversation());

        $this->assertSame(
            $orgB->id,
            (int) \Illuminate\Support\Facades\DB::table('crm_conversations')->where('id', $foreign->id)->value('org_id'),
            'The fixture must really belong to the other tenant.',
        );

        CurrentOrg::set($orgA);
        config(['meta.outbound.enabled' => true]);
        Sanctum::actingAs($this->user());

        $this->postJson($this->url($foreign), $this->payload())->assertStatus(404);

        Http::assertNothingSent();
        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->count());
    }

    // ------------------------------------------------------- DISABLED STATE

    public function test_an_authorized_reply_while_outbound_is_disabled_is_refused_safely(): void
    {
        [, $conversation] = $this->wired();
        // config already false, mirroring production.
        Sanctum::actingAs($this->user());

        $response = $this->postJson($this->url($conversation), $this->payload());

        $response->assertStatus(409);
        $response->assertJsonPath('accepted', false);
        $response->assertJsonPath('failureCode', OutboundFailureCode::NotConfigured->value);
        $response->assertJsonPath('retryAllowed', false);
        $response->assertJsonPath('message', null);

        // The exact operator-facing sentence, pinned: this is the one a real user will read most.
        $response->assertJsonPath('reason', 'Replies are not currently enabled for this conversation.');

        // And emphatically NOT the service's internal sentence.
        $this->assertStringNotContainsString('meta-messenger-outbound', $response->getContent());
        $this->assertStringNotContainsString('has no transport', $response->getContent());
        $this->assertStringNotContainsString('pending message is written', $response->getContent());

        Http::assertNothingSent();
        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->count());
    }

    /**
     * Every failure code maps to a stable, provider-neutral sentence.
     *
     * Driven off `OutboundFailureCode::cases()` rather than a hand-written list, so a code added
     * later cannot slip through untested — the match in ReplyFailureCopy is exhaustive, and this
     * asserts the sentences it produces are actually fit to ship.
     */
    #[DataProvider('everyFailureCode')]
    public function test_every_failure_code_has_safe_operator_copy(string $codeValue): void
    {
        $code = OutboundFailureCode::from($codeValue);
        $reason = ReplyFailureCopy::for($code);

        $this->assertNotSame('', trim($reason), $codeValue.' must have copy.');
        $this->assertMatchesRegularExpression('/[.!?]$/', $reason, $codeValue.' copy should read as a sentence.');

        foreach (self::INTERNAL_MARKERS as $marker) {
            $this->assertStringNotContainsStringIgnoringCase($marker, $reason,
                $codeValue.' copy must not mention '.$marker);
        }
    }

    /** @return array<string, array{string}> */
    public static function everyFailureCode(): array
    {
        $out = [];
        foreach (OutboundFailureCode::cases() as $case) {
            $out[$case->value] = [$case->value];
        }

        return $out;
    }

    /** Retry semantics are untouched by the copy change — asserted against the enum itself. */
    #[DataProvider('everyFailureCode')]
    public function test_retry_semantics_are_unchanged(string $codeValue): void
    {
        $code = OutboundFailureCode::from($codeValue);

        $expected = $codeValue === OutboundFailureCode::ProviderRateLimited->value;
        $this->assertSame($expected, $code->isRetryable(), $codeValue);

        if (in_array($codeValue, [
            OutboundFailureCode::ProviderUnavailable->value,
            OutboundFailureCode::DeliveryUnknown->value,
        ], true)) {
            $this->assertFalse($code->isRetryable(), $codeValue.' must never invite a retry.');
            $this->assertTrue($code->leavesDeliveryUnresolved(), $codeValue);
        }
    }

    // ------------------------------------------------------------- SUCCESS

    #[DataProvider('supportedChannels')]
    public function test_a_supported_channel_sends_once_and_returns_a_safe_body(string $channel, string $externalId): void
    {
        [, $conversation] = $this->wired($channel, $externalId);
        config(['meta.outbound.enabled' => true]);
        $this->fakeAccepted();
        Sanctum::actingAs($this->user());

        $response = $this->postJson($this->url($conversation), $this->payload());

        $response->assertStatus(201);
        $response->assertJsonPath('accepted', true);
        $response->assertJsonPath('failureCode', null);
        $response->assertJsonPath('retryAllowed', false);
        $response->assertJsonPath('message.deliveryStatus', OutboundMessagePersister::SENT);
        $response->assertJsonPath('message.failureCode', null);
        $response->assertJsonPath('message.providerMessageIdPresent', true);
        $this->assertNotNull($response->json('message.deliveredAt'));
        $this->assertNotNull($response->json('message.id'));

        Http::assertSentCount(1);
        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->count());
    }

    /** @return array<string, array{string, string}> */
    public static function supportedChannels(): array
    {
        return [
            'messenger' => ['messenger', self::PAGE_ID],
            'instagram' => ['instagram_dm', self::IG_ID],
        ];
    }

    // ---------------------------------------------------------- IDEMPOTENCY

    public function test_replaying_the_same_client_request_id_does_not_send_twice(): void
    {
        [, $conversation] = $this->wired();
        config(['meta.outbound.enabled' => true]);
        $this->fakeAccepted();
        Sanctum::actingAs($this->user());

        $payload = $this->payload();

        $first = $this->postJson($this->url($conversation), $payload)->assertStatus(201);
        $second = $this->postJson($this->url($conversation), $payload)->assertStatus(201);

        $this->assertSame($first->json('message.id'), $second->json('message.id'));
        Http::assertSentCount(1);
        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->count());
    }

    // -------------------------------------------------------------- FAILURES

    public function test_a_closed_messaging_window_is_refused_without_http_or_a_row(): void
    {
        $this->tenantId();
        $this->wired();
        config(['meta.outbound.enabled' => true]);
        $stale = $this->conversation('messenger', self::PAGE_ID, now()->subHours(25));
        Sanctum::actingAs($this->user());

        $response = $this->postJson($this->url($stale), $this->payload());

        $response->assertStatus(409);
        $response->assertJsonPath('failureCode', OutboundFailureCode::OutsideMessagingWindow->value);
        $response->assertJsonPath('retryAllowed', false);
        $response->assertJsonPath('message', null);

        Http::assertNothingSent();
        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->count());
    }

    #[DataProvider('providerFailures')]
    public function test_a_provider_refusal_maps_to_a_safe_status_and_code(
        int $httpStatus,
        array $error,
        OutboundFailureCode $expected,
        int $responseStatus,
        bool $retryAllowed,
        string $rowStatus,
    ): void {
        [, $conversation] = $this->wired();
        config(['meta.outbound.enabled' => true]);
        Http::fake(['*' => Http::response(['error' => $error + ['message' => 'META_PROSE_CANARY_9001']], $httpStatus)]);
        Sanctum::actingAs($this->user());

        $response = $this->postJson($this->url($conversation), $this->payload());

        $response->assertStatus($responseStatus);
        $response->assertJsonPath('accepted', false);
        $response->assertJsonPath('failureCode', $expected->value);
        $response->assertJsonPath('retryAllowed', $retryAllowed);
        $response->assertJsonPath('reason', ReplyFailureCopy::for($expected));

        // Meta's prose never reaches the client, and neither does ours.
        $this->assertStringNotContainsString('META_PROSE_CANARY_9001', $response->getContent());
        foreach (self::INTERNAL_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $response->getContent(), $marker.' leaked.');
        }

        $row = ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->firstOrFail();
        $this->assertSame($rowStatus, $row->delivery_status);
        $this->assertSame($expected->value, $row->failure_code);
    }

    /** @return array<string, array{int, array<string, int>, OutboundFailureCode, int, bool, string}> */
    public static function providerFailures(): array
    {
        return [
            'policy' => [400, ['code' => 10], OutboundFailureCode::PolicyViolation, 409, false, OutboundMessagePersister::FAILED],
            'outside window (remote)' => [400, ['code' => 10, 'subcode' => 2018278], OutboundFailureCode::OutsideMessagingWindow, 409, false, OutboundMessagePersister::FAILED],
            'credential rejected' => [401, ['code' => 190], OutboundFailureCode::CredentialRejected, 409, false, OutboundMessagePersister::FAILED],
            'rate limited' => [429, ['code' => 4], OutboundFailureCode::ProviderRateLimited, 429, true, OutboundMessagePersister::FAILED],
            // AMBIGUOUS: the row stays pending, and retry is refused.
            'provider fault' => [500, ['code' => 1], OutboundFailureCode::ProviderUnavailable, 502, false, OutboundMessagePersister::PENDING],
        ];
    }

    /**
     * A timeout leaves delivery unknown: `retryAllowed` must be false and nothing may re-send.
     *
     * `Http::assertSentCount()` cannot be used here — Laravel records a request only once it
     * completes, so a throwing stub leaves the log empty and "exactly one attempt" would pass
     * against zero. The counter lives inside the closure, captured BY REFERENCE.
     */
    public function test_an_ambiguous_timeout_reports_no_retry_and_never_replays(): void
    {
        [, $conversation] = $this->wired();
        config(['meta.outbound.enabled' => true]);

        $attempts = 0;
        Http::fake(['*' => function () use (&$attempts): void {
            $attempts++;

            throw new ConnectionException('Synthetic timeout - outcome unknown.');
        }]);

        Sanctum::actingAs($this->user());
        $payload = $this->payload();

        $first = $this->postJson($this->url($conversation), $payload);
        $first->assertStatus(502);
        $first->assertJsonPath('failureCode', OutboundFailureCode::DeliveryUnknown->value);
        $first->assertJsonPath('retryAllowed', false);
        $first->assertJsonPath('message.deliveryStatus', OutboundMessagePersister::PENDING);

        // The client replays the SAME id. The endpoint must not post again.
        $second = $this->postJson($this->url($conversation), $payload);
        $second->assertStatus(502);
        $second->assertJsonPath('failureCode', OutboundFailureCode::DeliveryUnknown->value);
        $second->assertJsonPath('retryAllowed', false);

        $this->assertSame(1, $attempts, 'An ambiguous outcome must never produce a second provider POST.');
        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->count());
    }

    // -------------------------------------------------------------- SECURITY

    public function test_the_response_leaks_no_identifier_credential_or_provider_prose(): void
    {
        [, $conversation] = $this->wired();
        config(['meta.outbound.enabled' => true]);
        $this->fakeAccepted();
        Sanctum::actingAs($this->user());

        $content = $this->postJson($this->url($conversation), $this->payload())
            ->assertStatus(201)->getContent();

        foreach (array_merge(self::INTERNAL_MARKERS, [
            self::TOKEN,
            self::APP_SECRET,
            self::PAGE_ID,
            (string) $conversation->external_contact_id,
            'externalMessageId',
            'provider_account_id',
            'provider_connection_id',
            'idempotency_key',
        ]) as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $content, $forbidden.' must never reach the client.');
        }
    }
}
