<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Models\ProviderConnectionCredential;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use App\Modules\Crm\Services\Outbound\OutboundMessagePersister;
use App\Modules\Crm\Services\Outbound\OutboundMessageRequest;
use App\Modules\Crm\Services\Outbound\OutboundMessageService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 3G — the Meta outbound transport, exercised entirely against a faked HTTP layer.
 *
 * NO REAL META CALL IS POSSIBLE FROM THIS FILE. `Http::preventStrayRequests()` is installed before
 * every test, so any request that does not match a stub registered by the test itself fails the
 * test rather than leaving the building. No production token, Page id or customer identifier
 * appears anywhere here; every value is synthetic and obviously so.
 *
 * ONE STUB PER TEST, ALWAYS. `Http::fake()` MERGES stubs rather than replacing them, and the first
 * registered wildcard keeps answering every subsequent call — a trap that has produced three
 * separate false passes in this project. Where a test needs the response to change between calls it
 * mutates a holder captured by reference, never by registering a second fake and never through an
 * arrow function, which would capture the holder by value.
 */
class MetaOutboundTransportTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'SYNTHETIC-PAGE-TOKEN-NOT-REAL-000';

    private const APP_SECRET = 'SYNTHETIC-APP-SECRET-NOT-REAL-000';

    private const PAGE_ID = '900000000000001';

    private const IG_ID = '900000000000002';

    private const HOST = 'https://graph.test.invalid';

    private const VERSION = 'v26.0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Never a real Meta host, even if a URL were somehow built wrong.
        config([
            'meta.oauth.graph_host' => self::HOST,
            'meta.oauth.graph_version' => self::VERSION,
            'meta.app_secret' => self::APP_SECRET,
            'meta.outbound.enabled' => true,
            'meta.outbound.window_hours' => 24,
        ]);

        Http::preventStrayRequests();
    }

    // ---------------------------------------------------------------- fixtures

    private function tenantId(): int
    {
        $org = $this->tenant();
        CurrentOrg::set($org->id);

        return (int) $org->id;
    }

    private function actor(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate(OutboundMessageService::PERMISSION, 'web'));

        return $user;
    }

    private function connection(
        int $orgId,
        string $status = ProviderConnection::CONNECTED,
        string $businessId = '900000000000999',
    ): ProviderConnection {
        $connection = new ProviderConnection([
            'provider' => 'meta',
            'status' => $status,
            'external_business_id' => $businessId,
            'connected_at' => now()->subDay(),
        ]);
        $connection->org_id = $orgId;
        $connection->save();

        return $connection;
    }

    private function account(
        int $orgId,
        ?ProviderConnection $connection,
        string $resourceType = 'page',
        string $externalId = self::PAGE_ID,
    ): ProviderAccount {
        $account = new ProviderAccount([
            'provider' => 'meta',
            'resource_type' => $resourceType,
            'external_id' => $externalId,
            'label' => 'Synthetic Asset',
            'is_active' => true,
        ]);
        $account->org_id = $orgId;
        $account->connection_id = $connection?->id;
        $account->save();

        return $account;
    }

    private function credential(
        int $orgId,
        ProviderConnection $connection,
        ProviderAccount $account,
        ?Carbon $expiresAt = null,
    ): ProviderConnectionCredential {
        $credential = new ProviderConnectionCredential;
        $credential->org_id = $orgId;
        $credential->provider_connection_id = $connection->id;
        $credential->provider_account_id = $account->id;
        $credential->credential_type = ProviderConnectionCredential::PAGE_ACCESS_TOKEN;
        $credential->access_token = self::TOKEN;
        $credential->expires_at = $expiresAt;
        $credential->save();

        return $credential;
    }

    /** A thread whose newest inbound turn is recent enough for the window to be open. */
    private function conversation(
        string $channel = 'messenger',
        string $recipient = self::PAGE_ID,
        ?Carbon $inboundAt = null,
    ): Conversation {
        $conversation = Conversation::query()->create([
            'provider' => 'meta',
            'channel' => $channel,
            'external_conversation_id' => 'thread_'.uniqid(),
            'external_contact_id' => 'contact_'.uniqid(),
            'status' => 'open',
            'started_at' => now()->subHours(2),
            'last_message_at' => now(),
        ]);

        $this->inbound($conversation, $recipient, $inboundAt ?? now()->subMinutes(5));

        return $conversation->refresh();
    }

    private function inbound(Conversation $conversation, string $recipient, Carbon $occurredAt): void
    {
        $message = new ConversationMessage([
            'provider' => 'meta',
            'channel' => $conversation->channel,
            'external_message_id' => 'mid_'.uniqid(),
            'direction' => 'inbound',
            'sender_external_id' => $conversation->external_contact_id,
            'recipient_external_id' => $recipient,
            'message_type' => 'text',
            'body' => 'Synthetic inbound turn',
            'occurred_at' => $occurredAt,
            'received_at' => $occurredAt,
        ]);
        $message->conversation_id = $conversation->id;
        $message->org_id = $conversation->org_id;
        $message->idempotency_key = hash('sha256', $conversation->id.uniqid());
        $message->save();
    }

    /** A fully wired Messenger tenant: connection, account, credential, open thread. */
    private function messengerTenant(): array
    {
        $org = $this->tenantId();
        $connection = $this->connection($org);
        $account = $this->account($org, $connection);
        $this->credential($org, $connection, $account);

        return [$org, $this->conversation(), $this->actor()];
    }

    private function instagramTenant(): array
    {
        $org = $this->tenantId();
        $connection = $this->connection($org);
        $account = $this->account($org, $connection, 'instagram_business_account', self::IG_ID);
        $this->credential($org, $connection, $account);

        return [$org, $this->conversation('instagram_dm', self::IG_ID), $this->actor()];
    }

    private function service(): OutboundMessageService
    {
        return app(OutboundMessageService::class);
    }

    private function request(Conversation $c, User $actor, ?string $clientRequestId = null): OutboundMessageRequest
    {
        return new OutboundMessageRequest(
            conversation: $c,
            actor: $actor,
            body: 'Synthetic outbound reply',
            clientRequestId: $clientRequestId,
        );
    }

    /**
     * The one stub. Everything else in a test reads what it captured.
     *
     * A DISTINCT message id per call, because `crm_messages` carries
     * UNIQUE(org_id, provider, channel, external_message_id) and a stub that answered with one
     * fixed id would make two legitimate sends collide on an index rather than on the behaviour
     * under test. The counter is captured BY REFERENCE: an arrow function would capture it by
     * value and every call would see the same number.
     */
    private function fakeAccepted(?string $messageId = null): void
    {
        $call = 0;

        // No return type: inside a fake callback Http::response() yields a PromiseInterface,
        // not a Response.
        Http::fake(['*' => function () use (&$call, $messageId) {
            $call++;

            return Http::response([
                'message_id' => $messageId ?? 'mid_synthetic_out_'.$call,
                'recipient_id' => 'x',
            ], 200);
        }]);
    }

    /**
     * A stub that times out, and a counter of how many times it was reached.
     *
     * `Http::assertSentCount()` CANNOT be used for this: Laravel records a request only once it
     * completes, so a stub that throws leaves the recorded list empty and an assertion of "exactly
     * one attempt" would pass trivially against zero attempts. Counting inside the closure is the
     * only way to prove that no automatic second POST happened.
     *
     * @return \Closure(): int
     */
    private function fakeTimeout(): \Closure
    {
        $attempts = 0;

        Http::fake(['*' => function () use (&$attempts): void {
            $attempts++;

            throw new ConnectionException('Synthetic timeout - outcome unknown.');
        }]);

        // `use (&$attempts)`, NOT an arrow function: `fn () => $attempts` would capture the
        // counter by value at zero and cheerfully report zero attempts forever.
        return function () use (&$attempts): int {
            return $attempts;
        };
    }

    /** @param array<string, mixed> $error */
    private function fakeError(int $status, array $error): void
    {
        Http::fake(['*' => Http::response(['error' => $error], $status)]);
    }

    // ------------------------------------------------------------- A. Messenger

    public function test_messenger_posts_the_expected_graph_request(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeAccepted();

        $result = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($result->accepted, (string) $result->reason);
        Http::assertSentCount(1);

        Http::assertSent(function (Request $r) use ($conversation): bool {
            $this->assertSame('POST', $r->method());
            $this->assertStringStartsWith(
                self::HOST.'/'.self::VERSION.'/'.self::PAGE_ID.'/messages',
                $r->url(),
            );

            $body = $r->data();
            $this->assertSame($conversation->external_contact_id, $body['recipient']['id']);
            $this->assertSame('RESPONSE', $body['messaging_type']);
            $this->assertSame('Synthetic outbound reply', $body['message']['text']);

            return true;
        });
    }

    /** Messenger keeps its own node: /{page-id}/messages, the shape proven live on 2026-09-24. */
    public function test_messenger_still_posts_to_the_page_node_with_messaging_type(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeAccepted();

        $this->service()->send($this->request($conversation, $actor), $org);

        Http::assertSent(function (Request $r): bool {
            $this->assertSame(
                '/'.self::VERSION.'/'.self::PAGE_ID.'/messages',
                parse_url($r->url(), PHP_URL_PATH),
            );
            $this->assertStringNotContainsString('/me/messages', $r->url());
            $this->assertSame('RESPONSE', $r->data()['messaging_type']);

            return true;
        });
    }

    public function test_messenger_captures_the_provider_message_id_and_marks_sent(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeAccepted('mid_synthetic_captured');

        $result = $this->service()->send($this->request($conversation, $actor, 'req-1'), $org);

        $this->assertTrue($result->accepted);
        $this->assertSame('mid_synthetic_captured', $result->providerMessageId);

        $row = ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->firstOrFail();

        $this->assertSame(OutboundMessagePersister::SENT, $row->delivery_status);
        $this->assertSame('mid_synthetic_captured', $row->external_message_id);
        $this->assertNotNull($row->delivered_at);
        $this->assertNull($row->failure_code);
    }

    public function test_the_appsecret_proof_is_an_hmac_of_the_token_under_the_app_secret(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeAccepted();

        $this->service()->send($this->request($conversation, $actor), $org);

        Http::assertSent(function (Request $r): bool {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
            $this->assertSame(
                hash_hmac('sha256', self::TOKEN, self::APP_SECRET),
                $query['appsecret_proof'] ?? null,
            );

            return true;
        });
    }

    public function test_no_appsecret_proof_is_sent_when_no_app_secret_is_configured(): void
    {
        config(['meta.app_secret' => '']);
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeAccepted();

        $this->service()->send($this->request($conversation, $actor), $org);

        Http::assertSent(fn (Request $r): bool => ! str_contains($r->url(), 'appsecret_proof'));
    }

    // ------------------------------------------------------------- B. Instagram

    /**
     * Instagram posts to `me`, NOT to the Instagram business account id.
     *
     * THE REGRESSION TEST FOR THE 2026-09-24 LIVE FAILURE. The first production Instagram reply
     * posted to /{ig-business-account-id}/messages and was refused with Meta error code 3: with
     * Instagram API for Facebook Login the messaging edge belongs to the PAGE, and a Page access
     * token resolves `me` to exactly that Page. Read-only probes confirmed the asymmetry —
     * GET /{ig-business-account-id}/conversations returns the same code 3, while
     * GET /{page-id}/conversations?platform=instagram returns the thread.
     */
    public function test_instagram_posts_to_me_and_never_to_the_business_account_node(): void
    {
        [$org, $conversation, $actor] = $this->instagramTenant();
        $this->fakeAccepted('mid_synthetic_ig');

        $result = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($result->accepted, (string) $result->reason);
        $this->assertSame('mid_synthetic_ig', $result->providerMessageId);

        Http::assertSent(function (Request $r) use ($conversation): bool {
            $this->assertStringStartsWith(
                self::HOST.'/'.self::VERSION.'/me/messages',
                $r->url(),
            );

            // The defect, pinned: the IG business account id must not appear in the URL at all.
            $this->assertStringNotContainsString(self::IG_ID, $r->url());
            $this->assertStringNotContainsString('/'.self::IG_ID.'/messages', $r->url());

            $body = $r->data();
            $this->assertSame($conversation->external_contact_id, $body['recipient']['id']);
            $this->assertSame('Synthetic outbound reply', $body['message']['text']);
            $this->assertArrayNotHasKey('messaging_type', $body);

            return true;
        });
    }

    /**
     * The two channels address different Graph nodes with the same kind of credential.
     *
     * Asserted side by side so that a future change which collapses them back into one node fails
     * here rather than in production.
     */
    public function test_the_two_channels_address_different_graph_nodes(): void
    {
        $org = $this->tenantId();
        $connection = $this->connection($org);
        $page = $this->account($org, $connection);
        $ig = $this->account($org, $connection, 'instagram_business_account', self::IG_ID);
        $this->credential($org, $connection, $page);
        $this->credential($org, $connection, $ig);
        $actor = $this->actor();

        $this->fakeAccepted();
        $this->service()->send($this->request($this->conversation(), $actor, 'req-m'), $org);
        $this->service()->send($this->request($this->conversation('instagram_dm', self::IG_ID), $actor, 'req-i'), $org);

        $urls = [];
        Http::assertSent(function (Request $r) use (&$urls): bool {
            $urls[] = (string) parse_url($r->url(), PHP_URL_PATH);

            return true;
        });

        sort($urls);
        $this->assertSame([
            '/'.self::VERSION.'/'.self::PAGE_ID.'/messages',
            '/'.self::VERSION.'/me/messages',
        ], $urls);
    }

    public function test_instagram_authenticates_with_the_linked_page_credential(): void
    {
        [$org, $conversation, $actor] = $this->instagramTenant();
        $this->fakeAccepted();

        $this->service()->send($this->request($conversation, $actor), $org);

        Http::assertSent(function (Request $r): bool {
            // The same stored page_access_token the resolver returns — no second mechanism. The
            // endpoint node changed; the credential path deliberately did not.
            $this->assertTrue($r->hasHeader('Authorization', 'Bearer '.self::TOKEN));

            // appsecret_proof survives the node change.
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $this->assertSame(hash_hmac('sha256', self::TOKEN, self::APP_SECRET), $q['appsecret_proof'] ?? null);

            return true;
        });
    }

    /** Resource resolution is untouched: the IG account still owns tenancy and the credential. */
    public function test_instagram_still_resolves_the_business_account_as_the_resource(): void
    {
        [$org, $conversation] = $this->instagramTenant();

        $res = app(\App\Modules\Crm\Services\Outbound\OutboundResourceResolver::class)
            ->resolve($conversation, 'instagram_business_account');

        $this->assertNotInstanceOf(OutboundFailureCode::class, $res);
        $this->assertSame('instagram_business_account', $res->resourceType);
        $this->assertSame(self::IG_ID, $res->externalId);
        $this->assertSame($org, $res->orgId);
    }

    // -------------------------------------------------------------- C. Security

    public function test_the_access_token_never_appears_in_the_url(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeAccepted();

        $this->service()->send($this->request($conversation, $actor), $org);

        Http::assertSent(function (Request $r): bool {
            $this->assertStringNotContainsString(self::TOKEN, $r->url());
            $this->assertStringNotContainsString('access_token=', $r->url());
            $this->assertTrue($r->hasHeader('Authorization', 'Bearer '.self::TOKEN));

            return true;
        });
    }

    public function test_no_secret_and_no_provider_prose_reaches_the_log(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();

        $prose = 'META PROSE QUOTING THE CUSTOMER MESSAGE BACK';
        $this->fakeError(400, ['message' => $prose, 'type' => 'OAuthException', 'code' => 10]);

        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$lines): void {
            $lines[] = $e->message.' '.json_encode($e->context);
        });

        $result = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertNotEmpty($lines, 'The rejection should have been logged at all.');

        $joined = implode("\n", $lines);
        $this->assertStringNotContainsString(self::TOKEN, $joined);
        $this->assertStringNotContainsString(self::APP_SECRET, $joined);
        $this->assertStringNotContainsString($prose, $joined);
        $this->assertStringNotContainsString('Synthetic outbound reply', $joined);
    }

    public function test_provider_prose_is_never_persisted_on_the_failed_row(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();

        $prose = 'META PROSE QUOTING THE CUSTOMER MESSAGE BACK';
        $this->fakeError(400, ['message' => $prose, 'code' => 10]);

        $this->service()->send($this->request($conversation, $actor), $org);

        $row = ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->firstOrFail();

        $this->assertSame(OutboundMessagePersister::FAILED, $row->delivery_status);
        $this->assertSame(OutboundFailureCode::PolicyViolation->value, $row->failure_code);
        $this->assertStringNotContainsString($prose, json_encode($row->toArray()) ?: '');
    }

    public function test_another_orgs_conversation_is_refused_before_any_http(): void
    {
        [, $conversation, $actor] = $this->messengerTenant();

        $result = $this->service()->send($this->request($conversation, $actor), 999_999);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::TenantMismatch, $result->failure);
        Http::assertNothingSent();
    }

    /**
     * An account released by a re-bootstrap cannot send.
     *
     * MODELS WHAT `MetaPlatformBootstrap::release()` ACTUALLY DOES, which is two things: it deletes
     * the account's credential AND nulls its `connection_id`. Both matter, and the first is the one
     * that closes the door — see the sibling test below, which pins the fact that detaching alone
     * does not, so that nobody later assumes it does.
     */
    public function test_a_released_account_fails_closed_before_any_http(): void
    {
        $org = $this->tenantId();
        $connection = $this->connection($org);
        $account = $this->account($org, $connection);
        $credential = $this->credential($org, $connection, $account);

        $credential->delete();
        $account->connection_id = null;
        $account->save();

        $conversation = $this->conversation();
        $result = $this->service()->send($this->request($conversation, $this->actor()), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::NotConfigured, $result->failure);
        Http::assertNothingSent();
    }

    /**
     * Detaching an account closes the door even if its credential row survives.
     *
     * THE REGRESSION TEST FOR REVIEW FINDING 3. This previously asserted the OPPOSITE — that a
     * detached account could still send — because `DatabaseProviderCredentialResolver` validated
     * the credential's own `provider_connection_id` and never looked at the account's. Fail-closed
     * therefore rested entirely on `MetaPlatformBootstrap::release()` deleting the credential
     * first. It does; but a release path that ever forgot would have left a revoked account able
     * to speak, so the resolver now requires the attachment itself.
     */
    public function test_detaching_an_account_revokes_it_even_with_a_surviving_credential(): void
    {
        $org = $this->tenantId();
        $connection = $this->connection($org);
        $account = $this->account($org, $connection);
        $this->credential($org, $connection, $account);

        // Only half of release(): detach, but leave the credential row behind.
        $account->connection_id = null;
        $account->save();

        $result = $this->service()->send($this->request($this->conversation(), $this->actor()), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::NotConfigured, $result->failure);
        Http::assertNothingSent();
    }

    // ------------------------------------------------- C2. resolver invariant

    private function resolver(): \App\Modules\Crm\Services\Outbound\Contracts\ProviderCredentialResolver
    {
        return app(\App\Modules\Crm\Services\Outbound\Contracts\ProviderCredentialResolver::class);
    }

    public function test_the_resolver_returns_a_credential_when_account_and_credential_agree(): void
    {
        $org = $this->tenantId();
        $connection = $this->connection($org);
        $account = $this->account($org, $connection);
        $this->credential($org, $connection, $account);

        $credential = $this->resolver()->resolve($org, 'meta', 'page', self::PAGE_ID);

        $this->assertNotNull($credential);
        $this->assertSame(self::PAGE_ID, $credential->resourceExternalId);
    }

    public function test_the_resolver_returns_null_for_a_detached_account_with_a_surviving_credential(): void
    {
        $org = $this->tenantId();
        $connection = $this->connection($org);
        $account = $this->account($org, $connection);
        $this->credential($org, $connection, $account);

        $account->connection_id = null;
        $account->save();

        $this->assertNull($this->resolver()->resolve($org, 'meta', 'page', self::PAGE_ID));
    }

    public function test_the_resolver_returns_null_when_account_and_credential_name_different_grants(): void
    {
        $org = $this->tenantId();
        $first = $this->connection($org);
        $second = $this->connection($org, ProviderConnection::CONNECTED, '900000000000998');

        $account = $this->account($org, $first);
        // The credential belongs to the OTHER grant. Both connections are live, so nothing but the
        // mismatch itself can refuse this.
        $credential = $this->credential($org, $first, $account);
        $credential->provider_connection_id = $second->id;
        $credential->save();

        $this->assertNull($this->resolver()->resolve($org, 'meta', 'page', self::PAGE_ID));
    }

    // ---------------------------------------------------------------- D. Policy

    public function test_a_recent_inbound_turn_opens_the_window(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeAccepted();

        $result = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($result->accepted, (string) $result->reason);
        Http::assertSentCount(1);
    }

    public function test_a_thread_with_no_inbound_turn_is_refused_without_http(): void
    {
        $org = $this->tenantId();
        $connection = $this->connection($org);
        $account = $this->account($org, $connection);
        $this->credential($org, $connection, $account);

        $conversation = Conversation::query()->create([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 'thread_'.uniqid(),
            'external_contact_id' => 'contact_'.uniqid(),
            'status' => 'open',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        $result = $this->service()->send($this->request($conversation, $this->actor()), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::OutsideMessagingWindow, $result->failure);
        Http::assertNothingSent();
    }

    public function test_an_expired_window_is_refused_without_http_and_persists_nothing(): void
    {
        $org = $this->tenantId();
        $connection = $this->connection($org);
        $account = $this->account($org, $connection);
        $this->credential($org, $connection, $account);

        $conversation = $this->conversation('messenger', self::PAGE_ID, now()->subHours(25));

        $result = $this->service()->send($this->request($conversation, $this->actor()), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::OutsideMessagingWindow, $result->failure);
        Http::assertNothingSent();

        // A reply nobody was allowed to send must leave no trace in the thread.
        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->count());
    }

    /**
     * The exact boundary, asserted to the second.
     *
     * At `occurred_at + 24h` the window is CLOSED; one second earlier it is open. The asymmetry is
     * deliberate — see MetaMessagingWindow — because our clock and Meta's are not the same clock.
     */
    public function test_the_window_boundary_is_closed_at_exactly_the_limit(): void
    {
        $org = $this->tenantId();
        $connection = $this->connection($org);
        $account = $this->account($org, $connection);
        $this->credential($org, $connection, $account);

        $opened = Carbon::parse('2026-09-24 00:00:00');
        $conversation = $this->conversation('messenger', self::PAGE_ID, $opened->copy());
        $actor = $this->actor();

        // One second inside: allowed, and a request is made.
        Carbon::setTestNow($opened->copy()->addHours(24)->subSecond());
        $this->fakeAccepted();
        $inside = $this->service()->send($this->request($conversation, $actor, 'req-inside'), $org);
        $this->assertTrue($inside->accepted, (string) $inside->reason);
        Http::assertSentCount(1);

        // Exactly at the boundary: refused, and no second request is made.
        Carbon::setTestNow($opened->copy()->addHours(24));
        $atLimit = $this->service()->send($this->request($conversation, $actor, 'req-boundary'), $org);
        $this->assertTrue($atLimit->wasRefused());
        $this->assertSame(OutboundFailureCode::OutsideMessagingWindow, $atLimit->failure);
        Http::assertSentCount(1);

        Carbon::setTestNow();
    }

    // ---------------------------------------------------------------- E. Errors

    public static function providerErrors(): array
    {
        return [
            'malformed request (400/code 100)' => [400, ['code' => 100], OutboundFailureCode::InvalidRequest],
            'policy or permission (400/code 10)' => [400, ['code' => 10], OutboundFailureCode::PolicyViolation],
            'outside window subcode' => [400, ['code' => 10, 'subcode' => 2018278], OutboundFailureCode::OutsideMessagingWindow],
            'invalid token (400/code 190)' => [400, ['code' => 190], OutboundFailureCode::CredentialRejected],
            'unauthorized (401)' => [401, ['code' => 0], OutboundFailureCode::CredentialRejected],
            'forbidden (403)' => [403, ['code' => 0], OutboundFailureCode::CredentialRejected],
            'throttled (429)' => [429, ['code' => 4], OutboundFailureCode::ProviderRateLimited],
            'application throttle (400/code 613)' => [400, ['code' => 613], OutboundFailureCode::ProviderRateLimited],
        ];
    }

    /**
     * The 5xx family, which is NOT deterministic.
     *
     * A Graph 500 may be raised after the message was queued, and a 502/504 from an edge may mean
     * the request never reached the backend or that it was processed and the answer lost. None of
     * these establishes non-delivery, so none may be recorded as `failed`.
     */
    public static function providerAmbiguousErrors(): array
    {
        return [
            'graph fault (500)' => [500],
            'bad gateway (502)' => [502],
            'unavailable (503)' => [503],
        ];
    }

    #[DataProvider('providerErrors')]
    public function test_graph_errors_map_onto_our_own_vocabulary(int $status, array $error, OutboundFailureCode $expected): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeError($status, $error);

        $result = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame($expected, $result->failure);
        Http::assertSentCount(1);

        $row = ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->firstOrFail();

        // A deterministic rejection is a real failure; only an ambiguous one stays pending.
        $this->assertSame(OutboundMessagePersister::FAILED, $row->delivery_status);
        $this->assertSame($expected->value, $row->failure_code);
        $this->assertFalse($expected->leavesDeliveryUnresolved());
    }

    /**
     * REVIEW FINDING 1. A 5xx leaves the row unresolved, and a replay never posts again.
     *
     * This previously asserted `FAILED`, which is what made the row replayable: neither the SENT
     * check nor the unresolved check matched it, so a second attempt with the same client request
     * id fell straight through to the transport and could deliver the message twice.
     */
    #[DataProvider('providerAmbiguousErrors')]
    public function test_a_five_hundred_class_response_is_unresolved_and_never_replayed(int $status): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();

        $calls = 0;
        // ONE stub, mutated by reference: Http::fake() merges, and an arrow function would capture
        // the counter by value.
        Http::fake(['*' => function () use (&$calls, $status) {
            $calls++;

            return Http::response(['error' => ['code' => 1]], $status);
        }]);

        $first = $this->service()->send($this->request($conversation, $actor, 'req-5xx'), $org);

        $this->assertTrue($first->wasRefused());
        $this->assertSame(OutboundFailureCode::ProviderUnavailable, $first->failure);
        $this->assertSame(1, $calls);

        $row = ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->firstOrFail();

        $this->assertSame(OutboundMessagePersister::PENDING, $row->delivery_status);
        $this->assertSame(OutboundFailureCode::ProviderUnavailable->value, $row->failure_code);
        $this->assertNull($row->delivered_at);
        $this->assertNull($row->external_message_id);

        // The replay refuses BEFORE the transport, and names the original reason.
        $second = $this->service()->send($this->request($conversation, $actor, 'req-5xx'), $org);

        $this->assertTrue($second->wasRefused());
        $this->assertSame(OutboundFailureCode::ProviderUnavailable, $second->failure);
        $this->assertStringContainsString('provider_unavailable', (string) $second->reason);
        $this->assertSame(1, $calls, 'A 5xx must never produce a second provider POST.');

        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->count());
    }

    /** Which outcomes may be replayed at all, stated once and asserted. */
    public function test_ambiguous_outcomes_are_never_advertised_as_retryable(): void
    {
        foreach ([OutboundFailureCode::ProviderUnavailable, OutboundFailureCode::DeliveryUnknown] as $code) {
            $this->assertTrue($code->leavesDeliveryUnresolved(), $code->value);
            $this->assertFalse($code->isRetryable(), $code->value.' must not invite an automatic retry.');
        }

        // The one case where non-delivery IS established: the throttle rejects before processing.
        $this->assertTrue(OutboundFailureCode::ProviderRateLimited->isRetryable());
        $this->assertFalse(OutboundFailureCode::ProviderRateLimited->leavesDeliveryUnresolved());

        foreach ([OutboundFailureCode::InvalidRequest, OutboundFailureCode::PolicyViolation,
            OutboundFailureCode::CredentialRejected, OutboundFailureCode::OutsideMessagingWindow] as $code) {
            $this->assertFalse($code->leavesDeliveryUnresolved(), $code->value);
        }
    }

    /** The persister refuses to park a resolved outcome in the unresolved state. */
    public function test_the_persister_refuses_to_park_a_resolved_outcome_as_unresolved(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeAccepted();
        $this->service()->send($this->request($conversation, $actor, 'req-park'), $org);

        $row = ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->firstOrFail();

        $this->expectException(\App\Modules\Crm\Services\Outbound\OutboundPersistenceException::class);
        app(OutboundMessagePersister::class)->markUnresolved($row, OutboundFailureCode::PolicyViolation);
    }

    public function test_a_connection_timeout_is_never_retried_and_never_marked_failed(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();

        $attempts = $this->fakeTimeout();

        $result = $this->service()->send($this->request($conversation, $actor, 'req-timeout'), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::DeliveryUnknown, $result->failure);

        // EXACTLY ONE POST. The whole point: no automatic second attempt.
        $this->assertSame(1, $attempts());

        $row = ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->firstOrFail();

        $this->assertSame(OutboundMessagePersister::PENDING, $row->delivery_status);
        $this->assertSame(OutboundFailureCode::DeliveryUnknown->value, $row->failure_code);
        $this->assertNull($row->delivered_at);
        $this->assertNull($row->external_message_id);
    }

    public function test_a_success_without_a_message_id_is_treated_as_unknown_not_sent(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        Http::fake(['*' => Http::response(['recipient_id' => 'x'], 200)]);

        $result = $this->service()->send($this->request($conversation, $actor, 'req-noid'), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::DeliveryUnknown, $result->failure);

        $row = ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->firstOrFail();

        $this->assertSame(OutboundMessagePersister::PENDING, $row->delivery_status);
        $this->assertNull($row->external_message_id);
    }

    // ----------------------------------------------------------- F. Idempotency

    public function test_one_accepted_send_performs_exactly_one_post(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeAccepted();

        $this->service()->send($this->request($conversation, $actor, 'req-once'), $org);

        Http::assertSentCount(1);
        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->count());
    }

    public function test_replaying_a_sent_key_performs_no_further_post(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeAccepted('mid_synthetic_first');

        $first = $this->service()->send($this->request($conversation, $actor, 'req-replay'), $org);
        $second = $this->service()->send($this->request($conversation, $actor, 'req-replay'), $org);

        $this->assertTrue($first->accepted);
        $this->assertTrue($second->accepted);
        $this->assertSame('mid_synthetic_first', $second->providerMessageId);

        Http::assertSentCount(1);
        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->count());
    }

    /**
     * The case this phase exists to get right.
     *
     * A timed-out POST leaves delivery unknown. Replaying the same client request id must NOT post
     * again — the message may already be sitting in the customer's inbox.
     */
    public function test_replaying_an_unresolved_key_performs_no_further_post(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();

        $attempts = $this->fakeTimeout();

        $first = $this->service()->send($this->request($conversation, $actor, 'req-unresolved'), $org);
        $second = $this->service()->send($this->request($conversation, $actor, 'req-unresolved'), $org);

        $this->assertSame(OutboundFailureCode::DeliveryUnknown, $first->failure);
        $this->assertSame(OutboundFailureCode::DeliveryUnknown, $second->failure);

        // The replay never reached the transport at all.
        $this->assertSame(1, $attempts());

        $row = ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->firstOrFail();

        $this->assertSame(OutboundMessagePersister::PENDING, $row->delivery_status);
        $this->assertSame(OutboundFailureCode::DeliveryUnknown->value, $row->failure_code);
        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->count());
    }

    public function test_an_unkeyed_attempt_is_not_deduplicated(): void
    {
        [$org, $conversation, $actor] = $this->messengerTenant();
        $this->fakeAccepted();

        $this->service()->send($this->request($conversation, $actor), $org);
        $this->service()->send($this->request($conversation, $actor), $org);

        // No client request id means no identity; two deliberate sends are two messages.
        Http::assertSentCount(2);
        $this->assertSame(2, ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->count());
    }

    // ------------------------------------------------------------------ G. Flag

    public function test_the_disabled_flag_refuses_before_any_http_or_persistence(): void
    {
        config(['meta.outbound.enabled' => false]);
        [$org, $conversation, $actor] = $this->messengerTenant();

        $result = $this->service()->send($this->request($conversation, $actor, 'req-off'), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::NotConfigured, $result->failure);
        $this->assertStringContainsString('has no transport', (string) $result->reason);

        Http::assertNothingSent();
        $this->assertSame(0, ConversationMessage::query()->withoutGlobalScopes()
            ->where('direction', 'outbound')->count());
    }

    public function test_the_flag_ships_disabled_in_the_repository_default(): void
    {
        // Read the shipped config rather than the test-overridden runtime value.
        $shipped = require base_path('config/meta.php');

        $this->assertFalse($shipped['outbound']['enabled'], 'meta.outbound.enabled must ship false.');
    }

    public function test_an_unconfigured_graph_host_refuses_even_with_the_flag_on(): void
    {
        config(['meta.oauth.graph_host' => '']);
        [$org, $conversation, $actor] = $this->messengerTenant();

        $result = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::NotConfigured, $result->failure);
        Http::assertNothingSent();
    }
}
