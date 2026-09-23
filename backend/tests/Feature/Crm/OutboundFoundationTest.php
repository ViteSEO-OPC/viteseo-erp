<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Services\Outbound\Contracts\ProviderCredentialResolver;
use App\Modules\Crm\Services\Outbound\Credentials\NullProviderCredentialResolver;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use App\Modules\Crm\Services\Outbound\OutboundIdempotency;
use App\Modules\Crm\Services\Outbound\OutboundMessageRequest;
use App\Modules\Crm\Services\Outbound\OutboundMessageService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\Fixtures\Outbound\FixtureOutboundAdapter;
use Tests\TestCase;

/**
 * The outbound foundation, tested for what it REFUSES.
 *
 * Phase 3F.1 ships no transport, so most of these assert that a send stops at a named boundary
 * rather than that a message went anywhere. That is the property worth protecting: every one of
 * these refusals is a place where a bug would mean a real customer receiving a message from the
 * wrong business, or from a business that never authorised the sender.
 *
 * Synthetic content only. No production message text appears anywhere in this file.
 */
class OutboundFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const CANARY = 'OUTBOUND_BODY_CANARY_8813';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        FixtureOutboundAdapter::reset();
        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * The base TestCase already provisions a tenant with entitlements and a subscription; this
     * only pins it and hands back the id, because every assertion below is about org boundaries
     * and wants the number rather than the model.
     */
    private function pinnedTenant(): int
    {
        $org = $this->tenant();
        CurrentOrg::set($org->id);

        return (int) $org->id;
    }

    private function sender(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate(OutboundMessageService::PERMISSION, 'web'));

        return $user;
    }

    /** A conversation whose newest inbound turn names the Page it arrived on. */
    private function conversation(string $channel = 'messenger', ?string $recipient = 'page_owned'): Conversation
    {
        $conversation = Conversation::query()->create([
            'provider' => 'meta',
            'channel' => $channel,
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

    private function account(int $orgId, string $resourceType = 'page', string $externalId = 'page_owned'): ProviderAccount
    {
        $account = new ProviderAccount([
            'provider' => 'meta',
            'resource_type' => $resourceType,
            'external_id' => $externalId,
            'label' => 'Synthetic Page',
            'is_active' => true,
        ]);
        $account->org_id = $orgId;
        $account->save();

        return $account;
    }

    private function service(): OutboundMessageService
    {
        return app(OutboundMessageService::class);
    }

    private function request(Conversation $c, User $actor, ?string $clientRequestId = null, string $body = 'Synthetic reply'): OutboundMessageRequest
    {
        return new OutboundMessageRequest(
            conversation: $c,
            actor: $actor,
            body: $body,
            clientRequestId: $clientRequestId,
        );
    }

    /** 1 — a user without the reply permission is refused before anything is looked up. */
    public function test_sending_requires_the_reply_permission(): void
    {
        $org = $this->pinnedTenant();
        $this->account($org);
        $conversation = $this->conversation();

        $result = $this->service()->send($this->request($conversation, User::factory()->create()), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::Unauthorized, $result->failure);
    }

    /** 2 — a conversation belonging to another org cannot be replied to. */
    public function test_a_conversation_from_another_org_is_refused(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = Org::create(['name' => 'Second Outbound Tenant', 'is_active' => true]);

        $foreign = CurrentOrg::runAs($orgB->id, function () use ($orgB) {
            $this->account((int) $orgB->id, 'page', 'page_of_org_b');

            return $this->conversation('messenger', 'page_of_org_b');
        });

        CurrentOrg::set($orgA);
        $result = $this->service()->send($this->request($foreign, $this->sender()), $orgA);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::TenantMismatch, $result->failure);
    }

    /** 3 — resource resolution never reaches another org's connected account. */
    public function test_resource_resolution_will_not_borrow_another_orgs_page(): void
    {
        $orgA = $this->pinnedTenant();
        $orgB = Org::create(['name' => 'Third Outbound Tenant', 'is_active' => true]);

        // Org B owns the Page. Org A owns nothing, and its thread names that same Page id.
        $this->account((int) $orgB->id, 'page', 'page_belonging_to_b');
        $conversation = $this->conversation('messenger', 'page_belonging_to_b');

        $result = $this->service()->send($this->request($conversation, $this->sender()), $orgA);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::ResourceUnmapped, $result->failure);
    }

    /** 4 — the request type has no org field a caller could set. */
    public function test_the_request_exposes_no_settable_org_or_sender(): void
    {
        $reflection = new \ReflectionClass(OutboundMessageRequest::class);
        $names = array_map(static fn ($p) => $p->getName(), $reflection->getProperties());

        foreach (['orgId', 'org_id', 'tenantId', 'tenant_id', 'pageId', 'page_id', 'accessToken', 'senderId'] as $forbidden) {
            $this->assertNotContains($forbidden, $names, "OutboundMessageRequest must not expose [$forbidden].");
        }

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), "[{$property->getName()}] must be readonly.");
        }
    }

    /** 5 — an org owning several Pages, with nothing naming one, is refused rather than guessed. */
    public function test_an_ambiguous_resource_is_refused_not_guessed(): void
    {
        $org = $this->pinnedTenant();
        $this->account($org, 'page', 'page_one');
        $this->account($org, 'page', 'page_two');

        // No recipient on the inbound turn, so route 1 cannot decide.
        $conversation = $this->conversation('messenger', null);

        $result = $this->service()->send($this->request($conversation, $this->sender()), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::ResourceAmbiguous, $result->failure);
    }

    /** 6 — with everything else in place, a missing credential fails closed. */
    public function test_a_missing_credential_fails_closed(): void
    {
        $org = $this->pinnedTenant();
        $this->account($org);
        $conversation = $this->conversation();

        $result = $this->service()->send($this->request($conversation, $this->sender()), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::NotConfigured, $result->failure);
        $this->assertInstanceOf(NullProviderCredentialResolver::class, app(ProviderCredentialResolver::class));
    }

    /** 7 — an unsupported provider/channel pair fails closed. */
    public function test_an_unsupported_channel_fails_closed(): void
    {
        $org = $this->pinnedTenant();
        $this->account($org);
        $conversation = $this->conversation();
        $conversation->channel = 'carrier_pigeon';
        $conversation->save();

        $result = $this->service()->send($this->request($conversation, $this->sender()), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::UnsupportedChannel, $result->failure);
    }

    /** 8 — even when a credential IS available, the real Meta adapters make no network call. */
    public function test_the_meta_adapters_refuse_rather_than_call_meta(): void
    {
        $org = $this->pinnedTenant();
        $this->account($org);
        $conversation = $this->conversation();

        // Hand the service a credential so the run reaches the adapter's send().
        app()->bind(ProviderCredentialResolver::class, fn () => new class implements ProviderCredentialResolver
        {
            public function resolve(int $orgId, string $provider, string $resourceType, string $resourceExternalId): ?ProviderCredential
            {
                return new ProviderCredential('synthetic-token-not-real', $resourceExternalId);
            }
        });

        $result = $this->service()->send($this->request($conversation, $this->sender()), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::NotConfigured, $result->failure);
        Http::assertNothingSent();
    }

    /** 8b — and the outbound source tree contains no transport at all. */
    public function test_the_outbound_tree_contains_no_http_client(): void
    {
        $root = app_path('Modules/Crm/Services/Outbound');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $offenders = [];

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            foreach (['graph.facebook.com', 'curl_init', 'GuzzleHttp\\Client', 'Http::post', 'Http::get', 'file_get_contents(\'http'] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = $file->getFilename().' => '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders, 'Phase 3F.1 must contain no outbound transport.');
    }

    /** 9 — no outbound message row is written, correctly or otherwise. */
    public function test_no_outbound_row_is_persisted(): void
    {
        $org = $this->pinnedTenant();
        $this->account($org);
        $conversation = $this->conversation();

        $before = ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->count();
        $this->service()->send($this->request($conversation, $this->sender()), $org);
        $after = ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->count();

        $this->assertSame(0, $before);
        $this->assertSame(0, $after, 'Phase 3F.1 persists nothing; nothing can be marked delivered.');
    }

    /** 10 — the idempotency key is deterministic, scoped, and absent without a client id. */
    public function test_outbound_idempotency_is_client_owned_and_scoped(): void
    {
        $org = $this->pinnedTenant();
        $conversation = $this->conversation();
        $other = $this->conversation();
        $actor = $this->sender();
        $idem = new OutboundIdempotency;

        $a = $idem->keyFor($this->request($conversation, $actor, 'req-1'));
        $b = $idem->keyFor($this->request($conversation, $actor, 'req-1'));
        $this->assertNotNull($a);
        $this->assertSame($a, $b, 'The same client request id must derive the same key.');
        $this->assertSame(OutboundIdempotency::MAX_LENGTH, strlen($a));

        $this->assertNotSame($a, $idem->keyFor($this->request($conversation, $actor, 'req-2')));
        $this->assertNotSame($a, $idem->keyFor($this->request($other, $actor, 'req-1')),
            'The same client id on another conversation is a different send.');

        // Identical text is NOT deduplicated: saying "ok" twice is two messages.
        $this->assertNull($idem->keyFor($this->request($conversation, $actor, null, 'ok')));
        $this->assertNull($idem->keyFor($this->request($conversation, $actor, null, 'ok')));
    }

    /** 11 — the message body never reaches the log. */
    public function test_the_message_body_is_never_logged(): void
    {
        $org = $this->pinnedTenant();
        $this->account($org);
        $conversation = $this->conversation();

        // Listening to the framework's own MessageLogged event rather than Log::listen(), which
        // only fires for the default driver stack and stayed silent here.
        $captured = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$captured): void {
            $captured[] = $event->message.' '.json_encode($event->context);
        });

        $this->service()->send($this->request($conversation, $this->sender(), 'req-canary', self::CANARY), $org);

        $this->assertNotEmpty($captured, 'The refusal should have been logged at all.');
        foreach ($captured as $line) {
            $this->assertStringNotContainsString(self::CANARY, $line);
        }
    }

    /** 12 — the test fixture adapter is not registered in production config. */
    public function test_the_fixture_adapter_is_not_in_production_config(): void
    {
        $this->assertNotContains(FixtureOutboundAdapter::class, config('outbound.adapters'));
    }

    /** 13 — a credential does not render its secret when dumped or serialized. */
    public function test_a_credential_does_not_leak_when_dumped(): void
    {
        $credential = new ProviderCredential('super-secret-token-value', 'page_owned');

        $this->assertStringNotContainsString('super-secret-token-value', print_r($credential->__debugInfo(), true));
        $this->assertFalse(method_exists($credential, '__toString'), 'A credential must not be interpolable into a string.');
        $this->assertSame('super-secret-token-value', $credential->reveal());
    }
}
