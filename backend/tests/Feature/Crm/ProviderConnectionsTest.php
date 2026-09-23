<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Models\ProviderConnectionCredential;
use App\Modules\Crm\Services\Inbound\ConversationResolver;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use App\Modules\Crm\Services\Outbound\Contracts\ProviderCredentialResolver;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use App\Modules\Crm\Services\Outbound\OutboundIdempotency;
use App\Modules\Crm\Services\Outbound\OutboundMessagePersister;
use App\Modules\Crm\Services\Outbound\OutboundMessageRequest;
use App\Modules\Crm\Services\Outbound\OutboundMessageService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The SaaS connection model, tested for isolation above all else.
 *
 * The premise of every test here is that this CRM will hold more than one customer's Facebook
 * Pages at once. Almost nothing in this file checks that a feature works; they check that one
 * tenant's assets cannot be reached, borrowed or spoken through by another — and that a Page an
 * operator disconnected stops being usable without taking the customer's history with it.
 *
 * Synthetic secrets and synthetic content only. No real token and no production message text
 * appears anywhere in this file.
 */
class ProviderConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private const SYNTHETIC_TOKEN = 'SYNTHETIC_TOKEN_NOT_A_REAL_SECRET_4417';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
    }

    private function pinnedTenant(): int
    {
        $org = $this->tenant();
        CurrentOrg::set($org->id);

        return (int) $org->id;
    }

    private function otherOrg(string $name): int
    {
        return (int) Org::create(['name' => $name, 'is_active' => true])->id;
    }

    private function connection(int $orgId, string $status = ProviderConnection::CONNECTED, ?string $business = null): ProviderConnection
    {
        $connection = new ProviderConnection([
            'provider' => 'meta',
            'status' => $status,
            'external_business_id' => $business ?? 'biz_'.uniqid(),
            'connected_at' => now(),
        ]);
        $connection->org_id = $orgId;
        $connection->save();

        return $connection;
    }

    private function account(
        int $orgId,
        string $externalId,
        string $resourceType = 'page',
        ?ProviderConnection $connection = null,
    ): ProviderAccount {
        $account = new ProviderAccount([
            'connection_id' => $connection?->id,
            'provider' => 'meta',
            'resource_type' => $resourceType,
            'external_id' => $externalId,
            'label' => 'Synthetic '.$resourceType,
            'is_active' => true,
        ]);
        $account->org_id = $orgId;
        $account->save();

        return $account;
    }

    private function credential(ProviderConnection $connection, ProviderAccount $account): ProviderConnectionCredential
    {
        $credential = new ProviderConnectionCredential([
            'provider_connection_id' => $connection->id,
            'provider_account_id' => $account->id,
            'credential_type' => ProviderConnectionCredential::PAGE_ACCESS_TOKEN,
            'access_token' => self::SYNTHETIC_TOKEN,
            'expires_at' => now()->addDays(30),
            'scopes' => ['pages_messaging'],
        ]);
        $credential->org_id = (int) $connection->org_id;
        $credential->save();

        return $credential;
    }

    /** A thread whose newest inbound turn names `$recipient`, created in the pinned org. */
    private function conversation(string $channel, string $recipient, ?int $accountId = null): Conversation
    {
        $conversation = Conversation::query()->create([
            'provider' => 'meta',
            'channel' => $channel,
            'provider_account_id' => $accountId,
            'external_conversation_id' => 'thread_'.uniqid(),
            'external_contact_id' => 'contact_'.uniqid(),
            'status' => 'open',
            'started_at' => now()->subMinutes(5),
            'last_message_at' => now(),
        ]);

        $message = new ConversationMessage([
            'provider' => 'meta',
            'channel' => $channel,
            'external_message_id' => 'mid_'.uniqid(),
            'direction' => 'inbound',
            'sender_external_id' => 'psid_'.uniqid(),
            'recipient_external_id' => $recipient,
            'message_type' => 'text',
            'body' => 'Synthetic inbound turn',
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
        $message->conversation_id = $conversation->id;
        $message->org_id = $conversation->org_id;
        $message->idempotency_key = hash('sha256', $conversation->id.uniqid());
        $message->save();

        return $conversation->refresh();
    }

    private function sender(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate(OutboundMessageService::PERMISSION, 'web'));

        return $user;
    }

    private function request(Conversation $c, User $actor, ?string $clientRequestId = null, string $body = 'Synthetic reply'): OutboundMessageRequest
    {
        return new OutboundMessageRequest($c, $actor, $body, $clientRequestId);
    }

    /** 1 — a connection belongs to exactly one org and is invisible to the other. */
    public function test_one_orgs_connection_cannot_be_used_by_another(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = $this->otherOrg('Connection Tenant B');

        $connectionB = $this->connection($orgB);

        $visibleToA = ProviderConnection::query()
            ->where('org_id', $orgA)
            ->where('id', $connectionB->id)
            ->exists();

        $this->assertFalse($visibleToA, "Org A must not be able to read org B's connection.");
        $this->assertSame($orgB, (int) DB::table('crm_provider_connections')->where('id', $connectionB->id)->value('org_id'));
    }

    /** 2 — a resource cannot resolve through a connection owned by another org. */
    public function test_a_resource_cannot_borrow_another_orgs_connection(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = $this->otherOrg('Connection Tenant C');

        $connectionB = $this->connection($orgB);
        // Deliberately mis-provisioned: org A's Page pointed at org B's grant.
        $accountA = $this->account($orgA, 'page_a', 'page', $connectionB);
        $this->credential($connectionB, $accountA);

        $resolved = app(ProviderCredentialResolver::class)
            ->resolve($orgA, 'meta', 'page', 'page_a');

        $this->assertNull($resolved, 'A credential whose connection belongs to another org must not resolve.');
    }

    /** 3 — a conversation's recorded resource must belong to the conversation's org. */
    public function test_a_conversation_resource_from_another_org_is_refused(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = $this->otherOrg('Connection Tenant D');

        $accountB = CurrentOrg::runAs($orgB, fn () => $this->account($orgB, 'page_of_b'));
        CurrentOrg::set($orgA);

        $conversation = $this->conversation('messenger', 'page_of_b', (int) $accountB->id);

        $result = app(OutboundMessageService::class)->send($this->request($conversation, $this->sender()), $orgA);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::ResourceUnmapped, $result->failure);
    }

    /** 4 — two Pages under one org stay distinguishable, and the thread picks its own. */
    public function test_two_pages_under_one_org_remain_distinguishable(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $pageOne = $this->account($org, 'page_one', 'page', $connection);
        $pageTwo = $this->account($org, 'page_two', 'page', $connection);

        $this->assertNotSame($pageOne->id, $pageTwo->id);

        $threadOnTwo = $this->conversation('messenger', 'page_two', (int) $pageTwo->id);
        $this->credential($connection, $pageTwo);

        $resolved = app(ProviderCredentialResolver::class)->resolve($org, 'meta', 'page', 'page_two');
        $this->assertNotNull($resolved);
        $this->assertSame('page_two', $resolved->resourceExternalId);

        // The other Page has no credential of its own, so it must not resolve.
        $this->assertNull(app(ProviderCredentialResolver::class)->resolve($org, 'meta', 'page', 'page_one'));
        $this->assertSame((int) $pageTwo->id, (int) $threadOnTwo->provider_account_id);
    }

    /** 5 — the same holds for two Instagram accounts. */
    public function test_two_instagram_accounts_under_one_org_remain_distinguishable(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $igOne = $this->account($org, 'ig_one', 'instagram_business_account', $connection);
        $igTwo = $this->account($org, 'ig_two', 'instagram_business_account', $connection);
        $this->credential($connection, $igTwo);

        $this->assertNull(app(ProviderCredentialResolver::class)->resolve($org, 'meta', 'instagram_business_account', 'ig_one'));
        $resolved = app(ProviderCredentialResolver::class)->resolve($org, 'meta', 'instagram_business_account', 'ig_two');
        $this->assertNotNull($resolved);
        $this->assertSame('ig_two', $resolved->resourceExternalId);
        $this->assertNotSame($igOne->id, $igTwo->id);
    }

    /** 6 — with two Pages connected, the send uses the one the thread recorded, not a guess. */
    public function test_outbound_resolves_the_conversations_recorded_resource(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $pageOne = $this->account($org, 'page_alpha', 'page', $connection);
        $pageTwo = $this->account($org, 'page_beta', 'page', $connection);
        $this->credential($connection, $pageTwo);

        $conversation = $this->conversation('messenger', 'page_beta', (int) $pageTwo->id);

        $result = app(OutboundMessageService::class)->send($this->request($conversation, $this->sender()), $org);

        // It got all the way past resource and credential resolution — the refusal is the missing
        // transport, not an ambiguity. With Phase 3F.1's inference alone this would have been
        // ResourceAmbiguous, which is the whole reason the column exists.
        $this->assertSame(OutboundFailureCode::NotConfigured, $result->failure);
        $this->assertStringContainsString('has no transport', (string) $result->reason);
        $this->assertNotSame($pageOne->id, $pageTwo->id);
    }

    /** 7 — the inbound path, not a request field, is what records the resource. */
    public function test_inbound_records_the_resource_and_no_request_field_can(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $page = $this->account($org, 'page_recorded', 'page', $connection);

        $resolver = app(ConversationResolver::class);
        $message = new InboundConversationMessage(
            provider: 'meta',
            channel: 'messenger',
            externalConversationId: 'fbpage:page_recorded:from:psid_9',
            externalMessageId: 'mid_recorded',
            senderExternalId: 'psid_9',
            recipientExternalId: 'page_recorded',
            text: 'Synthetic inbound',
        );

        $conversation = $resolver->resolve($org, $message, now());

        $this->assertSame((int) $page->id, (int) $conversation->provider_account_id);

        // The outbound request type has no way to name a resource.
        $names = array_map(
            static fn ($p) => $p->getName(),
            (new \ReflectionClass(OutboundMessageRequest::class))->getProperties(),
        );
        foreach (['providerAccountId', 'provider_account_id', 'pageId', 'resourceExternalId'] as $forbidden) {
            $this->assertNotContains($forbidden, $names);
        }
    }

    /** 8 — disconnecting blocks new sends and destroys nothing. */
    public function test_a_disconnected_connection_blocks_outbound_but_keeps_history(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $page = $this->account($org, 'page_live', 'page', $connection);
        $this->credential($connection, $page);
        $conversation = $this->conversation('messenger', 'page_live', (int) $page->id);

        $connection->disconnect();

        $result = app(OutboundMessageService::class)->send($this->request($conversation, $this->sender()), $org);
        $this->assertSame(OutboundFailureCode::ResourceUnavailable, $result->failure);
        $this->assertNull(app(ProviderCredentialResolver::class)->resolve($org, 'meta', 'page', 'page_live'));

        // Nothing was deleted: the mapping, the thread and its message all survive.
        $this->assertDatabaseHas('crm_provider_accounts', ['id' => $page->id]);
        $this->assertDatabaseHas('crm_conversations', ['id' => $conversation->id]);
        $this->assertSame(1, $conversation->messages()->withoutGlobalScopes()->count());
        $this->assertSame(ProviderConnection::DISCONNECTED, $connection->fresh()->status);
    }

    /** 9 — a credential attached to a different connection does not resolve. */
    public function test_a_credential_from_another_connection_does_not_resolve(): void
    {
        $org = $this->pinnedTenant();
        $live = $this->connection($org);
        $stale = $this->connection($org, ProviderConnection::DISCONNECTED);

        $page = $this->account($org, 'page_shared', 'page', $live);
        // The credential row points at the disconnected grant.
        $credential = $this->credential($stale, $page);

        $this->assertNull(app(ProviderCredentialResolver::class)->resolve($org, 'meta', 'page', 'page_shared'));
        $this->assertSame((int) $stale->id, (int) $credential->provider_connection_id);
    }

    /** 10 — an encrypted token is ciphertext at rest, hidden from serialization, and never logged. */
    public function test_the_token_is_encrypted_hidden_and_unlogged(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $page = $this->account($org, 'page_secret', 'page', $connection);
        $credential = $this->credential($connection, $page);

        $raw = (string) DB::table('crm_provider_credentials')->where('id', $credential->id)->value('access_token');
        $this->assertNotSame(self::SYNTHETIC_TOKEN, $raw, 'The column must hold ciphertext, not the token.');
        $this->assertStringNotContainsString(self::SYNTHETIC_TOKEN, $raw);
        $this->assertSame(self::SYNTHETIC_TOKEN, $credential->fresh()->access_token, 'and plaintext when read through the cast.');

        $this->assertStringNotContainsString(self::SYNTHETIC_TOKEN, (string) json_encode($credential->toArray()));
        $this->assertArrayNotHasKey('access_token', $credential->toArray());

        $captured = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$captured): void {
            $captured[] = $e->message.' '.json_encode($e->context);
        });

        $conversation = $this->conversation('messenger', 'page_secret', (int) $page->id);
        app(OutboundMessageService::class)->send($this->request($conversation, $this->sender()), $org);

        foreach ($captured as $line) {
            $this->assertStringNotContainsString(self::SYNTHETIC_TOKEN, $line);
        }
    }

    /** 11 — a replayed client request id reuses the same outbound row. */
    public function test_replaying_a_request_id_does_not_duplicate_the_outbound_row(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $page = $this->account($org, 'page_idem', 'page', $connection);
        $conversation = $this->conversation('messenger', 'page_idem', (int) $page->id);
        $actor = $this->sender();

        $persister = app(OutboundMessagePersister::class);
        $idem = app(OutboundIdempotency::class);

        $request = $this->request($conversation, $actor, 'client-req-1');
        $key = $idem->keyFor($request);

        $first = $persister->pending($request, $key);
        $second = $persister->pending($this->request($conversation, $actor, 'client-req-1'), $key);

        $this->assertSame((int) $first->id, (int) $second->id, 'A replay must find the first attempt.');
        $this->assertSame(OutboundMessagePersister::PENDING, $first->delivery_status);
        $this->assertSame(1, ConversationMessage::query()->withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
    }

    /** 12 — identical text under two request ids is two distinct attempts. */
    public function test_identical_text_with_different_request_ids_creates_two_attempts(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $page = $this->account($org, 'page_twice', 'page', $connection);
        $conversation = $this->conversation('messenger', 'page_twice', (int) $page->id);
        $actor = $this->sender();

        $persister = app(OutboundMessagePersister::class);
        $idem = app(OutboundIdempotency::class);

        $a = $this->request($conversation, $actor, 'req-a', 'ok');
        $b = $this->request($conversation, $actor, 'req-b', 'ok');

        $first = $persister->pending($a, $idem->keyFor($a));
        $second = $persister->pending($b, $idem->keyFor($b));

        $this->assertNotSame((int) $first->id, (int) $second->id, 'Saying "ok" twice is two messages.');
        $this->assertSame(2, ConversationMessage::query()->withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
    }

    /** 12b — and a pending row is never reported as delivered. */
    public function test_a_pending_row_is_not_delivered(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $page = $this->account($org, 'page_pending', 'page', $connection);
        $conversation = $this->conversation('messenger', 'page_pending', (int) $page->id);
        $actor = $this->sender();

        $request = $this->request($conversation, $actor, 'req-pending');
        $row = app(OutboundMessagePersister::class)->pending($request, app(OutboundIdempotency::class)->keyFor($request));

        $this->assertSame(OutboundMessagePersister::PENDING, $row->delivery_status);
        $this->assertNull($row->external_message_id);
        $this->assertNull($row->delivered_at);
    }

    /** 13 — inbound rows keep their meaning: no delivery state, and the engine still ingests. */
    public function test_inbound_rows_are_unaffected_by_the_delivery_columns(): void
    {
        $org = $this->pinnedTenant();
        $connection = $this->connection($org);
        $page = $this->account($org, 'page_inbound', 'page', $connection);
        $conversation = $this->conversation('messenger', 'page_inbound', (int) $page->id);

        $inbound = $conversation->messages()->withoutGlobalScopes()->where('direction', 'inbound')->firstOrFail();

        $this->assertNull($inbound->delivery_status, 'An arriving message has no delivery state; it already arrived.');
        $this->assertNull($inbound->failure_code);
        $this->assertNull($inbound->delivered_at);
    }

    /** 14 — the whole outbound tree still contains no transport. */
    public function test_no_provider_transport_exists_anywhere(): void
    {
        $offenders = [];

        foreach ([app_path('Modules/Crm/Services/Outbound'), app_path('Modules/Crm/Models')] as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                foreach (['graph.facebook.com', 'curl_init', 'GuzzleHttp\\Client', 'Http::post', 'openrouter', 'oauth/access_token'] as $needle) {
                    if (stripos($source, $needle) !== false) {
                        $offenders[] = $file->getFilename().' => '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
