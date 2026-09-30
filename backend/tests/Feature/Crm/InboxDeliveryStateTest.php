<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use App\Modules\Crm\Services\Outbound\OutboundMessagePersister;
use App\Modules\Crm\Services\Outbound\OutboundMessageService;
use App\Modules\Crm\Services\Outbound\ReplyCapability;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Phase 3H.0 — the delivery state and reply capability the inbox needs before it can grow a composer.
 *
 * TWO PROPERTIES ARE UNDER TEST AND THEY PULL IN OPPOSITE DIRECTIONS. The inbox must learn enough
 * to distinguish five message states and to decide whether to offer a composer at all; and it must
 * learn nothing more — no provider message id, no platform-scoped person identifier, no provider
 * prose, no account or connection id, no credential. Every assertion below serves one of those two.
 *
 * Synthetic content only. No production identifier, body or token appears in this file, and no
 * request leaves the process: nothing here calls Meta, and the capability check is deliberately
 * incapable of doing so.
 */
class InboxDeliveryStateTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_MID = 'mid_PROVIDER_ID_CANARY_7731';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function tenantId(): int
    {
        $org = $this->tenant();
        CurrentOrg::set($org->id);

        return (int) $org->id;
    }

    /** A viewer who may read the inbox and, optionally, reply. */
    private function viewer(bool $mayReply = false): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('crm.conversations.view', 'web'));

        if ($mayReply) {
            $user->givePermissionTo(Permission::findOrCreate(OutboundMessageService::PERMISSION, 'web'));
        }

        return $user;
    }

    private function conversation(string $channel = 'messenger', string $provider = 'meta'): Conversation
    {
        return Conversation::query()->create([
            'provider' => $provider,
            'channel' => $channel,
            'external_conversation_id' => 'thread_'.uniqid(),
            'external_contact_id' => 'contact_'.uniqid(),
            'status' => 'open',
            'started_at' => now()->subMinutes(10),
            'last_message_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function message(Conversation $conversation, string $direction, array $attributes = []): ConversationMessage
    {
        $message = new ConversationMessage(array_merge([
            'provider' => $conversation->provider,
            'channel' => $conversation->channel,
            'direction' => $direction,
            'message_type' => 'text',
            'body' => 'Synthetic turn',
            'occurred_at' => now(),
        ], $attributes));

        $message->conversation_id = $conversation->id;
        $message->org_id = $conversation->org_id;
        $message->idempotency_key = hash('sha256', $conversation->id.$direction.uniqid());
        $message->save();

        return $message;
    }

    /** @return array<string, mixed> */
    private function firstMessage(Conversation $conversation, User $viewer): array
    {
        Sanctum::actingAs($viewer);

        return $this->getJson('/api/v1/crm/conversations/'.$conversation->id)
            ->assertOk()
            ->json('data.messages.0');
    }

    // ------------------------------------------------------- A. inbound

    public function test_an_inbound_message_keeps_its_existing_fields_and_carries_no_delivery_state(): void
    {
        $this->tenantId();
        $conversation = $this->conversation();
        $this->message($conversation, 'inbound', ['body' => 'Synthetic enquiry', 'received_at' => now()]);

        $message = $this->firstMessage($conversation, $this->viewer());

        // Existing contract, unchanged.
        $this->assertSame('inbound', $message['direction']);
        $this->assertSame('text', $message['messageType']);
        $this->assertSame('Synthetic enquiry', $message['body']);
        $this->assertFalse($message['hasAttachment']);
        $this->assertNotNull($message['occurredAt']);

        // Nothing is invented for a turn we did not send.
        $this->assertNull($message['deliveryStatus']);
        $this->assertNull($message['failureCode']);
        $this->assertNull($message['deliveredAt']);
        $this->assertFalse($message['providerMessageIdPresent']);
    }

    /**
     * An inbound turn that DOES carry a provider mid reports the boolean honestly.
     *
     * Read literally, `providerMessageIdPresent` answers "is a provider message id recorded",
     * which for inbound is usually yes. It is not a delivery claim — the caller separates the two
     * cases by `direction`, which it already has — and the identifier itself still never ships.
     */
    public function test_the_provider_id_flag_is_literal_and_never_leaks_the_identifier(): void
    {
        $this->tenantId();
        $conversation = $this->conversation();
        $this->message($conversation, 'inbound', ['external_message_id' => self::PROVIDER_MID]);

        $viewer = $this->viewer();
        $message = $this->firstMessage($conversation, $viewer);

        $this->assertTrue($message['providerMessageIdPresent']);
        $this->assertArrayNotHasKey('externalMessageId', $message);
        $this->assertArrayNotHasKey('external_message_id', $message);

        Sanctum::actingAs($viewer);
        $body = $this->getJson('/api/v1/crm/conversations/'.$conversation->id)->getContent();
        $this->assertStringNotContainsString(self::PROVIDER_MID, (string) $body);
    }

    // ------------------------------------------------------- B–D. outbound states

    public function test_an_outbound_sent_message_reports_a_completed_delivery(): void
    {
        $this->tenantId();
        $conversation = $this->conversation();
        $this->message($conversation, 'outbound', [
            'delivery_status' => OutboundMessagePersister::SENT,
            'external_message_id' => self::PROVIDER_MID,
            'delivered_at' => now(),
        ]);

        $message = $this->firstMessage($conversation, $this->viewer());

        $this->assertSame('sent', $message['deliveryStatus']);
        $this->assertNull($message['failureCode']);
        $this->assertNotNull($message['deliveredAt']);
        $this->assertTrue($message['providerMessageIdPresent']);
    }

    public function test_an_outbound_failed_message_reports_our_own_failure_code(): void
    {
        $this->tenantId();
        $conversation = $this->conversation();
        $this->message($conversation, 'outbound', [
            'delivery_status' => OutboundMessagePersister::FAILED,
            'failure_code' => OutboundFailureCode::OutsideMessagingWindow->value,
        ]);

        $message = $this->firstMessage($conversation, $this->viewer());

        $this->assertSame('failed', $message['deliveryStatus']);
        $this->assertSame('outside_messaging_window', $message['failureCode']);
        $this->assertNull($message['deliveredAt']);
        $this->assertFalse($message['providerMessageIdPresent']);
    }

    /**
     * The unresolved state: `pending` CARRYING a failure code.
     *
     * The single most important row for the UI to get right. It is not a failure — the message may
     * already be with the customer — which is why the server refuses to mark it `failed` and why
     * the UI must never offer a retry on it.
     *
     * @param  string  $code
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unresolvedCodes')]
    public function test_an_unresolved_outbound_message_is_pending_with_a_safe_code(string $code): void
    {
        $this->tenantId();
        $conversation = $this->conversation();
        $this->message($conversation, 'outbound', [
            'delivery_status' => OutboundMessagePersister::PENDING,
            'failure_code' => $code,
        ]);

        $message = $this->firstMessage($conversation, $this->viewer());

        $this->assertSame('pending', $message['deliveryStatus']);
        $this->assertSame($code, $message['failureCode']);
        $this->assertNull($message['deliveredAt']);
        $this->assertFalse($message['providerMessageIdPresent']);

        // The pair is what identifies the state; neither half alone does.
        $this->assertTrue(OutboundFailureCode::from($code)->leavesDeliveryUnresolved());
    }

    /** @return array<string, array{string}> */
    public static function unresolvedCodes(): array
    {
        return [
            'timed out' => [OutboundFailureCode::DeliveryUnknown->value],
            'provider fault' => [OutboundFailureCode::ProviderUnavailable->value],
        ];
    }

    public function test_a_freshly_pending_message_carries_no_failure_code(): void
    {
        $this->tenantId();
        $conversation = $this->conversation();
        $this->message($conversation, 'outbound', ['delivery_status' => OutboundMessagePersister::PENDING]);

        $message = $this->firstMessage($conversation, $this->viewer());

        $this->assertSame('pending', $message['deliveryStatus']);
        $this->assertNull($message['failureCode'], 'pending WITHOUT a code is in flight, not unresolved.');
    }

    // ------------------------------------------------------- E. leak guard

    public function test_the_thread_still_exposes_no_identifiers_credentials_or_provider_prose(): void
    {
        $this->tenantId();
        $conversation = $this->conversation();
        $this->message($conversation, 'outbound', [
            'delivery_status' => OutboundMessagePersister::FAILED,
            'failure_code' => OutboundFailureCode::CredentialRejected->value,
            'sender_external_id' => 'SENDER_CANARY_4410',
            'recipient_external_id' => 'RECIPIENT_CANARY_9902',
            'external_message_id' => self::PROVIDER_MID,
            'payload' => ['error' => ['message' => 'META_PROSE_CANARY_1234']],
        ]);

        Sanctum::actingAs($this->viewer());
        $body = (string) $this->getJson('/api/v1/crm/conversations/'.$conversation->id)->getContent();

        foreach ([
            self::PROVIDER_MID,
            'SENDER_CANARY_4410',
            'RECIPIENT_CANARY_9902',
            'META_PROSE_CANARY_1234',
            'provider_account_id',
            'provider_connection_id',
            'access_token',
            'idempotency_key',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, $forbidden.' must never reach the browser.');
        }
    }

    // ------------------------------------------------------- F–I. reply capability

    public function test_a_viewer_without_the_reply_permission_is_told_permission_denied(): void
    {
        $this->tenantId();
        $conversation = $this->conversation();
        config(['meta.outbound.enabled' => true]);

        Sanctum::actingAs($this->viewer());
        $response = $this->getJson('/api/v1/crm/conversations/'.$conversation->id)->assertOk();

        $response->assertJsonPath('data.reply.enabled', false);
        $response->assertJsonPath('data.reply.reason', ReplyCapability::PERMISSION_DENIED);
    }

    public function test_an_allowed_viewer_with_outbound_disabled_is_told_outbound_disabled(): void
    {
        $this->tenantId();
        $conversation = $this->conversation();
        config(['meta.outbound.enabled' => false]);

        Sanctum::actingAs($this->viewer(mayReply: true));
        $response = $this->getJson('/api/v1/crm/conversations/'.$conversation->id)->assertOk();

        $response->assertJsonPath('data.reply.enabled', false);
        $response->assertJsonPath('data.reply.reason', ReplyCapability::OUTBOUND_DISABLED);
    }

    public function test_an_unsupported_provider_or_channel_is_told_unsupported_channel(): void
    {
        $this->tenantId();
        $conversation = $this->conversation('sms', 'twilio');
        config(['meta.outbound.enabled' => true]);

        Sanctum::actingAs($this->viewer(mayReply: true));
        $response = $this->getJson('/api/v1/crm/conversations/'.$conversation->id)->assertOk();

        $response->assertJsonPath('data.reply.enabled', false);
        $response->assertJsonPath('data.reply.reason', ReplyCapability::UNSUPPORTED_CHANNEL);
    }

    public function test_an_allowed_viewer_on_a_supported_channel_with_outbound_enabled_may_reply(): void
    {
        $this->tenantId();
        $conversation = $this->conversation();
        // TEST CONFIG ONLY. Production ships this false and the .env key is absent.
        config(['meta.outbound.enabled' => true]);

        Sanctum::actingAs($this->viewer(mayReply: true));
        $response = $this->getJson('/api/v1/crm/conversations/'.$conversation->id)->assertOk();

        $response->assertJsonPath('data.reply.enabled', true);
        $response->assertJsonPath('data.reply.reason', null);
    }

    /**
     * Precedence, asserted directly rather than only through HTTP.
     *
     * A viewer who may not reply learns `permission_denied` even when the channel is unsupported
     * AND outbound is off — deployment state is never disclosed to someone who could not have used
     * it, which mirrors the send path's own ordering.
     */
    public function test_permission_is_reported_before_channel_and_configuration(): void
    {
        $this->tenantId();
        config(['meta.outbound.enabled' => false]);

        $capability = app(ReplyCapability::class);
        $unsupported = $this->conversation('sms', 'twilio');

        $this->assertSame(
            ReplyCapability::PERMISSION_DENIED,
            $capability->for($unsupported, $this->viewer())['reason'],
        );

        // With permission, the thread's own nature outranks the deployment flag.
        $this->assertSame(
            ReplyCapability::UNSUPPORTED_CHANNEL,
            $capability->for($unsupported, $this->viewer(mayReply: true))['reason'],
        );

        $this->assertSame(
            ReplyCapability::OUTBOUND_DISABLED,
            $capability->for($this->conversation(), $this->viewer(mayReply: true))['reason'],
        );
    }

    public function test_the_capability_never_resolves_a_credential_or_queries_the_window(): void
    {
        $this->tenantId();
        $conversation = $this->conversation();
        config(['meta.outbound.enabled' => true]);

        // A capability check must cost no queries beyond what the caller already did.
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $result = app(ReplyCapability::class)->for($conversation, $this->viewer(mayReply: true));
        $queries = \Illuminate\Support\Facades\DB::getQueryLog();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertTrue($result['enabled']);

        foreach ($queries as $query) {
            $sql = strtolower((string) $query['query']);
            $this->assertStringNotContainsString('crm_provider_credentials', $sql);
            $this->assertStringNotContainsString('crm_provider_connections', $sql);
            $this->assertStringNotContainsString('crm_provider_accounts', $sql);
            $this->assertStringNotContainsString('crm_messages', $sql);
        }
    }
}
