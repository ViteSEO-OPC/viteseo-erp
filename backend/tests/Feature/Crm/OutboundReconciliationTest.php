<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Models\ProviderConnectionCredential;
use App\Modules\Crm\Services\Outbound\OutboundDeliveryResolver;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use App\Modules\Crm\Services\Outbound\OutboundIdempotency;
use App\Modules\Crm\Services\Outbound\OutboundMessagePersister;
use App\Modules\Crm\Services\Outbound\OutboundMessageRequest;
use App\Modules\Crm\Services\Outbound\OutboundMessageResult;
use App\Modules\Crm\Services\Outbound\OutboundMessageService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 3H.5A — the send claim, stranded-send detection, the durable unresolved summary, and the
 * operator's manual resolution.
 *
 * THE PROPERTY UNDER TEST IS "AT MOST ONE PROVIDER CALL PER CLIENT REQUEST ID UNLESS NON-DELIVERY IS
 * ESTABLISHED". Every send-path test counts provider calls INSIDE the fake, never with
 * `Http::assertSentCount()` alone: Laravel records a request only once it completes, so a throwing
 * stub would let "exactly one call" pass against zero.
 *
 * NO REAL HTTP. `Http::preventStrayRequests()` runs before every test; every identifier is synthetic.
 *
 * CONCURRENCY, HONESTLY. SQLite cannot reproduce two MariaDB connections interleaving. What these
 * tests prove is (a) the claim is a one-way state transition — a claimed row can never be claimed
 * again — and (b) a second same-key attempt made WHILE the first is inside the provider call, driven
 * re-entrantly from the HTTP fake, cannot reach the provider. InnoDB's per-row atomicity of a single
 * conditional UPDATE is what the design relies on in production; it is not provable here.
 */
class OutboundReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'SYNTHETIC-PAGE-TOKEN-NOT-REAL-000';

    private const PAGE_ID = '900000000000001';

    private const HOST = 'https://graph.test.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'meta.oauth.graph_host' => self::HOST,
            'meta.oauth.graph_version' => 'v26.0',
            'meta.app_secret' => 'SYNTHETIC-APP-SECRET-NOT-REAL-000',
            'meta.outbound.enabled' => true,
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

    /** A wired Messenger tenant: connection, account, credential, and a thread inside the window. */
    private function wired(): array
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
            'resource_type' => 'page',
            'external_id' => self::PAGE_ID,
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

        return [$org, $this->conversation(), $this->sender()];
    }

    private function conversation(): Conversation
    {
        $conversation = Conversation::query()->create([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 'thread_'.uniqid(),
            'external_contact_id' => 'contact_'.uniqid(),
            'status' => 'open',
            'started_at' => now()->subHours(2),
            'last_message_at' => now(),
        ]);

        $inbound = new ConversationMessage([
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_message_id' => 'mid_in_'.uniqid(),
            'direction' => 'inbound',
            'sender_external_id' => $conversation->external_contact_id,
            'recipient_external_id' => self::PAGE_ID,
            'message_type' => 'text',
            'body' => 'Synthetic inbound turn',
            'occurred_at' => now()->subMinutes(5),
            'received_at' => now()->subMinutes(5),
        ]);
        $inbound->conversation_id = $conversation->id;
        $inbound->org_id = $conversation->org_id;
        $inbound->idempotency_key = hash('sha256', 'in'.$conversation->id.uniqid());
        $inbound->save();

        return $conversation->refresh();
    }

    /** Someone allowed to send (the service re-checks this permission itself). */
    private function sender(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate(OutboundMessageService::PERMISSION, 'web'));

        return $user;
    }

    /** Someone allowed to resolve through the endpoint: view + reply, no role. */
    private function operator(bool $view = true, bool $reply = true): User
    {
        $user = User::factory()->create();

        if ($view) {
            $user->givePermissionTo(Permission::findOrCreate('crm.conversations.view', 'web'));
        }
        if ($reply) {
            $user->givePermissionTo(Permission::findOrCreate('crm.conversations.reply', 'web'));
        }

        return $user;
    }

    private function service(): OutboundMessageService
    {
        return app(OutboundMessageService::class);
    }

    private function persister(): OutboundMessagePersister
    {
        return app(OutboundMessagePersister::class);
    }

    private function request(Conversation $c, User $actor, string $clientRequestId = 'crid-fixed-0001'): OutboundMessageRequest
    {
        return new OutboundMessageRequest(
            conversation: $c,
            actor: $actor,
            body: 'Synthetic outbound reply',
            clientRequestId: $clientRequestId,
        );
    }

    /**
     * An outbound row written straight to the database in a chosen state — the fixture for every
     * resolution and summary test, because production cannot be made to produce these on demand.
     */
    private function outbound(
        Conversation $conversation,
        string $status,
        ?string $failureCode = null,
        ?Carbon $attemptedAt = null,
        ?Carbon $createdAt = null,
        ?string $providerId = null,
    ): ConversationMessage {
        $message = new ConversationMessage([
            'provider' => 'meta',
            'channel' => $conversation->channel,
            'direction' => 'outbound',
            'message_type' => 'text',
            'body' => 'Synthetic outbound body — must never appear in an audit record',
            'delivery_status' => $status,
            'failure_code' => $failureCode,
            'external_message_id' => $providerId,
            'occurred_at' => now(),
        ]);
        $message->conversation_id = $conversation->id;
        $message->org_id = $conversation->org_id;
        $message->idempotency_key = hash('sha256', 'out'.$conversation->id.uniqid('', true));
        $message->send_attempted_at = $attemptedAt;
        $message->save();

        if ($createdAt !== null) {
            DB::table('crm_messages')->where('id', $message->id)->update(['created_at' => $createdAt]);
        }

        return $message->refresh();
    }

    /**
     * One stub for the whole test that counts provider calls by reference and can be told what to
     * answer next. An arrow function would capture the counter by value and report zero forever.
     *
     * @param  list<'accept'|'timeout'|'500'|'400'>  $answers  consumed in order; the last repeats
     * @return \Closure(): int the number of calls that reached the provider
     */
    private function provider(array $answers, ?\Closure $during = null): \Closure
    {
        $calls = 0;

        Http::fake(['*' => function () use (&$calls, $answers, $during) {
            $calls++;
            $answer = $answers[min($calls, count($answers)) - 1];

            if ($during !== null) {
                $during();
            }

            return match ($answer) {
                'accept' => Http::response(['message_id' => 'mid_synthetic_out_'.$calls, 'recipient_id' => 'x'], 200),
                'timeout' => throw new ConnectionException('Synthetic timeout - outcome unknown.'),
                '500' => Http::response(['error' => ['message' => 'synthetic', 'code' => 1]], 500),
                '400' => Http::response(['error' => ['message' => 'synthetic', 'type' => 'OAuthException', 'code' => 100]], 400),
            };
        }]);

        return function () use (&$calls): int {
            return $calls;
        };
    }

    private function row(string $clientRequestId, Conversation $conversation, User $actor): ?ConversationMessage
    {
        $key = app(OutboundIdempotency::class)
            ->keyFor($this->request($conversation, $actor, $clientRequestId));

        return ConversationMessage::query()->withoutGlobalScopes()->where('idempotency_key', $key)->first();
    }

    // ============================================================ SEND CLAIM

    /** 1 — the first attempt claims the row, reaches the provider once, and stamps the attempt. */
    public function test_the_first_attempt_claims_and_reaches_the_provider_once(): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $calls = $this->provider(['accept']);

        $result = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($result->accepted, (string) $result->reason);
        $this->assertSame(1, $calls());

        $row = $this->row('crid-fixed-0001', $conversation, $actor);
        $this->assertSame('sent', $row->delivery_status);
        $this->assertNotNull($row->send_attempted_at, 'The claim must be recorded before the provider call.');
    }

    /**
     * 2 — THE HOLE THIS SLICE CLOSES. A same-key row that is already claimed and has no outcome yet
     * (a first request still in flight, or one that died after Meta accepted) must not be sent again.
     * Before 3H.5A this reached the provider a second time.
     */
    public function test_an_already_claimed_row_with_no_outcome_is_not_sent_again(): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $calls = $this->provider(['accept']);

        // A first attempt that claimed the row and never recorded an outcome.
        $persisted = $this->persister()->pending($this->request($conversation, $actor), $this->keyFor($conversation, $actor));
        $this->assertTrue($this->persister()->claim($persisted));

        $result = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::DeliveryUnknown, $result->failure);
        $this->assertSame(0, $calls(), 'A claimed row must never reach the provider again.');
        $this->assertSame('pending', $persisted->refresh()->delivery_status);
    }

    /**
     * 2b — THE STRONGEST CONCURRENCY TEST SQLITE ALLOWS. A second same-key attempt is fired from
     * INSIDE the first attempt's provider call — the exact moment the race would happen — and must be
     * refused without a second provider call.
     */
    public function test_a_same_key_attempt_made_while_the_first_is_in_flight_cannot_reach_the_provider(): void
    {
        [$org, $conversation, $actor] = $this->wired();

        /** @var OutboundMessageResult|null $inner */
        $inner = null;
        $fired = false;

        $calls = $this->provider(['accept'], function () use (&$inner, &$fired, $conversation, $actor, $org): void {
            if ($fired) {
                return;
            }
            $fired = true;
            $inner = $this->service()->send($this->request($conversation, $actor), $org);
        });

        $outer = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($outer->accepted, (string) $outer->reason);
        $this->assertNotNull($inner, 'The re-entrant attempt must actually have run.');
        $this->assertTrue($inner->wasRefused());
        $this->assertSame(OutboundFailureCode::DeliveryUnknown, $inner->failure);
        $this->assertSame(1, $calls(), 'Exactly one provider call for one client request id.');
    }

    /** 3 — a row the provider already accepted is reported, never re-sent. */
    public function test_a_sent_row_is_not_sent_again(): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $calls = $this->provider(['accept']);

        $this->service()->send($this->request($conversation, $actor), $org);
        $replay = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($replay->accepted);
        $this->assertSame(1, $calls());
    }

    /** 4 / 5 — both unresolved codes refuse a replay, and the provider is reached once. */
    #[DataProvider('unresolvedOutcomes')]
    public function test_an_unresolved_row_is_not_sent_again(string $answer, OutboundFailureCode $code): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $calls = $this->provider([$answer]);

        $first = $this->service()->send($this->request($conversation, $actor), $org);
        $this->assertSame($code, $first->failure);

        $replay = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($replay->wasRefused());
        $this->assertSame($code, $replay->failure);
        $this->assertSame(1, $calls());
        $this->assertSame('pending', $this->row('crid-fixed-0001', $conversation, $actor)->delivery_status);
    }

    /** @return array<string, array{string, OutboundFailureCode}> */
    public static function unresolvedOutcomes(): array
    {
        return [
            'timeout -> delivery_unknown' => ['timeout', OutboundFailureCode::DeliveryUnknown],
            '5xx -> provider_unavailable' => ['500', OutboundFailureCode::ProviderUnavailable],
        ];
    }

    /**
     * 6 — a DEFINITIVE failure keeps its existing semantics: the message was not delivered, so a
     * deliberate replay on the same id may be claimed again and sent.
     */
    public function test_a_definitively_failed_row_may_be_deliberately_attempted_again(): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $calls = $this->provider(['400', 'accept']);

        $first = $this->service()->send($this->request($conversation, $actor), $org);
        $this->assertSame(OutboundFailureCode::InvalidRequest, $first->failure);
        $this->assertSame('failed', $this->row('crid-fixed-0001', $conversation, $actor)->delivery_status);

        $again = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($again->accepted, (string) $again->reason);
        $this->assertSame(2, $calls());
        $row = $this->row('crid-fixed-0001', $conversation, $actor);
        $this->assertSame('sent', $row->delivery_status);
        $this->assertNull($row->failure_code);
    }

    /** A row created moments ago but never claimed (its request died before the provider) is still safe to send. */
    public function test_a_fresh_unclaimed_pending_row_is_claimable(): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $calls = $this->provider(['accept']);

        $this->persister()->pending($this->request($conversation, $actor), $this->keyFor($conversation, $actor));

        $result = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($result->accepted, (string) $result->reason);
        $this->assertSame(1, $calls());
    }

    /** The claim is one-way: a second claim on the same row fails. */
    public function test_a_claim_succeeds_exactly_once(): void
    {
        [, $conversation, $actor] = $this->wired();
        $persisted = $this->persister()->pending($this->request($conversation, $actor), $this->keyFor($conversation, $actor));

        $this->assertTrue($this->persister()->claim($persisted));
        $this->assertFalse($this->persister()->claim($persisted->fresh()));
        $this->assertFalse($this->persister()->claim($persisted->fresh()));
    }

    // ============================================================ STRANDED

    /** 7 — inside the window a claimed row is "sending"; past it, it is unresolved. */
    public function test_stranded_detection_uses_the_two_minute_window(): void
    {
        [, $conversation] = $this->wired();
        $this->freezeTime();

        $row = $this->outbound($conversation, 'pending', null, now());

        $this->travel(OutboundMessagePersister::STRANDED_AFTER_SECONDS - 1)->seconds();
        $this->assertFalse($this->persister()->isStranded($row->fresh()));
        $this->assertNull($this->persister()->unresolvedCode($row->fresh()));

        $this->travel(2)->seconds();
        $this->assertTrue($this->persister()->isStranded($row->fresh()));
        $this->assertSame(OutboundFailureCode::DeliveryUnknown, $this->persister()->unresolvedCode($row->fresh()));

        // Derived on read, never written: the row itself still records no outcome.
        $this->assertNull($row->fresh()->failure_code);
    }

    /** A stranded row is never re-sent on a replay. */
    public function test_a_stranded_row_is_not_sent_again(): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $calls = $this->provider(['accept']);

        $persisted = $this->persister()->pending($this->request($conversation, $actor), $this->keyFor($conversation, $actor));
        $this->persister()->claim($persisted);

        $this->travel(OutboundMessagePersister::STRANDED_AFTER_SECONDS + 5)->seconds();

        $result = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($result->wasRefused());
        $this->assertSame(OutboundFailureCode::DeliveryUnknown, $result->failure);
        $this->assertSame(0, $calls());
    }

    /** An OLD row that was never claimed (pre-3H.5A, or a request that died) is unresolved, not sendable. */
    public function test_an_old_unclaimed_pending_row_is_stranded_and_not_claimable(): void
    {
        [, $conversation] = $this->wired();
        $row = $this->outbound($conversation, 'pending', null, null, now()->subMinutes(10));

        $this->assertTrue($this->persister()->isStranded($row));
        $this->assertFalse($this->persister()->claim($row));
    }

    /** Historical rows (send_attempted_at NULL) keep their meaning: sent and failed are not unresolved. */
    public function test_historical_rows_without_an_attempt_time_are_unchanged(): void
    {
        [, $conversation] = $this->wired();

        $sent = $this->outbound($conversation, 'sent', null, null, now()->subDay(), 'mid_hist_1');
        $failed = $this->outbound($conversation, 'failed', 'invalid_request', null, now()->subDay());

        $this->assertNull($this->persister()->unresolvedCode($sent));
        $this->assertNull($this->persister()->unresolvedCode($failed));
        $this->assertFalse($this->persister()->isStranded($sent));
        $this->assertFalse($this->persister()->isStranded($failed));
        $this->assertCount(0, $this->persister()->unresolvedInConversation($conversation));
    }

    // ============================================================ SUMMARY

    /** 8 — the durable summary lists exactly the unresolved rows, in a minimal shape. */
    public function test_conversation_detail_lists_unresolved_outbound_messages(): void
    {
        [, $conversation] = $this->wired();

        $unknown = $this->outbound($conversation, 'pending', 'delivery_unknown', now()->subMinute());
        $unavailable = $this->outbound($conversation, 'pending', 'provider_unavailable', now()->subMinute());
        $stranded = $this->outbound($conversation, 'pending', null, now()->subMinutes(5));
        $this->outbound($conversation, 'pending', null, now()->subSeconds(10));          // in flight
        $this->outbound($conversation, 'sent', null, now()->subMinute(), null, 'mid_x');  // resolved
        $this->outbound($conversation, 'failed', 'invalid_request', now()->subMinute()); // resolved

        Sanctum::actingAs($this->operator());

        $response = $this->getJson('/api/v1/crm/conversations/'.$conversation->id)->assertStatus(200);
        $rows = $response->json('data.unresolvedOutbound');

        $this->assertSame(
            [(string) $unknown->id, (string) $unavailable->id, (string) $stranded->id],
            array_column($rows, 'id'),
        );
        $this->assertSame(['id', 'createdAt', 'sendAttemptedAt', 'failureCode', 'stranded'], array_keys($rows[0]));
        $this->assertFalse($rows[0]['stranded']);
        $this->assertSame('delivery_unknown', $rows[0]['failureCode']);
        $this->assertTrue($rows[2]['stranded']);
        $this->assertNull($rows[2]['failureCode']);

        $this->assertStringNotContainsString('must never appear', json_encode($rows));
        Http::assertNothingSent();
    }

    // ============================================================ RESOLUTION

    private function resolveUrl(Conversation $c, ConversationMessage $m): string
    {
        return '/api/v1/crm/conversations/'.$c->id.'/messages/'.$m->id.'/resolution';
    }

    private function resolutionActivities(): Collection
    {
        return Activity::query()->withoutGlobalScopes()->where('event', OutboundDeliveryResolver::EVENT)->get();
    }

    /** 9 / 11 — "sent" is recorded without inventing a provider id or a delivery time. */
    public function test_an_operator_can_confirm_an_unresolved_message_as_sent(): void
    {
        [, $conversation] = $this->wired();
        $row = $this->outbound($conversation, 'pending', 'delivery_unknown', now()->subMinute());
        Sanctum::actingAs($this->operator());

        $response = $this->postJson($this->resolveUrl($conversation, $row), ['outcome' => 'sent']);

        $response->assertStatus(200)
            ->assertJsonPath('resolved', true)
            ->assertJsonPath('outcome', 'sent')
            ->assertJsonPath('message.deliveryStatus', 'sent')
            ->assertJsonPath('message.providerMessageIdPresent', false)
            ->assertJsonPath('message.deliveredAt', null);

        $fresh = $row->fresh();
        $this->assertSame('sent', $fresh->delivery_status);
        $this->assertNull($fresh->external_message_id, 'A provider id must never be invented.');
        $this->assertNull($fresh->failure_code);
        $this->assertNull($fresh->delivered_at);
        Http::assertNothingSent();
    }

    /** 10 — "not sent" becomes a failure with its own operator code. */
    public function test_an_operator_can_confirm_an_unresolved_message_as_not_sent(): void
    {
        [, $conversation] = $this->wired();
        $row = $this->outbound($conversation, 'pending', 'provider_unavailable', now()->subMinute());
        Sanctum::actingAs($this->operator());

        $this->postJson($this->resolveUrl($conversation, $row), ['outcome' => 'not_sent'])
            ->assertStatus(200)
            ->assertJsonPath('resolved', true)
            ->assertJsonPath('message.deliveryStatus', 'failed')
            ->assertJsonPath('message.failureCode', 'operator_confirmed_not_sent');

        $this->assertSame('failed', $row->fresh()->delivery_status);
        $this->assertSame(OutboundFailureCode::OperatorConfirmedNotSent->value, $row->fresh()->failure_code);
        Http::assertNothingSent();
    }

    /** A stranded row (no outcome ever recorded) can be resolved too. */
    public function test_a_stranded_message_can_be_resolved(): void
    {
        [, $conversation] = $this->wired();
        $row = $this->outbound($conversation, 'pending', null, now()->subMinutes(5));
        Sanctum::actingAs($this->operator());

        $this->postJson($this->resolveUrl($conversation, $row), ['outcome' => 'not_sent'])->assertStatus(200);

        $activity = $this->resolutionActivities()->sole();
        $this->assertTrue($activity->properties['was_stranded']);
        $this->assertNull($activity->properties['previous_failure_code']);
    }

    /** 12 / 20 — only a still-unresolved row can be resolved; anything else is a 409 with no audit. */
    #[DataProvider('notResolvable')]
    public function test_only_an_unresolved_message_can_be_resolved(string $status, ?string $code, int $attemptedSecondsAgo): void
    {
        [, $conversation] = $this->wired();
        $row = $this->outbound(
            $conversation,
            $status,
            $code,
            now()->subSeconds($attemptedSecondsAgo),
            null,
            $status === 'sent' ? 'mid_done' : null,
        );
        $before = $row->fresh()->getAttributes();
        Sanctum::actingAs($this->operator());

        $this->postJson($this->resolveUrl($conversation, $row), ['outcome' => 'sent'])
            ->assertStatus(409)
            ->assertJsonPath('resolved', false);

        $this->assertSame($before, $row->fresh()->getAttributes(), 'A refused resolution must change nothing.');
        $this->assertCount(0, $this->resolutionActivities());
    }

    /** @return array<string, array{string, ?string, int}> */
    public static function notResolvable(): array
    {
        return [
            'already sent' => ['sent', null, 60],
            'definitively failed' => ['failed', 'invalid_request', 60],
            'still in flight (inside window)' => ['pending', null, 10],
        ];
    }

    /** 13 / 19 / 20 — the second verdict loses, changes nothing, and audits nothing. */
    public function test_a_second_resolution_is_a_conflict_and_the_first_verdict_stands(): void
    {
        [, $conversation] = $this->wired();
        $row = $this->outbound($conversation, 'pending', 'delivery_unknown', now()->subMinute());
        $operator = $this->operator();
        Sanctum::actingAs($operator);

        $this->postJson($this->resolveUrl($conversation, $row), ['outcome' => 'sent'])->assertStatus(200);
        $this->postJson($this->resolveUrl($conversation, $row), ['outcome' => 'not_sent'])
            ->assertStatus(409)
            ->assertJsonPath('message.deliveryStatus', 'sent');

        $this->assertSame('sent', $row->fresh()->delivery_status);

        $activity = $this->resolutionActivities()->sole();
        $this->assertSame('Conversation', class_basename($activity->subject_type));
        $this->assertSame($conversation->id, (int) $activity->subject_id);
        $this->assertSame($operator->id, (int) $activity->causer_id);
        $this->assertSame(
            ['conversation_id', 'message_id', 'outcome', 'previous_failure_code', 'was_stranded', 'provider', 'channel'],
            array_keys($activity->properties),
        );
        $this->assertSame('delivery_unknown', $activity->properties['previous_failure_code']);
        $this->assertStringNotContainsString('must never appear', json_encode($activity->properties).$activity->description);
    }

    /** 14 — another tenant's conversation is unreachable. */
    public function test_a_cross_tenant_message_cannot_be_resolved(): void
    {
        $orgA = $this->tenantId();
        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);

        [$foreignConversation, $foreignRow] = CurrentOrg::runAs($orgB->id, function (): array {
            $c = $this->conversation();

            return [$c, $this->outbound($c, 'pending', 'delivery_unknown', now()->subMinute())];
        });

        CurrentOrg::set($orgA);
        Sanctum::actingAs($this->operator());

        $this->postJson($this->resolveUrl($foreignConversation, $foreignRow), ['outcome' => 'sent'])->assertStatus(404);
        $this->assertSame('pending', DB::table('crm_messages')->where('id', $foreignRow->id)->value('delivery_status'));
    }

    /** 15 — a message cannot be resolved through a different conversation's URL. */
    public function test_a_message_cannot_be_resolved_through_another_conversation(): void
    {
        [, $conversation] = $this->wired();
        $other = $this->conversation();
        $row = $this->outbound($conversation, 'pending', 'delivery_unknown', now()->subMinute());
        Sanctum::actingAs($this->operator());

        $this->postJson($this->resolveUrl($other, $row), ['outcome' => 'sent'])->assertStatus(404);
        $this->assertSame('pending', $row->fresh()->delivery_status);
    }

    /** Inbound messages are not resolvable. */
    public function test_an_inbound_message_cannot_be_resolved(): void
    {
        [, $conversation] = $this->wired();
        $inbound = ConversationMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'inbound')->firstOrFail();
        Sanctum::actingAs($this->operator());

        $this->postJson($this->resolveUrl($conversation, $inbound), ['outcome' => 'sent'])->assertStatus(404);
    }

    /** 16 — each of the two abilities is required. */
    #[DataProvider('missingAbilities')]
    public function test_an_unauthorized_user_is_denied(bool $view, bool $reply): void
    {
        [, $conversation] = $this->wired();
        $row = $this->outbound($conversation, 'pending', 'delivery_unknown', now()->subMinute());
        Sanctum::actingAs($this->operator($view, $reply));

        $this->postJson($this->resolveUrl($conversation, $row), ['outcome' => 'sent'])->assertStatus(403);
        $this->assertSame('pending', $row->fresh()->delivery_status);
        $this->assertCount(0, $this->resolutionActivities());
    }

    /** @return array<string, array{bool, bool}> */
    public static function missingAbilities(): array
    {
        return [
            'view only' => [true, false],
            'reply only' => [false, true],
            'neither' => [false, false],
        ];
    }

    /** 17 — crm_admin, through its role. */
    public function test_a_crm_admin_can_resolve(): void
    {
        [, $conversation] = $this->wired();
        $row = $this->outbound($conversation, 'pending', 'delivery_unknown', now()->subMinute());
        $admin = User::factory()->create();
        $admin->assignRole('crm_admin');
        Sanctum::actingAs($admin);

        $this->postJson($this->resolveUrl($conversation, $row), ['outcome' => 'sent'])->assertStatus(200);
    }

    /**
     * 18 — super_admin through Gate::before, with the two grants stripped from the role so the test
     * matches production, where super_admin holds neither row.
     */
    public function test_a_super_admin_can_resolve_through_gate_before(): void
    {
        [, $conversation] = $this->wired();
        $row = $this->outbound($conversation, 'pending', 'delivery_unknown', now()->subMinute());

        $role = Role::findByName('super_admin', 'web');
        foreach (['crm.conversations.view', 'crm.conversations.reply'] as $p) {
            if ($role->hasPermissionTo($p)) {
                $role->revokePermissionTo($p);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $super = User::factory()->create();
        $super->assignRole('super_admin');
        $this->assertNotContains('crm.conversations.reply', $super->getAllPermissions()->pluck('name')->all());

        Sanctum::actingAs($super);

        $this->postJson($this->resolveUrl($conversation, $row), ['outcome' => 'not_sent'])->assertStatus(200);
    }

    /** The outcome is validated, and server-owned fields are refused loudly. */
    #[DataProvider('badBodies')]
    public function test_invalid_bodies_are_rejected(array $body, string $field): void
    {
        [, $conversation] = $this->wired();
        $row = $this->outbound($conversation, 'pending', 'delivery_unknown', now()->subMinute());
        Sanctum::actingAs($this->operator());

        $this->postJson($this->resolveUrl($conversation, $row), $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertSame('pending', $row->fresh()->delivery_status);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badBodies(): array
    {
        return [
            'missing outcome' => [[], 'outcome'],
            'unknown outcome' => [['outcome' => 'maybe'], 'outcome'],
            'client sets status' => [['outcome' => 'sent', 'deliveryStatus' => 'sent'], 'deliveryStatus'],
            'client sets provider id' => [['outcome' => 'sent', 'providerMessageId' => 'mid_fake'], 'providerMessageId'],
            'client sets code' => [['outcome' => 'not_sent', 'failure_code' => 'x'], 'failure_code'],
        ];
    }

    // ============================================================ LATE PROVIDER RESULTS

    private const LATE_EVENT = 'crm.outbound.late_outcome_ignored';

    /** A whole-second instant: the columns store Y-m-d H:i:s, so a fractional clock would blur 120 s. */
    private function frozenAt(): Carbon
    {
        $t0 = Carbon::parse('2026-09-29 10:00:00');
        $this->travelTo($t0);

        return $t0;
    }

    /**
     * Spy on the log and collect the context of every late-outcome warning.
     *
     * @return \Closure(): list<array<string, mixed>>
     */
    private function lateOutcomeLog(): \Closure
    {
        $seen = [];

        Log::spy();
        Log::shouldReceive('warning')->andReturnUsing(function (string $event, array $context = []) use (&$seen): void {
            if ($event === self::LATE_EVENT) {
                $seen[] = $context;
            }
        });

        return function () use (&$seen): array {
            return $seen;
        };
    }

    /**
     * F1 — an operator's verdict is final. The original request's provider result, arriving after
     * the operator resolved the stranded row, must change nothing: not the status, not the code, not
     * the provider id, not the delivery or attempt time.
     */
    #[DataProvider('lateResults')]
    public function test_a_late_provider_result_never_overwrites_an_operator_verdict(string $verdict, string $late): void
    {
        [, $conversation, $actor] = $this->wired();
        $log = $this->lateOutcomeLog();
        $this->frozenAt();

        // The original request: it claimed the row and is still inside its provider call.
        $stale = $this->persister()->pending($this->request($conversation, $actor), $this->keyFor($conversation, $actor));
        $this->assertTrue($this->persister()->claim($stale));
        $claimedAt = $stale->getRawOriginal('send_attempted_at');

        // Two minutes on it is stranded, and an operator resolves it.
        $this->travel(OutboundMessagePersister::STRANDED_AFTER_SECONDS + 1)->seconds();
        Sanctum::actingAs($this->operator());
        $this->postJson($this->resolveUrl($conversation, $stale), ['outcome' => $verdict])->assertStatus(200);

        $resolved = $stale->fresh()->getAttributes();

        // Then the original request's result finally lands, on its stale model.
        $written = match ($late) {
            'sent' => $this->persister()->markSent($stale, 'mid_late_synthetic_0001'),
            'failed' => $this->persister()->markFailed($stale, OutboundFailureCode::InvalidRequest),
            'unresolved' => $this->persister()->markUnresolved($stale, OutboundFailureCode::DeliveryUnknown),
        };

        $this->assertFalse($written, 'A late result must report that it was not recorded.');
        $this->assertSame($resolved, $stale->fresh()->getAttributes(), 'The operator verdict must be untouched.');

        $row = $stale->fresh();
        $this->assertSame($verdict === 'sent' ? 'sent' : 'failed', $row->delivery_status);
        $this->assertSame($verdict === 'sent' ? null : 'operator_confirmed_not_sent', $row->failure_code);
        $this->assertNull($row->external_message_id, 'No provider id may appear after an operator verdict.');
        $this->assertNull($row->delivered_at);
        $this->assertSame($claimedAt, $row->getRawOriginal('send_attempted_at'));

        // Exactly one sanitized warning, carrying identifiers and codes only.
        $entries = $log();
        $this->assertCount(1, $entries);
        $this->assertSame(
            ['message_id', 'conversation_id', 'org_id', 'attempted_status', 'attempted_code', 'provider_message_id_present', 'current_status', 'current_code'],
            array_keys($entries[0]),
        );
        $this->assertSame((int) $row->id, $entries[0]['message_id']);
        $this->assertSame($row->delivery_status, $entries[0]['current_status']);
        $this->assertSame($late === 'sent', $entries[0]['provider_message_id_present']);
        $flat = json_encode($entries[0]);
        $this->assertStringNotContainsString('mid_late_synthetic', $flat);
        $this->assertStringNotContainsString('Synthetic outbound reply', $flat);
        $this->assertStringNotContainsString(self::TOKEN, $flat);

        Http::assertNothingSent();
    }

    /** @return array<string, array{string, string}> */
    public static function lateResults(): array
    {
        return [
            'not_sent, then late markSent' => ['not_sent', 'sent'],
            'sent, then late markFailed' => ['sent', 'failed'],
            'sent, then late markUnresolved' => ['sent', 'unresolved'],
            'not_sent, then late markUnresolved' => ['not_sent', 'unresolved'],
            'sent, then late markSent' => ['sent', 'sent'],
            'not_sent, then late markFailed' => ['not_sent', 'failed'],
        ];
    }

    /**
     * F1 — the result write is bound to ITS OWN claim. After an operator's `not_sent`, a replay may
     * legitimately claim the row again; the FIRST attempt's late result, landing while the second
     * attempt is inside its provider call, must not be written onto the second attempt's row.
     */
    public function test_a_late_result_from_an_earlier_attempt_cannot_land_on_a_new_attempt(): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $log = $this->lateOutcomeLog();
        $this->frozenAt();

        $stale = $this->persister()->pending($this->request($conversation, $actor), $this->keyFor($conversation, $actor));
        $this->assertTrue($this->persister()->claim($stale));

        $this->travel(OutboundMessagePersister::STRANDED_AFTER_SECONDS + 1)->seconds();
        Sanctum::actingAs($this->operator());
        $this->postJson($this->resolveUrl($conversation, $stale), ['outcome' => 'not_sent'])->assertStatus(200);

        $lateWritten = null;
        $calls = $this->provider(['accept'], function () use (&$lateWritten, $stale): void {
            $lateWritten ??= $this->persister()->markSent($stale, 'mid_late_first_attempt');
        });

        $replay = $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertTrue($replay->accepted, (string) $replay->reason);
        $this->assertSame(1, $calls());
        $this->assertFalse($lateWritten, 'The first attempt must not write onto the second attempt.');

        $row = $stale->fresh();
        $this->assertSame('sent', $row->delivery_status);
        $this->assertSame('mid_synthetic_out_1', $row->external_message_id, 'The second attempt records its own result.');
        $this->assertNull($row->failure_code);
        $this->assertCount(1, $log());
    }

    /**
     * F1 — the ordinary outcomes still record, through the real service, on a row the service itself
     * claimed. The claim time is preserved and no late-outcome warning is written.
     */
    #[DataProvider('normalResults')]
    public function test_a_provider_result_on_the_claimed_row_is_recorded(string $answer, string $status, ?string $code, bool $delivered): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $log = $this->lateOutcomeLog();
        $t0 = $this->frozenAt();
        $calls = $this->provider([$answer]);

        $this->service()->send($this->request($conversation, $actor), $org);

        $this->assertSame(1, $calls());
        $row = $this->row('crid-fixed-0001', $conversation, $actor);
        $this->assertSame($status, $row->delivery_status);
        $this->assertSame($code, $row->failure_code);
        $this->assertSame($delivered ? 'mid_synthetic_out_1' : null, $row->external_message_id);
        $this->assertSame($delivered, $row->delivered_at !== null);
        $this->assertTrue($row->send_attempted_at->equalTo($t0), 'The claim time must survive the result write.');
        $this->assertCount(0, $log());
    }

    /** @return array<string, array{string, string, ?string, bool}> */
    public static function normalResults(): array
    {
        return [
            'provider success -> sent' => ['accept', 'sent', null, true],
            'unresolved result (5xx) -> pending + provider_unavailable' => ['500', 'pending', 'provider_unavailable', false],
            'unresolved result (timeout) -> pending + delivery_unknown' => ['timeout', 'pending', 'delivery_unknown', false],
            'definitive failure (4xx) -> failed + invalid_request' => ['400', 'failed', 'invalid_request', false],
        ];
    }

    /** F1 — a stranded row nobody has resolved yet still takes its late result: a real outcome beats "unknown". */
    public function test_a_late_result_on_an_unresolved_stranded_row_is_still_recorded(): void
    {
        [, $conversation, $actor] = $this->wired();
        $log = $this->lateOutcomeLog();
        $this->frozenAt();

        $stale = $this->persister()->pending($this->request($conversation, $actor), $this->keyFor($conversation, $actor));
        $this->assertTrue($this->persister()->claim($stale));
        $this->travel(OutboundMessagePersister::STRANDED_AFTER_SECONDS + 30)->seconds();

        $this->assertTrue($this->persister()->markSent($stale, 'mid_late_but_first'));
        $this->assertSame('sent', $stale->fresh()->delivery_status);
        $this->assertCount(0, $log());
    }

    // ============================================================ 120-SECOND BOUNDARY

    /**
     * F2 — `age >= 120 s` is stranded, everywhere, to the second: the replay guard
     * (`unresolvedCode`), the durable summary (`unresolvedOutbound` through the real detail GET),
     * manual-resolution eligibility (`whereUnresolved` via `resolveManually`), and — its exact
     * complement — the claim of an unclaimed row. Measured from `send_attempted_at` when claimed,
     * otherwise from `created_at`.
     */
    #[DataProvider('boundary')]
    public function test_the_stranded_rule_is_age_at_least_120_seconds_everywhere(int $age, bool $claimed): void
    {
        [, $conversation] = $this->wired();
        $t0 = $this->frozenAt();
        $stranded = $age >= OutboundMessagePersister::STRANDED_AFTER_SECONDS;

        // Three identical rows: one to read, one to resolve, one to claim (writes consume a row).
        $make = fn (): ConversationMessage => $this->outbound($conversation, 'pending', null, $claimed ? $t0->copy() : null);
        $read = $make();
        $resolve = $make();
        $claim = $make();

        $this->travelTo($t0->copy()->addSeconds($age));

        $this->assertSame(
            $stranded ? OutboundFailureCode::DeliveryUnknown : null,
            $this->persister()->unresolvedCode($read->fresh()),
            'unresolvedCode()',
        );
        $this->assertSame($stranded, $this->persister()->isStranded($read->fresh()), 'isStranded()');

        Sanctum::actingAs($this->operator());
        $listed = array_column(
            $this->getJson('/api/v1/crm/conversations/'.$conversation->id)->assertStatus(200)->json('data.unresolvedOutbound'),
            'id',
        );
        $this->assertSame(
            $stranded ? [(string) $read->id, (string) $resolve->id, (string) $claim->id] : [],
            $listed,
            'unresolvedOutbound',
        );

        $this->assertSame(
            $stranded,
            $this->persister()->resolveManually($resolve, OutboundMessagePersister::OUTCOME_NOT_SENT, (int) $conversation->org_id),
            'resolveManually() / whereUnresolved()',
        );

        // The claim window is the complement: an unclaimed row is claimable only while NOT stranded.
        // A claimed row is never claimable again, at any age.
        $this->assertSame(! $claimed && ! $stranded, $this->persister()->claim($claim->fresh()), 'claim()');

        Http::assertNothingSent();
    }

    /** @return array<string, array{int, bool}> */
    public static function boundary(): array
    {
        return [
            'claimed, 119 s -> still sending' => [119, true],
            'claimed, 120 s -> stranded' => [120, true],
            'claimed, 121 s -> stranded' => [121, true],
            'unclaimed (created_at), 119 s -> still sending' => [119, false],
            'unclaimed (created_at), 120 s -> stranded' => [120, false],
            'unclaimed (created_at), 121 s -> stranded' => [121, false],
        ];
    }

    // ------------------------------------------------------------------ util

    private function keyFor(Conversation $conversation, User $actor): string
    {
        return (string) app(OutboundIdempotency::class)
            ->keyFor($this->request($conversation, $actor));
    }
}
