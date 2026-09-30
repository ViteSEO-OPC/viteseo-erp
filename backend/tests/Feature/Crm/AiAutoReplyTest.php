<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Crm\Events\InboundConversationMessageReceived;
use App\Modules\Crm\Jobs\AiAutoReplyJob;
use App\Modules\Crm\Listeners\QueueAiAutoReply;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\AutoReply;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\CrmUserAccess;
use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Models\ProviderConnection;
use App\Modules\Crm\Models\ProviderConnectionCredential;
use App\Modules\Crm\Services\AiAutoReply\AiAutoReplyService;
use App\Modules\Crm\Services\AiDraft\ReplyDraftPromptBuilder;
use App\Modules\Crm\Services\AiDraft\ReplyDraftService;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use App\Modules\Crm\Services\Inbound\InboundConversationService;
use App\Modules\Crm\Services\Outbound\OutboundIdempotency;
use App\Modules\Crm\Services\Outbound\OutboundMessagePersister;
use App\Modules\Crm\Services\Outbound\OutboundMessageRequest;
use App\Modules\Crm\Services\Outbound\OutboundMessageService;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\Support\Inbound\Meta\MetaEnvelopes;
use Tests\Support\Inbound\Meta\SignsMetaDeliveries;
use Tests\TestCase;

/**
 * Phase 3I — guarded AI auto-reply V1.
 *
 * THE PROPERTIES UNDER TEST: one inbound text → at most one model call and at most one Meta send,
 * whatever the queue, the webhook or the operator does; every guard fails closed; nothing is ever
 * retried; and no log, Activity or job payload carries a message body, a draft, a token or a key.
 *
 * ONE HTTP FAKE PER TEST, COUNTING INSIDE THE CLOSURE. `Http::fake()` merges stubs (the first match
 * wins), and Laravel records a request only once it completes — a throwing stub would let "exactly
 * one call" pass against zero. So a single closure installed in setUp() routes by host, counts into
 * properties BEFORE answering, and reads what to answer from properties a test can change.
 *
 * NO REAL HTTP. `Http::preventStrayRequests()` runs before every test; every value is synthetic.
 */
class AiAutoReplyTest extends TestCase
{
    use RefreshDatabase;
    use SignsMetaDeliveries;

    private const AI_BASE = 'https://ai.test.invalid/api/v1';

    private const AI_KEY = 'SYNTHETIC-AI-KEY-NOT-REAL-3I';

    private const MODEL = 'test-vendor/pinned-model-2024-01-01';

    private const META_HOST = 'https://graph.test.invalid';

    private const PAGE_ID = '900000000000301';

    private const IG_ID = '900000000000302';

    private const TOKEN = 'SYNTHETIC-PAGE-TOKEN-NOT-REAL-3I';

    private const BODY_CANARY = 'BODYCANARY-5521 magkano po';

    private const REPLY = 'Salamat po sa message! REPLYCANARY-8830 A member of our team will follow up shortly.';

    private int $aiCalls = 0;

    private int $metaCalls = 0;

    /** @var list<Request> */
    private array $aiRequests = [];

    /** @var \Closure(): mixed */
    private \Closure $aiAnswer;

    /** @var \Closure(int): mixed */
    private \Closure $metaAnswer;

    private ?\Closure $duringAi = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'ai_drafts.enabled' => true,
            'ai_drafts.api_key' => self::AI_KEY,
            'ai_drafts.base_url' => self::AI_BASE,
            'ai_drafts.model' => self::MODEL,
            'ai_drafts.timeout' => 15,
            'ai_drafts.referer' => null,
            'ai_drafts.app_title' => null,
            'meta.oauth.graph_host' => self::META_HOST,
            'meta.oauth.graph_version' => 'v26.0',
            'meta.app_secret' => 'SYNTHETIC-APP-SECRET-NOT-REAL-3I',
            'meta.outbound.enabled' => true,
            'meta.outbound.window_hours' => 24,
            'ai_auto_reply.enabled' => true,
            'ai_auto_reply.conversation_ids' => [],
            'ai_auto_reply.actor_user_id' => 0,
            'ai_auto_reply.debounce_seconds' => 15,
            'ai_auto_reply.min_seconds_between' => 120,
            'ai_auto_reply.max_per_conversation_hour' => 3,
            'ai_auto_reply.max_global_hour' => 30,
            'ai_auto_reply.max_trigger_age_seconds' => 600,
            'ai_auto_reply.max_reply_chars' => 1000,
        ]);

        $this->aiAnswer = fn () => Http::response(self::completion(self::REPLY), 200);
        $this->metaAnswer = fn (int $n) => Http::response(['message_id' => 'mid_ai_out_'.$n, 'recipient_id' => 'x'], 200);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::AI_BASE)) {
                $this->aiCalls++;
                $this->aiRequests[] = $request;

                if ($this->duringAi !== null) {
                    ($this->duringAi)();
                }

                return ($this->aiAnswer)();
            }

            $this->metaCalls++;

            return ($this->metaAnswer)($this->metaCalls);
        });
    }

    // ================================================================= fixtures

    private static function completion(mixed $content): array
    {
        return [
            'id' => 'gen-synthetic',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
        ];
    }

    private function tenantId(): int
    {
        $org = $this->tenant();
        CurrentOrg::set($org->id);

        return (int) $org->id;
    }

    /** Connection + account + credential for one Meta resource, in one org. */
    private function wireResource(int $orgId, string $resourceType, string $externalId): void
    {
        $connection = new ProviderConnection([
            'provider' => 'meta',
            'status' => ProviderConnection::CONNECTED,
            'external_business_id' => '900000000000999',
            'connected_at' => now()->subDay(),
        ]);
        $connection->org_id = $orgId;
        $connection->save();

        $account = new ProviderAccount([
            'provider' => 'meta',
            'resource_type' => $resourceType,
            'external_id' => $externalId,
            'label' => 'Synthetic Asset',
            'is_active' => true,
        ]);
        $account->org_id = $orgId;
        $account->connection_id = $connection->id;
        $account->save();

        $credential = new ProviderConnectionCredential;
        $credential->org_id = $orgId;
        $credential->provider_connection_id = $connection->id;
        $credential->provider_account_id = $account->id;
        $credential->credential_type = ProviderConnectionCredential::PAGE_ACCESS_TOKEN;
        $credential->access_token = self::TOKEN;
        $credential->save();
    }

    /** An active org member holding the reply grant itself (no Gate::before bypass) — the only valid sender. */
    private function actor(int $orgId, bool $member = true, bool $permission = true): User
    {
        $user = User::factory()->create();

        if ($permission) {
            $user->givePermissionTo(Permission::findOrCreate(OutboundMessageService::PERMISSION, 'web'));
        }
        // The factory attaches the first org; membership here is exactly what the test says.
        $user->orgs()->detach();
        if ($member) {
            $user->orgs()->attach($orgId, ['is_default' => true]);
        }

        return $user;
    }

    /**
     * A fully wired tenant with an allowlisted thread and a valid actor. No inbound turn yet: the
     * tests decide the id order of what they insert.
     *
     * @return array{0: int, 1: Conversation, 2: User}
     */
    private function wired(string $channel = 'messenger'): array
    {
        $org = $this->tenantId();
        $this->wireResource($org, $channel === 'instagram_dm' ? 'instagram_business_account' : 'page', $channel === 'instagram_dm' ? self::IG_ID : self::PAGE_ID);

        $conversation = $this->conversation($channel);
        $actor = $this->actor($org);

        config([
            'ai_auto_reply.conversation_ids' => [(int) $conversation->id],
            'ai_auto_reply.actor_user_id' => (int) $actor->id,
        ]);

        return [$org, $conversation, $actor];
    }

    private function conversation(string $channel = 'messenger', ?int $orgId = null): Conversation
    {
        $conversation = new Conversation([
            'provider' => 'meta',
            'channel' => $channel,
            'external_conversation_id' => 'thread_'.uniqid('', true),
            'external_contact_id' => 'contact_'.uniqid('', true),
            'status' => 'open',
            'started_at' => now()->subHours(2),
            'last_message_at' => now(),
        ]);
        if ($orgId !== null) {
            $conversation->org_id = $orgId;
        }
        $conversation->save();

        return $conversation->refresh();
    }

    private function inbound(
        Conversation $conversation,
        ?string $body = self::BODY_CANARY,
        ?Carbon $at = null,
        string $type = 'text',
        string $direction = 'inbound',
    ): ConversationMessage {
        $at ??= now()->subSeconds(20);

        $message = new ConversationMessage([
            'provider' => 'meta',
            'channel' => $conversation->channel,
            'external_message_id' => 'mid_in_'.uniqid('', true),
            'direction' => $direction,
            'sender_external_id' => $conversation->external_contact_id,
            'recipient_external_id' => self::PAGE_ID,
            'message_type' => $type,
            'body' => $body,
            'occurred_at' => $at,
            'received_at' => $at,
        ]);
        $message->conversation_id = $conversation->id;
        $message->org_id = $conversation->org_id;
        $message->idempotency_key = hash('sha256', 'in'.$conversation->id.uniqid('', true));
        $message->save();

        return $message->refresh();
    }

    private function outbound(Conversation $conversation, string $status = 'sent', ?string $failureCode = null, ?string $key = null): ConversationMessage
    {
        $message = new ConversationMessage([
            'provider' => 'meta',
            'channel' => $conversation->channel,
            'direction' => 'outbound',
            'message_type' => 'text',
            'body' => 'Operator reply',
            'delivery_status' => $status,
            'failure_code' => $failureCode,
            'occurred_at' => now(),
        ]);
        $message->conversation_id = $conversation->id;
        $message->org_id = $conversation->org_id;
        $message->idempotency_key = $key ?? hash('sha256', 'out'.$conversation->id.uniqid('', true));
        $message->save();

        return $message;
    }

    /** A finished auto-reply for an EARLIER inbound turn, created at a chosen time. */
    private function priorReply(Conversation $conversation, string $status, Carbon $createdAt): AutoReply
    {
        $trigger = $this->inbound($conversation, 'earlier', now()->subHours(3));

        $reply = new AutoReply;
        $reply->org_id = $conversation->org_id;
        $reply->conversation_id = $conversation->id;
        $reply->inbound_message_id = $trigger->id;
        $reply->status = $status;
        $reply->save();

        DB::table('crm_auto_replies')->where('id', $reply->id)->update(['created_at' => $createdAt]);

        return $reply;
    }

    /** Run the job the way a worker does: pin cleared, the job names its own org. */
    private function runJob(int $orgId, int $conversationId, int $inboundId): ?AutoReply
    {
        CurrentOrg::clear();
        AiAutoReplyJob::dispatchSync($orgId, $conversationId, $inboundId);
        CurrentOrg::set($orgId);

        return $this->reply($inboundId);
    }

    private function reply(int $inboundId): ?AutoReply
    {
        return AutoReply::query()->withoutGlobalScopes()->where('inbound_message_id', $inboundId)->first();
    }

    /**
     * @param  bool  $activity  false only where no conversation exists to carry the audit row (the
     *                          cross-org `conversation_missing` case)
     */
    private function assertSkipped(?AutoReply $reply, string $reason, bool $activity = true): void
    {
        $this->assertNotNull($reply);
        $this->assertSame(AutoReply::SKIPPED, $reply->status);
        $this->assertSame($reason, $reply->skip_reason);
        $this->assertNotNull($reply->completed_at);
        $this->assertSame(0, $this->metaCalls, 'a skipped decision never reaches Meta');

        if ($activity) {
            $this->assertActivity($reply, AutoReply::SKIPPED, skipReason: $reason);
        } else {
            $this->assertCount(0, $this->activitiesFor($reply));
        }
    }

    private function assertFailed(?AutoReply $reply, string $status, string $failureCode): void
    {
        $this->assertNotNull($reply);
        $this->assertSame($status, $reply->status);
        $this->assertSame($failureCode, $reply->failure_code);
        $this->assertNull($reply->skip_reason);
        $this->assertNotNull($reply->completed_at);
        $this->assertActivity($reply, $status, failureCode: $failureCode);
    }

    /** Exactly one audit Activity for this reply's trigger, carrying the same outcome. */
    private function assertActivity(AutoReply $reply, string $status, ?string $skipReason = null, ?string $failureCode = null): void
    {
        $activities = $this->activitiesFor($reply);
        $this->assertCount(1, $activities, 'exactly one audit Activity per decision');

        $properties = $activities[0]->properties;
        $this->assertSame($status, $properties['status']);
        $this->assertSame($skipReason, $properties['skip_reason']);
        $this->assertSame($failureCode, $properties['failure_code']);
    }

    /** @return list<Activity> filtered in PHP: JSON-path queries differ between sqlite and MariaDB */
    private function activitiesFor(AutoReply $reply): array
    {
        return Activity::query()->withoutGlobalScopes()
            ->where('event', AiAutoReplyService::ACTIVITY_EVENT)
            ->get()
            ->filter(fn (Activity $a): bool => (int) ($a->properties['inbound_message_id'] ?? 0) === (int) $reply->inbound_message_id)
            ->values()
            ->all();
    }

    /** A claim row in a chosen state for a given trigger, as a crashed or running job leaves it. */
    private function claimRow(ConversationMessage $trigger, string $status, ?Carbon $createdAt = null): AutoReply
    {
        $reply = new AutoReply;
        $reply->org_id = $trigger->org_id;
        $reply->conversation_id = $trigger->conversation_id;
        $reply->inbound_message_id = $trigger->id;
        $reply->status = $status;
        $reply->save();

        if ($createdAt !== null) {
            DB::table('crm_auto_replies')->where('id', $reply->id)->update(['created_at' => $createdAt]);
        }

        return $reply;
    }

    private function outboundRows(): int
    {
        return ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'outbound')->count();
    }

    /** @return list<string> every log line, message + encoded context */
    private function captureLogs(): \Closure
    {
        $lines = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$lines): void {
            $lines[] = $e->message.' '.json_encode($e->context);
        });

        return function () use (&$lines): array {
            return $lines;
        };
    }

    private function nowMs(): int
    {
        return (int) now()->getTimestampMs();
    }

    /** A Meta item stamped NOW — the shared fixtures carry a 2025 timestamp. */
    private function fresh(array $item): array
    {
        $item['timestamp'] = $this->nowMs();

        return $item;
    }

    private function freshEnvelope(array $envelope): array
    {
        $envelope['entry'][0]['time'] = $this->nowMs();

        return $envelope;
    }

    // ============================================================ end to end

    /** 1 — a signed Messenger text on an allowlisted thread → one model call, one send, `sent`. */
    public function test_messenger_webhook_text_gets_exactly_one_reply(): void
    {
        $this->enableMetaWebhook();
        $org = (int) $this->tenant()->id;
        $this->wireResource($org, 'page', MetaEnvelopes::PAGE_A);
        $actor = $this->actor($org);
        config(['ai_auto_reply.actor_user_id' => (int) $actor->id]);

        // First turn creates the thread; it is not allowlisted yet, so nothing happens.
        $this->postSigned($this->freshEnvelope(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [
            $this->fresh(MetaEnvelopes::textItem('mid.e2e1', self::BODY_CANARY)),
        ])))->assertOk();

        $conversation = Conversation::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(0, $this->aiCalls);
        $this->assertSame(0, AutoReply::query()->withoutGlobalScopes()->count());

        config(['ai_auto_reply.conversation_ids' => [(int) $conversation->id]]);

        $this->postSigned($this->freshEnvelope(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [
            $this->fresh(MetaEnvelopes::textItem('mid.e2e2', 'Available pa po ba?')),
        ])))->assertOk();

        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(1, $this->metaCalls);

        $trigger = ConversationMessage::query()->withoutGlobalScopes()->where('external_message_id', 'mid.e2e2')->firstOrFail();
        $reply = $this->reply((int) $trigger->id);
        $this->assertSame(AutoReply::SENT, $reply->status);
        $this->assertSame(self::MODEL, $reply->model);
        $this->assertSame(ReplyDraftPromptBuilder::AUTO_REPLY_PROMPT_VERSION, $reply->prompt_version);

        $out = ConversationMessage::query()->withoutGlobalScopes()->findOrFail($reply->outbound_message_id);
        $this->assertSame('outbound', $out->direction);
        $this->assertSame('sent', $out->delivery_status);
        $this->assertSame(self::REPLY, $out->body);

        $activity = Activity::query()->withoutGlobalScopes()->where('event', AiAutoReplyService::ACTIVITY_EVENT)->sole();
        $this->assertSame((int) $actor->id, (int) $activity->causer_id);
        $this->assertSame('sent', $activity->properties['status']);
        $this->assertStringNotContainsString('REPLYCANARY', json_encode($activity->properties));

        // The auto-reply's own send created no inbound turn and therefore no further job.
        $this->assertSame(1, AutoReply::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, ConversationMessage::query()->withoutGlobalScopes()->where('direction', 'inbound')->count());
    }

    /** 2 — the same on Instagram. */
    public function test_instagram_webhook_text_gets_exactly_one_reply(): void
    {
        $this->enableMetaWebhook();
        $org = (int) $this->tenant()->id;
        $this->wireResource($org, 'instagram_business_account', MetaEnvelopes::IG_A);
        $actor = $this->actor($org);
        config(['ai_auto_reply.actor_user_id' => (int) $actor->id]);

        $this->postSigned($this->freshEnvelope(MetaEnvelopes::instagram(MetaEnvelopes::IG_A, [
            $this->fresh(MetaEnvelopes::igTextItem('ig.e2e1')),
        ])))->assertOk();

        $conversation = Conversation::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('instagram_dm', $conversation->channel);
        config(['ai_auto_reply.conversation_ids' => [(int) $conversation->id]]);

        $this->postSigned($this->freshEnvelope(MetaEnvelopes::instagram(MetaEnvelopes::IG_A, [
            $this->fresh(MetaEnvelopes::igTextItem('ig.e2e2', 'Pwede po mag-inquire?')),
        ])))->assertOk();

        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(1, $this->metaCalls);
        $this->assertSame(AutoReply::SENT, AutoReply::query()->withoutGlobalScopes()->sole()->status);
    }

    /** 3 — a redelivered webhook is a replay: no event, no second job, no second reply. */
    public function test_a_duplicate_webhook_produces_one_reply(): void
    {
        $this->enableMetaWebhook();
        $org = (int) $this->tenant()->id;
        $this->wireResource($org, 'page', MetaEnvelopes::PAGE_A);
        $actor = $this->actor($org);
        config(['ai_auto_reply.actor_user_id' => (int) $actor->id]);

        $this->postSigned($this->freshEnvelope(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [
            $this->fresh(MetaEnvelopes::textItem('mid.dup0')),
        ])))->assertOk();
        config(['ai_auto_reply.conversation_ids' => [(int) Conversation::query()->withoutGlobalScopes()->value('id')]]);

        $envelope = $this->freshEnvelope(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [
            $this->fresh(MetaEnvelopes::textItem('mid.dup1')),
        ]));
        $this->postSigned($envelope)->assertOk();
        $this->postSigned($envelope)->assertOk();

        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(1, $this->metaCalls);
        $this->assertSame(1, AutoReply::query()->withoutGlobalScopes()->count());
    }

    /** 4 — an echo of our own send is dropped by the adapter: no row, no event, no job. */
    public function test_an_echo_never_triggers_a_reply(): void
    {
        $this->enableMetaWebhook();
        $org = (int) $this->tenant()->id;
        $this->wireResource($org, 'page', MetaEnvelopes::PAGE_A);
        config(['ai_auto_reply.conversation_ids' => range(1, 50)]);

        Queue::fake();

        $this->postSigned($this->freshEnvelope(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [
            $this->fresh(MetaEnvelopes::echoItem()),
        ])))->assertOk();

        Queue::assertNotPushed(AiAutoReplyJob::class);
        $this->assertSame(0, $this->aiCalls + $this->metaCalls);
    }

    /** 5 — non-text webhook turns (postback, attachment, mixed) queue nothing. */
    public function test_postback_and_attachment_webhooks_queue_nothing(): void
    {
        $this->enableMetaWebhook();
        $org = (int) $this->tenant()->id;
        $this->wireResource($org, 'page', MetaEnvelopes::PAGE_A);
        config(['ai_auto_reply.conversation_ids' => range(1, 50)]);

        Queue::fake();

        $this->postSigned($this->freshEnvelope(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [
            $this->fresh(MetaEnvelopes::postbackItem()),
            $this->fresh(MetaEnvelopes::attachmentItem()),
            $this->fresh(MetaEnvelopes::mixedItem()),
        ])))->assertOk();

        $this->assertGreaterThan(0, ConversationMessage::query()->withoutGlobalScopes()->count());
        Queue::assertNotPushed(AiAutoReplyJob::class);
    }

    // ============================================================ listener

    /** 6 — a qualifying turn queues exactly ONE job: ints only, debounced. */
    public function test_the_listener_queues_one_debounced_job(): void
    {
        [$org, $conversation] = $this->wired();
        Queue::fake();

        $message = $this->inbound($conversation);
        event(new InboundConversationMessageReceived($message, $conversation));

        Queue::assertPushed(AiAutoReplyJob::class, 1);
        Queue::assertPushed(AiAutoReplyJob::class, function (AiAutoReplyJob $job) use ($org, $conversation, $message): bool {
            return $job->orgId === $org
                && $job->conversationId === (int) $conversation->id
                && $job->inboundMessageId === (int) $message->id
                && $job->delay !== null
                && $job->afterCommit === true;
        });
    }

    /** @return array<string, array{string}> */
    public static function listenerRefusals(): array
    {
        return [
            'automation off' => ['automation_off'],
            'ai drafts off' => ['ai_off'],
            'ai key missing' => ['ai_no_key'],
            'meta outbound off' => ['meta_off'],
            'allowlist empty' => ['allowlist_empty'],
            'allowlist names another thread' => ['allowlist_other'],
            'unsupported channel' => ['channel'],
            'image turn' => ['image'],
            'postback turn' => ['postback'],
            'empty body' => ['empty'],
            'whitespace body' => ['blank'],
            'outbound turn' => ['outbound'],
        ];
    }

    /** 7 — every cheap filter refuses before a job exists. */
    #[DataProvider('listenerRefusals')]
    public function test_the_listener_queues_nothing_when_a_filter_fails(string $case): void
    {
        [, $conversation] = $this->wired();
        Queue::fake();

        $message = match ($case) {
            'channel' => $this->inbound($c = $this->conversation('whatsapp')),
            'image' => $this->inbound($conversation, null, null, 'image'),
            'postback' => $this->inbound($conversation, 'Get a quote', null, 'postback'),
            'empty' => $this->inbound($conversation, null),
            'blank' => $this->inbound($conversation, "  \n "),
            'outbound' => $this->inbound($conversation, 'x', null, 'text', 'outbound'),
            default => $this->inbound($conversation),
        };
        $thread = $case === 'channel' ? $c : $conversation;
        if ($case === 'channel') {
            config(['ai_auto_reply.conversation_ids' => [(int) $c->id]]);
        }

        match ($case) {
            'automation_off' => config(['ai_auto_reply.enabled' => false]),
            'ai_off' => config(['ai_drafts.enabled' => false]),
            'ai_no_key' => config(['ai_drafts.api_key' => '']),
            'meta_off' => config(['meta.outbound.enabled' => false]),
            'allowlist_empty' => config(['ai_auto_reply.conversation_ids' => []]),
            'allowlist_other' => config(['ai_auto_reply.conversation_ids' => [(int) $conversation->id + 1000]]),
            default => null,
        };

        event(new InboundConversationMessageReceived($message, $thread));

        Queue::assertNotPushed(AiAutoReplyJob::class);
        $this->assertSame(0, $this->aiCalls + $this->metaCalls);
    }

    /**
     * 8 — THE LISTENER NEVER THROWS. A dispatch failure inside ingest's after-commit callback must
     * not turn a stored message into a persistence failure: ingest still reports `created`.
     */
    public function test_a_dispatch_failure_never_breaks_ingest(): void
    {
        [$org, $conversation] = $this->wired();
        // Whichever thread the resolver picks for this turn, it is on the allowlist.
        config(['ai_auto_reply.conversation_ids' => range(1, 50)]);

        $bus = \Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('synthetic dispatch failure'));
        $this->app->instance(Dispatcher::class, $bus);
        $this->app->forgetInstance(QueueAiAutoReply::class);

        $logs = $this->captureLogs();

        $result = CurrentOrg::runAs($org, fn () => app(InboundConversationService::class)->ingest(new InboundConversationMessage(
            provider: 'meta',
            channel: 'messenger',
            externalConversationId: (string) $conversation->external_conversation_id,
            externalMessageId: 'mid_listener_throw',
            externalEventId: 'evt_listener_throw',
            senderExternalId: (string) $conversation->external_contact_id,
            recipientExternalId: self::PAGE_ID,
            messageType: 'text',
            text: self::BODY_CANARY,
            occurredAt: now()->toIso8601String(),
            rawPayload: ['synthetic' => true],
        )));

        $this->assertTrue($result->wasCreated());
        $this->assertNotNull(ConversationMessage::query()->withoutGlobalScopes()->where('external_message_id', 'mid_listener_throw')->first());

        $dispatchFailed = array_values(array_filter($logs(), fn (string $l): bool => str_starts_with($l, 'crm.ai_auto_reply.dispatch_failed')));
        $this->assertCount(1, $dispatchFailed);
        $this->assertStringContainsString('RuntimeException', $dispatchFailed[0]);
        $this->assertStringNotContainsString('synthetic dispatch failure', $dispatchFailed[0]);
        $this->assertSame(0, $this->aiCalls + $this->metaCalls);
    }

    // ============================================================ job: happy path + idempotency

    /** 9 — a direct job run on a Messenger thread sends one reply and writes the outbound row. */
    public function test_a_job_run_sends_once_and_links_the_outbound_row(): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $message = $this->inbound($conversation);

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertSame(AutoReply::SENT, $reply->status);
        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(1, $this->metaCalls);
        $this->assertNull($reply->skip_reason);
        $this->assertNull($reply->failure_code);

        $key = app(OutboundIdempotency::class)->keyFor(new OutboundMessageRequest($conversation, $actor, '', 'ai-auto:'.$message->id));
        $row = ConversationMessage::query()->withoutGlobalScopes()->where('idempotency_key', $key)->sole();
        $this->assertSame((int) $row->id, $reply->outbound_message_id);
    }

    /** 10 — running the same job twice costs one model call and one send; the second is silent. */
    public function test_a_duplicate_job_execution_sends_once(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);

        $this->runJob($org, (int) $conversation->id, (int) $message->id);
        $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(1, $this->metaCalls);
        $this->assertSame(1, AutoReply::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Activity::query()->withoutGlobalScopes()->where('event', AiAutoReplyService::ACTIVITY_EVENT)->count());
    }

    /** 11 — a second run started WHILE the first is inside the model call loses the claim. */
    public function test_a_reentrant_job_during_the_model_call_sends_once(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);

        $nested = null;
        $this->duringAi = function () use ($org, $conversation, $message, &$nested): void {
            $this->duringAi = null;
            $nested = app(AiAutoReplyService::class)->handle($org, (int) $conversation->id, (int) $message->id);
        };

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertNull($nested, 'the re-entrant run found the claim and did nothing');
        $this->assertSame(AutoReply::SENT, $reply->status);
        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(1, $this->metaCalls);
    }

    // ============================================================ job: switches and scope

    /** @return array<string, array{string}> */
    public static function switchesOff(): array
    {
        return [
            'automation off' => ['ai_auto_reply.enabled'],
            'ai drafts off' => ['ai_drafts.enabled'],
            'ai key missing' => ['ai_drafts.api_key'],
            'meta outbound off' => ['meta.outbound.enabled'],
        ];
    }

    /** 12 — any switch off at run time → skipped, no model call, no send. */
    #[DataProvider('switchesOff')]
    public function test_any_switch_off_skips_before_any_http(string $key): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);
        config([$key => $key === 'ai_drafts.api_key' ? null : false]);

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertSkipped($reply, 'auto_reply_disabled');
        $this->assertSame(0, $this->aiCalls);
    }

    /** 13 — the allowlist is authoritative at run time too; empty means nobody. */
    public function test_an_unlisted_conversation_is_skipped(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);

        config(['ai_auto_reply.conversation_ids' => []]);
        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'not_allowlisted');

        $other = $this->inbound($conversation);
        config(['ai_auto_reply.conversation_ids' => [(int) $conversation->id + 999]]);
        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $other->id), 'not_allowlisted');
        $this->assertSame(0, $this->aiCalls);
    }

    /** @return array<string, array{string, string}> */
    public static function unsuitableTriggers(): array
    {
        return [
            'unsupported channel' => ['channel', 'unsupported_channel'],
            'image' => ['image', 'unsupported_type'],
            'postback' => ['postback', 'unsupported_type'],
            'empty body' => ['empty', 'empty_body'],
            'outbound trigger' => ['outbound', 'not_inbound'],
            'wrong conversation' => ['wrong_thread', 'message_missing'],
        ];
    }

    /** 14 — the run-time copies of the listener's cheap filters. */
    #[DataProvider('unsuitableTriggers')]
    public function test_an_unsuitable_trigger_is_skipped(string $case, string $reason): void
    {
        [$org, $conversation] = $this->wired();

        if ($case === 'channel') {
            $conversation = $this->conversation('whatsapp');
            config(['ai_auto_reply.conversation_ids' => [(int) $conversation->id]]);
        }

        $message = match ($case) {
            'image' => $this->inbound($conversation, null, null, 'image'),
            'postback' => $this->inbound($conversation, 'Get a quote', null, 'postback'),
            'empty' => $this->inbound($conversation, '   '),
            'outbound' => $this->inbound($conversation, 'x', null, 'text', 'outbound'),
            'wrong_thread' => $this->inbound($this->conversation()),
            default => $this->inbound($conversation),
        };

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), $reason);
        $this->assertSame(0, $this->aiCalls);
    }

    /**
     * 15 — an inbound turn 25 h old: outside Meta's window AND past the trigger age. The trigger
     * age catches it first, so the outbound window refusal is never even reached; no send either way.
     */
    public function test_an_expired_trigger_never_sends(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation, self::BODY_CANARY, now()->subHours(25));

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'trigger_too_old');
        $this->assertSame(0, $this->aiCalls);
        $this->assertSame(0, $this->outboundRows());
    }

    /** 16 — tenant isolation: a job naming org A cannot act on org B's thread. */
    public function test_a_job_cannot_reach_another_orgs_conversation(): void
    {
        [$org] = $this->wired();
        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        $foreign = $this->conversation('messenger', (int) $orgB->id);
        $message = CurrentOrg::runAs((int) $orgB->id, fn () => $this->inbound($foreign));
        config(['ai_auto_reply.conversation_ids' => [(int) $foreign->id]]);

        $reply = $this->runJob($org, (int) $foreign->id, (int) $message->id);

        $this->assertSkipped($reply, 'conversation_missing', activity: false);
        $this->assertSame($org, (int) $reply->org_id);
        $this->assertSame(0, $this->aiCalls);
    }

    /** 16b — an org that lost the CRM feature or its subscription never auto-replies. */
    public function test_an_org_without_crm_or_subscription_is_ineligible(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);
        Entitlements::disable($org, ['crm']);

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'org_ineligible');

        Entitlements::enable($org, ['crm']);
        Subscription::query()->where('org_id', $org)->delete();
        $other = $this->inbound($conversation);

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $other->id), 'org_ineligible');
        $this->assertSame(0, $this->aiCalls);
    }

    /** 17 — and the configured actor must belong to the conversation's own org. */
    public function test_an_actor_from_another_org_is_invalid(): void
    {
        [, , $actor] = $this->wired();
        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        // A fully eligible second tenant, so the refusal is about the actor and nothing else.
        Entitlements::enable((int) $orgB->id, ['crm']);
        Subscription::query()->create([
            'org_id' => $orgB->id,
            'plan' => 'full',
            'monthly_price' => 0,
            'seat_limit' => 500,
            'status' => Subscription::ACTIVE,
            'current_period_end' => now()->addYears(5),
        ]);
        $foreign = $this->conversation('messenger', (int) $orgB->id);
        $message = CurrentOrg::runAs((int) $orgB->id, fn () => $this->inbound($foreign));
        config(['ai_auto_reply.conversation_ids' => [(int) $foreign->id], 'ai_auto_reply.actor_user_id' => (int) $actor->id]);

        $this->assertSkipped($this->runJob((int) $orgB->id, (int) $foreign->id, (int) $message->id), 'actor_invalid');
        $this->assertSame(0, $this->aiCalls);
    }

    // ============================================================ job: thread-state guards

    /** 18 — a newer inbound turn supersedes an older trigger; the newer one is answered. */
    public function test_a_newer_inbound_supersedes_the_older_trigger(): void
    {
        [$org, $conversation] = $this->wired();
        $first = $this->inbound($conversation);
        $second = $this->inbound($conversation, 'follow-up');

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $first->id), 'superseded');
        $this->assertSame(AutoReply::SENT, $this->runJob($org, (int) $conversation->id, (int) $second->id)->status);
        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(1, $this->metaCalls);
    }

    /** 19 — an operator replied after the trigger: the human has it. */
    public function test_an_operator_reply_before_the_job_skips(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);
        $this->outbound($conversation);

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'operator_replied');
        $this->assertSame(0, $this->aiCalls);
    }

    /** 20 — an operator replying DURING the model call stops the send. */
    public function test_an_operator_reply_during_the_model_call_skips_before_send(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);
        $this->duringAi = function () use ($conversation): void {
            $this->outbound($conversation);
        };

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertSkipped($reply, 'operator_replied_before_send');
        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(self::MODEL, $reply->model);
    }

    /** 21 — the automation switched off during the model call stops the send. */
    public function test_a_switch_turned_off_during_the_model_call_skips_before_send(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);
        $this->duringAi = fn () => config(['ai_auto_reply.enabled' => false]);

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'auto_reply_disabled_before_send');
        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(0, $this->outboundRows());
    }

    /** 22 — an earlier send whose outcome is unknown blocks automation until an operator resolves it. */
    public function test_an_unresolved_outbound_blocks_the_reply(): void
    {
        [$org, $conversation] = $this->wired();
        $this->outbound($conversation, 'pending', 'delivery_unknown');
        $message = $this->inbound($conversation);

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'outbound_unresolved');
        $this->assertSame(0, $this->aiCalls);
    }

    /** 23 — another auto-reply for this thread is still running. */
    public function test_another_auto_reply_in_flight_skips(): void
    {
        [$org, $conversation] = $this->wired();
        $this->priorReply($conversation, AutoReply::DRAFTING, now()->subSeconds(5));
        $message = $this->inbound($conversation);

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'in_flight');
        $this->assertSame(0, $this->aiCalls);
    }

    /** 24 — a reply was sent within min_seconds_between. */
    public function test_cooldown_skips(): void
    {
        [$org, $conversation] = $this->wired();
        $this->priorReply($conversation, AutoReply::SENT, now()->subSeconds(30));
        $message = $this->inbound($conversation);

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'cooldown');
        $this->assertSame(0, $this->aiCalls);
    }

    /** 25 — the per-conversation hourly cap counts failures too (each cost a model call). */
    public function test_the_conversation_hour_cap_skips(): void
    {
        [$org, $conversation] = $this->wired();
        foreach ([AutoReply::SENT, AutoReply::FAILED, AutoReply::AMBIGUOUS] as $status) {
            $this->priorReply($conversation, $status, now()->subMinutes(20));
        }
        $message = $this->inbound($conversation);

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'conversation_hour_cap');
        $this->assertSame(0, $this->aiCalls);
    }

    /** 26 — the global cap counts every org's replies. */
    public function test_the_global_hour_cap_counts_every_org(): void
    {
        [$org, $conversation] = $this->wired();
        config(['ai_auto_reply.max_global_hour' => 2]);

        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        $foreign = $this->conversation('messenger', (int) $orgB->id);
        CurrentOrg::runAs((int) $orgB->id, function () use ($foreign): void {
            $this->priorReply($foreign, AutoReply::SENT, now()->subMinutes(10));
            $this->priorReply($foreign, AutoReply::FAILED, now()->subMinutes(10));
        });
        $message = $this->inbound($conversation);

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'global_hour_cap');
        $this->assertSame(0, $this->aiCalls);
    }

    // ============================================================ job: actor

    /** @return array<string, array{string}> */
    public static function invalidActors(): array
    {
        return [
            'unset' => ['unset'],
            'nonexistent' => ['nonexistent'],
            'super_admin' => ['super_admin'],
            'not an org member' => ['not_member'],
            'inactive account' => ['inactive'],
            'lacks the reply permission' => ['no_permission'],
            'crm suspended' => ['suspended'],
        ];
    }

    /** 27 — only an active, unsuspended org member with a real reply grant may be the sender. */
    #[DataProvider('invalidActors')]
    public function test_an_invalid_actor_is_refused_before_any_http(string $case): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);

        $actorId = match ($case) {
            'unset' => 0,
            'nonexistent' => 999999,
            'super_admin' => (function () use ($org): int {
                $user = $this->actor($org);
                $user->assignRole('super_admin');

                return (int) $user->id;
            })(),
            'not_member' => (int) $this->actor($org, member: false)->id,
            'inactive' => (function () use ($org): int {
                $user = $this->actor($org);
                $user->forceFill(['status' => User::STATUS_INACTIVE])->save();

                return (int) $user->id;
            })(),
            'no_permission' => (int) $this->actor($org, permission: false)->id,
            'suspended' => (function () use ($org): int {
                $user = $this->actor($org);
                $access = new CrmUserAccess(['suspended_at' => now()]);
                $access->user_id = $user->id;
                $access->org_id = $org;
                $access->save();

                return (int) $user->id;
            })(),
        };
        config(['ai_auto_reply.actor_user_id' => $actorId]);

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertSkipped($reply, 'actor_invalid');
        $this->assertSame(0, $this->aiCalls);
        $this->assertNull(Activity::query()->withoutGlobalScopes()->where('event', AiAutoReplyService::ACTIVITY_EVENT)->sole()->causer_id);
    }

    // ============================================================ job: model failures + output

    /** @return array<string, array{string, string}> */
    public static function modelFailures(): array
    {
        return [
            'timeout' => ['timeout', 'ai_timeout'],
            '500' => ['500', 'ai_upstream_error'],
            '401' => ['401', 'ai_auth_failed'],
            '429' => ['429', 'ai_rate_limited'],
            'empty completion' => ['empty', 'ai_invalid_response'],
            '200 non-JSON body' => ['non_json', 'ai_invalid_response'],
            '200 with an error object' => ['error_object', 'ai_invalid_response'],
            'content null' => ['content_null', 'ai_invalid_response'],
            'content array' => ['content_array', 'ai_invalid_response'],
            'choices missing' => ['no_choices', 'ai_invalid_response'],
        ];
    }

    /** 28 — a model failure is terminal: `failed`, one model call, no Meta call, no outbound row. */
    #[DataProvider('modelFailures')]
    public function test_a_model_failure_never_sends(string $answer, string $code): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);
        $this->aiAnswer = fn () => match ($answer) {
            'timeout' => throw new ConnectionException('synthetic timeout'),
            '500' => Http::response(['error' => 'x'], 500),
            '401' => Http::response(['error' => 'x'], 401),
            '429' => Http::response(['error' => 'x'], 429),
            'empty' => Http::response(self::completion(''), 200),
            'non_json' => Http::response('<html>gateway says hi</html>', 200, ['Content-Type' => 'text/html']),
            // A VALID content next to the error: the error object alone must be enough to refuse.
            'error_object' => Http::response(self::completion(self::REPLY) + ['error' => ['code' => 502, 'message' => 'x']], 200),
            'content_null' => Http::response(self::completion(null), 200),
            'content_array' => Http::response(self::completion([['type' => 'text', 'text' => self::REPLY]]), 200),
            'no_choices' => Http::response(['id' => 'gen-synthetic', 'usage' => ['prompt_tokens' => 1]], 200),
        };

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertFailed($reply, AutoReply::FAILED, $code);
        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(0, $this->metaCalls);
        $this->assertSame(0, $this->outboundRows());
    }

    /** @return array<string, array{string}> */
    public static function rejectedOutputs(): array
    {
        return [
            'https link' => ['See https://example.test for details'],
            'http link' => ['See http://example.test'],
            'www' => ['Visit www.example.test po'],
            'email' => ['Email us at sales@example.test'],
            'mustache placeholder' => ['Hi {{name}}, thanks!'],
            'bracket placeholder' => ['Hi [Customer Name], thanks!'],
            'too long' => [str_repeat('a', 1001)],
            'too short' => ['k'],
            'only quotes' => ['""'],
            'bare domain' => ['Visit viteseo.ph po'],
            'scheme-less path' => ['See example.com/pricing'],
            'm.me link' => ['Message us on m.me/viteseo'],
            'fullwidth scheme' => ["See \u{FF48}\u{FF54}\u{FF54}\u{FF50}\u{FF53}\u{FF1A}\u{FF0F}\u{FF0F}example po"],
            'zero-width only' => ["\u{200B}\u{200B}\u{200B}"],
            'single-brace placeholder' => ['Hi {customer_name}, thanks!'],
            'JSON output' => ['{"reply": "Salamat po!"}'],
        ];
    }

    /** 29 — output validation rejects rather than repairs; nothing reaches Meta. */
    #[DataProvider('rejectedOutputs')]
    public function test_invalid_model_output_is_never_sent(string $output): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);
        $this->aiAnswer = fn () => Http::response(self::completion($output), 200);

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertFailed($reply, AutoReply::FAILED, 'invalid_output');
        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(0, $this->metaCalls);
        $this->assertSame(0, $this->outboundRows());
    }

    /** 30 — one wrapping pair of quotes is stripped; the rest is sent verbatim. */
    public function test_wrapping_quotes_are_stripped_once(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);
        $this->aiAnswer = fn () => Http::response(self::completion("\u{201C}Salamat po!\u{201D}"), 200);

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertSame(AutoReply::SENT, $reply->status);
        $this->assertSame('Salamat po!', ConversationMessage::query()->withoutGlobalScopes()->findOrFail($reply->outbound_message_id)->body);
        $this->assertSame('"a" and "b"', AiAutoReplyService::sanitize('""a" and "b""', 1000));
        $this->assertSame(1000, mb_strlen((string) AiAutoReplyService::sanitize(str_repeat('é', 1000), 1000)));
    }

    // ============================================================ job: Meta outcomes

    /** @return array<string, array{string, string}> */
    public static function ambiguousMeta(): array
    {
        return [
            'timeout' => ['timeout', 'send_delivery_unknown'],
            '500' => ['500', 'send_provider_unavailable'],
            'no message id' => ['no_id', 'send_delivery_unknown'],
        ];
    }

    /** 31 — Meta may have delivered it: `ambiguous`, and a re-run never sends again. */
    #[DataProvider('ambiguousMeta')]
    public function test_an_ambiguous_send_is_never_repeated(string $answer, string $code): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);
        $this->metaAnswer = fn (int $n) => match ($answer) {
            'timeout' => throw new ConnectionException('synthetic timeout'),
            '500' => Http::response(['error' => ['code' => 1]], 500),
            'no_id' => Http::response(['recipient_id' => 'x'], 200),
        };

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertFailed($reply, AutoReply::AMBIGUOUS, $code);
        $this->assertNotNull($reply->outbound_message_id);
        $this->assertSame(1, $this->metaCalls);
        // Derived from the outbound row, not the result object: the row is still unresolved, so
        // e.g. a 200 without a message_id can never be recorded as `sent`.
        $out = ConversationMessage::query()->withoutGlobalScopes()->findOrFail($reply->outbound_message_id);
        $this->assertSame('pending', $out->delivery_status);
        $this->assertSame($code, 'send_'.$out->failure_code);

        // A re-run loses the claim.
        $this->runJob($org, (int) $conversation->id, (int) $message->id);
        $this->assertSame(1, $this->metaCalls);

        // Even with the claim row gone, the thread's own later outbound row blocks it (the spec's
        // `operator_replied` is "any outbound row after the trigger", which includes this one).
        AutoReply::query()->withoutGlobalScopes()->delete();
        $again = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertSame(AutoReply::SKIPPED, $again->status);
        $this->assertSame('operator_replied', $again->skip_reason);
        $this->assertSame(1, $this->metaCalls);
        $this->assertSame(1, $this->aiCalls);
    }

    /**
     * 32 — a DEFINITIVE Meta failure leaves a re-claimable FAILED outbound row (the persister would
     * send again on a replay of the same key). A re-run still must not send: the claim stops it,
     * and with the claim row gone the thread-state guards stop it before the send is ever built.
     */
    public function test_a_definitive_send_failure_is_never_retried(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);
        $this->metaAnswer = fn (int $n) => Http::response(['error' => ['message' => 'synthetic', 'type' => 'OAuthException', 'code' => 100]], 400);

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertFailed($reply, AutoReply::FAILED, 'send_invalid_request');
        $this->assertSame('failed', ConversationMessage::query()->withoutGlobalScopes()->findOrFail($reply->outbound_message_id)->delivery_status);
        $this->assertSame(1, $this->metaCalls);

        $this->assertNull(app(AiAutoReplyService::class)->handle($org, (int) $conversation->id, (int) $message->id));
        $this->assertSame(1, $this->metaCalls);

        AutoReply::query()->withoutGlobalScopes()->delete();
        config(['ai_auto_reply.max_per_conversation_hour' => 100]);

        $again = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertSame(AutoReply::SKIPPED, $again->status);
        $this->assertSame('operator_replied', $again->skip_reason);
        $this->assertSame(1, $this->metaCalls);
        $this->assertSame(1, $this->aiCalls);
    }

    /**
     * 32b — `already_attempted`, the last fence: a crm_messages row already carries this trigger's
     * idempotency key. Unreachable through the normal flow (the row would be newer than the trigger
     * and `operator_replied` fires first), so it is staged here with a row OLDER than the trigger.
     */
    public function test_an_existing_row_with_the_reply_key_is_already_attempted(): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $next = (int) ConversationMessage::query()->withoutGlobalScopes()->max('id') + 2;
        $key = app(OutboundIdempotency::class)->keyFor(new OutboundMessageRequest($conversation, $actor, '', 'ai-auto:'.$next));
        $this->outbound($conversation, 'failed', 'policy_violation', $key);
        $message = $this->inbound($conversation);
        $this->assertSame($next, (int) $message->id, 'fixture precondition: the predicted trigger id');

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'already_attempted');
        $this->assertSame(0, $this->aiCalls);
    }

    /** 33 — the outbound service's own refusals (e.g. no credential) are `failed`, not retried. */
    public function test_an_outbound_refusal_is_recorded_as_failed(): void
    {
        $org = $this->tenantId();
        $conversation = $this->conversation();
        $actor = $this->actor($org);
        config(['ai_auto_reply.conversation_ids' => [(int) $conversation->id], 'ai_auto_reply.actor_user_id' => (int) $actor->id]);
        $message = $this->inbound($conversation);

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertFailed($reply, AutoReply::FAILED, 'send_resource_unmapped');
        $this->assertNull($reply->outbound_message_id);
        $this->assertSame(0, $this->outboundRows());
        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(0, $this->metaCalls);
    }

    /** 34 — an exception thrown by the send is recorded as ambiguous and never re-raised. */
    public function test_an_exception_from_the_send_is_ambiguous_and_swallowed(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);

        // GraphMetaSender maps only ConnectionException; anything else escapes OutboundMessageService.
        $this->metaAnswer = fn (int $n) => throw new RuntimeException('synthetic transport explosion');

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertFailed($reply, AutoReply::AMBIGUOUS, 'exception');
        $this->assertNotNull($reply->outbound_message_id, 'the claimed outbound row is linked for the operator');
        $this->assertSame(1, $this->metaCalls);

        $this->runJob($org, (int) $conversation->id, (int) $message->id);
        $this->assertSame(1, $this->metaCalls, 'never re-sent');
    }

    /** 35 — anything else thrown inside the job is recorded on the claim and swallowed. */
    public function test_an_unexpected_exception_is_recorded_and_never_rethrown(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);

        $throwing = \Mockery::mock(ReplyDraftService::class);
        $throwing->shouldReceive('draft')->once()->andThrow(new RuntimeException('synthetic draft explosion'));
        $this->app->instance(ReplyDraftService::class, $throwing);

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        // Recorded on the row this run claimed, from the in-flight state the DB actually holds.
        $this->assertFailed($reply, AutoReply::FAILED, 'exception');
        $this->assertSame(0, $this->metaCalls);
        $this->assertSame(0, $this->outboundRows());
    }

    // ============================================================ prompt

    /** 36 — the auto-reply prompt is used for auto-replies; the draft prompt is untouched. */
    public function test_the_auto_reply_uses_its_own_prompt_and_drafts_keep_theirs(): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);

        $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $sent = $this->aiRequests[0]->data()['messages'];
        $this->assertSame(ReplyDraftPromptBuilder::autoReplySystemPrompt(), $sent[0]['content']);
        $this->assertStringContainsString('NO human review', $sent[0]['content']);
        $this->assertStringNotContainsString('for a human customer-service agent to review', $sent[0]['content']);

        // The default purpose is still the Phase 4A-lite draft prompt, byte for byte.
        CurrentOrg::set($org);
        app(ReplyDraftService::class)->draft($conversation->refresh(), 1);
        $draft = $this->aiRequests[1]->data()['messages'];
        $this->assertSame(ReplyDraftPromptBuilder::systemPrompt(), $draft[0]['content']);
        $this->assertStringContainsString("Draft the business's next reply.", $draft[1]['content']);

        $built = (new ReplyDraftPromptBuilder)->build([$message]);
        $this->assertSame(ReplyDraftPromptBuilder::systemPrompt(), $built[0]['content']);
        $this->assertSame(1, ReplyDraftPromptBuilder::promptVersionFor(ReplyDraftPromptBuilder::PURPOSE_DRAFT));
    }

    // ============================================================ payload + settings

    /** 37 — the queued payload is three integers; no model, no body. */
    public function test_the_job_payload_is_integers_only(): void
    {
        $job = new AiAutoReplyJob(11, 22, 33);
        $serialized = serialize($job);

        $this->assertStringNotContainsString('body', $serialized);
        $this->assertStringNotContainsString('Conversation', $serialized);
        $this->assertNotContains(SerializesModels::class, class_uses_recursive($job));

        foreach ((new \ReflectionClass($job))->getConstructor()->getParameters() as $parameter) {
            $this->assertSame('int', (string) $parameter->getType());
        }
    }

    /** 38 — one try, no backoff, killed well inside retry_after, failed on timeout. */
    public function test_the_job_never_retries_and_times_out_inside_retry_after(): void
    {
        $job = new AiAutoReplyJob(1, 2, 3);

        $this->assertSame(1, $job->tries);
        $this->assertSame(1, $job->maxExceptions);
        $this->assertTrue($job->failOnTimeout);
        $this->assertLessThan((int) config('queue.connections.database.retry_after', 90), $job->timeout);
        $this->assertFalse(property_exists($job, 'backoff') && $job->backoff !== null, 'no backoff');
        $this->assertFalse(method_exists($job, 'backoff'));
        $this->assertFalse(method_exists($job, 'retryUntil'));
    }

    // ============================================================ logging

    /** 39 — no log line carries the customer's text, the reply, the model key or the page token. */
    public function test_logs_never_carry_bodies_drafts_or_secrets(): void
    {
        [$org, $conversation] = $this->wired();
        $logs = $this->captureLogs();

        $message = $this->inbound($conversation);
        $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $failing = $this->inbound($conversation, self::BODY_CANARY);
        config(['ai_auto_reply.min_seconds_between' => 0]);
        $this->metaAnswer = fn (int $n) => throw new ConnectionException('synthetic timeout '.self::TOKEN);
        $this->assertSame(AutoReply::AMBIGUOUS, $this->runJob($org, (int) $conversation->id, (int) $failing->id)->status);
        $this->assertSame(2, $this->metaCalls);

        $lines = $logs();
        $this->assertNotEmpty(array_filter($lines, fn (string $l): bool => str_starts_with($l, 'crm.ai_auto_reply.sent')));
        foreach ($lines as $line) {
            foreach (['BODYCANARY', 'REPLYCANARY', self::AI_KEY, self::TOKEN] as $needle) {
                $this->assertStringNotContainsString($needle, $line);
            }
        }

        foreach (Activity::query()->withoutGlobalScopes()->where('event', AiAutoReplyService::ACTIVITY_EVENT)->get() as $activity) {
            $encoded = json_encode($activity->properties).$activity->description;
            $this->assertStringNotContainsString('BODYCANARY', $encoded);
            $this->assertStringNotContainsString('REPLYCANARY', $encoded);
        }
    }

    // ============================================================ review fixes: actor + org before send

    /** @return array<string, array{string, string}> */
    public static function changedDuringAi(): array
    {
        return [
            'actor made inactive' => ['inactive', 'actor_invalid_before_send'],
            'actor removed from the org' => ['not_member', 'actor_invalid_before_send'],
            'reply permission revoked' => ['revoked', 'actor_invalid_before_send'],
            'actor made super_admin' => ['super_admin', 'actor_invalid_before_send'],
            'org lost the crm feature' => ['org_crm_off', 'org_ineligible_before_send'],
        ];
    }

    /**
     * 40 (B1) — the actor and the org are re-validated FRESH under the lock before the send: a
     * change made while the model was drafting stops it. The model was paid for; Meta never is.
     */
    #[DataProvider('changedDuringAi')]
    public function test_an_actor_or_org_change_during_the_model_call_skips_before_send(string $case, string $reason): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $message = $this->inbound($conversation);

        $this->duringAi = function () use ($case, $org, $actor): void {
            // A separate instance, as another request would hold: nothing in the job's memory changes.
            $other = User::query()->findOrFail($actor->id);

            match ($case) {
                'inactive' => $other->forceFill(['status' => User::STATUS_INACTIVE])->save(),
                'not_member' => DB::table('org_user')->where('user_id', $actor->id)->where('org_id', $org)->delete(),
                'revoked' => $other->revokePermissionTo(OutboundMessageService::PERMISSION),
                'super_admin' => $other->assignRole('super_admin'),
                'org_crm_off' => Entitlements::disable($org, ['crm']),
            };
        };

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertSkipped($reply, $reason);
        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(0, $this->metaCalls);
        $this->assertSame(0, $this->outboundRows());
        $this->assertNull($reply->outbound_message_id);

        $causer = $this->activitiesFor($reply)[0]->causer_id;
        if ($reason === 'actor_invalid_before_send') {
            $this->assertNull($causer, 'an actor that is no longer valid is not named as the causer');
        } else {
            $this->assertSame((int) $actor->id, (int) $causer);
        }
    }

    // ============================================================ review fixes: output validation

    /** 41 (B2/B3) — every rejected pattern, checked directly on the pure validator. */
    public function test_sanitize_rejects_links_placeholders_and_artifacts(): void
    {
        $rejected = [
            'bare domain' => 'Visit viteseo.ph for details',
            'bare .com' => 'Go to example.com',
            'scheme-less path' => 'See example.com/pricing po',
            'm.me' => 'Chat us at m.me/viteseo',
            'wa.me' => 'WhatsApp wa.me/639171234567',
            'bit.ly' => 'Short link bit.ly/x',
            'subdomain' => 'Try shop.viteseo.ph/cart',
            'uppercase domain' => 'Visit VITESEO.PH',
            'https' => 'See https://example.test',
            'spaced scheme' => 'See https : // example',
            'ftp' => 'ftp://files.example.test',
            'www' => 'Visit www.example.test',
            'fullwidth scheme' => "See \u{FF48}\u{FF54}\u{FF54}\u{FF50}\u{FF53}\u{FF1A}\u{FF0F}\u{FF0F}x po",
            'fullwidth www' => "Visit \u{FF57}\u{FF57}\u{FF57}\u{FF0E}example po",
            'fullwidth domain' => "Visit viteseo\u{FF0E}ph po",
            'zero-width inside domain' => "Visit exa\u{200B}mple.com po",
            'tel:' => 'Call tel:+639171234567',
            'mailto:' => 'Write mailto:sales',
            'email' => 'Email sales@example.test',
            'single-brace placeholder' => 'Hi {customer_name}, thanks!',
            'mustache' => 'Hi {{name}}',
            'dollar-brace' => 'Hi ${name}, thanks!',
            'angle NAME' => 'Hi <NAME>, thanks!',
            'angle name' => 'Hi <name>, thanks!',
            'printf %s' => 'Hi %s, thanks!',
            'printf %d' => 'You have %d new items',
            'printf positional' => 'Hi %1$s, thanks!',
            'square bracket' => 'Hi [Customer Name]',
            'JSON object' => '{"reply": "Salamat po!"}',
            'JSON array' => '["Salamat po!"]',
            'zero-width only' => "\u{200B}\u{200C}\u{200D}\u{FEFF}\u{2060}",
            'zero-width padded single char' => "\u{200B}k\u{200B}",
            'whitespace only' => "   \n\t ",
            'invalid UTF-8' => "Salamat \xC3\x28 po",
            'youtu.be' => 'Watch youtu.be/XYZ po',
            'lnkd.in' => 'Profile lnkd.in/x',
            'forms.gle' => 'Fill forms.gle/x',
            'linktr.ee' => 'All links linktr.ee/x',
            'rb.gy' => 'Short rb.gy/x',
            'bit.do' => 'Short bit.do/x',
            'discord.gg' => 'Join discord.gg/x',
            '.de' => 'See example.de',
            '.ru path' => 'See evil.ru/x',
            'IPv4 path' => 'Login at 192.168.1.1/login',
            'accented IDN' => 'Visit café.com',
            'accent-glued domain' => 'Visit évil.com',
            'cyrillic lookalike' => "Visit \u{0435}\u{0445}ample.com",
            'ideographic dot' => "Visit example\u{3002}com",
            'halfwidth ideographic dot' => "Visit example\u{FF61}com",
            'trailing-dot domain' => 'Visit evil.com.',
        ];

        foreach ($rejected as $label => $output) {
            $this->assertNull(AiAutoReplyService::sanitize($output, 1000), 'must reject: '.$label);
        }

        $accepted = [
            "Thanks! We'll get back to you soon.",
            'Our team will follow up.',
            'Salamat po! e.g. the basic plan, i.e. monthly. Mr. Cruz will call you.',
            'It costs ₱1,500.00, about 3.5% less than last year.',
            'Hotel: we can help with that po.',
            'We give 100% support, 7 days a week.',
        ];

        foreach ($accepted as $output) {
            $this->assertSame($output, AiAutoReplyService::sanitize($output, 1000), 'must accept: '.$output);
        }
    }

    // ============================================================ review fixes: config parsing

    private const ENV_KEYS = ['AI_AUTO_REPLY_CONVERSATION_IDS', 'AI_AUTO_REPLY_ENABLED', 'AI_AUTO_REPLY_ACTOR_USER_ID'];

    /**
     * Evaluate config/ai_auto_reply.php itself under the given env (a key absent from $vars is
     * UNSET in every store env() reads), restoring the process env afterwards.
     *
     * @param  array<string, string>  $vars
     * @return array<string, mixed>
     */
    private function configUnderEnv(array $vars): array
    {
        $saved = [];
        foreach (self::ENV_KEYS as $key) {
            $saved[$key] = [
                array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
                array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
                getenv($key),
            ];
        }

        try {
            foreach (self::ENV_KEYS as $key) {
                if (array_key_exists($key, $vars)) {
                    $_SERVER[$key] = $_ENV[$key] = $vars[$key];
                    putenv($key.'='.$vars[$key]);
                } else {
                    unset($_SERVER[$key], $_ENV[$key]);
                    putenv($key);
                }
            }

            return require base_path('config/ai_auto_reply.php');
        } finally {
            foreach ($saved as $key => [$server, $env, $process]) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
                if ($env !== null) {
                    $_ENV[$key] = $env;
                }
                if ($process !== false) {
                    putenv($key.'='.$process);
                }
            }
        }
    }

    /** 42 (C1) — the allowlist env value fails closed: junk narrows it, never widens it. */
    public function test_the_allowlist_env_value_is_parsed_fail_closed(): void
    {
        $unset = $this->configUnderEnv([]);
        $this->assertSame([], $unset['conversation_ids']);
        $this->assertFalse($unset['enabled']);
        $this->assertSame(0, $unset['actor_user_id']);

        foreach (['', ' , ', '*', 'all', '12abc', '-3', '0', '1.5', ' ,,, '] as $value) {
            $this->assertSame([], $this->configUnderEnv(['AI_AUTO_REPLY_CONVERSATION_IDS' => $value])['conversation_ids'], 'value: '.var_export($value, true));
        }

        $this->assertSame([5, 7], $this->configUnderEnv(['AI_AUTO_REPLY_CONVERSATION_IDS' => '5, 5 ,7'])['conversation_ids']);
        $this->assertSame([5], $this->configUnderEnv(['AI_AUTO_REPLY_CONVERSATION_IDS' => '5,*,-3,12abc'])['conversation_ids']);

        foreach (['off', 'no', 'false', '0', 'disabled', 'ture', ''] as $value) {
            $this->assertFalse($this->configUnderEnv(['AI_AUTO_REPLY_ENABLED' => $value])['enabled'], 'enabled must stay off for: '.var_export($value, true));
        }

        $on = $this->configUnderEnv(['AI_AUTO_REPLY_ENABLED' => 'true', 'AI_AUTO_REPLY_ACTOR_USER_ID' => '42']);
        $this->assertTrue($on['enabled']);
        $this->assertSame(42, $on['actor_user_id']);

        // Nothing leaked into the process: unset again once the helper returns.
        $this->assertSame([], $this->configUnderEnv([])['conversation_ids']);
    }

    // ============================================================ review fixes: switch flipped after dispatch

    /** @return array<string, array{string, string}> */
    public static function flippedAfterDispatch(): array
    {
        return [
            'automation off' => ['automation_off', 'auto_reply_disabled'],
            'ai drafts off' => ['ai_off', 'auto_reply_disabled'],
            'meta outbound off' => ['meta_off', 'auto_reply_disabled'],
            'allowlist emptied' => ['allowlist_empty', 'not_allowlisted'],
            'actor made inactive' => ['actor_inactive', 'actor_invalid'],
        ];
    }

    /**
     * 43 (C2) — the real listener queues the job; a kill switch flipped while it waits in the queue
     * is honoured when the (serialized, re-hydrated) job finally runs.
     */
    #[DataProvider('flippedAfterDispatch')]
    public function test_a_switch_flipped_after_dispatch_is_honoured_at_run_time(string $case, string $reason): void
    {
        [$org, $conversation, $actor] = $this->wired();
        Queue::fake();

        $message = $this->inbound($conversation);
        event(new InboundConversationMessageReceived($message, $conversation));

        Queue::assertPushed(AiAutoReplyJob::class, 1);
        $queued = Queue::pushed(AiAutoReplyJob::class)->first();

        match ($case) {
            'automation_off' => config(['ai_auto_reply.enabled' => false]),
            'ai_off' => config(['ai_drafts.enabled' => false]),
            'meta_off' => config(['meta.outbound.enabled' => false]),
            'allowlist_empty' => config(['ai_auto_reply.conversation_ids' => []]),
            'actor_inactive' => $actor->forceFill(['status' => User::STATUS_INACTIVE])->save(),
        };

        /** @var AiAutoReplyJob $job */
        $job = unserialize(serialize($queued));
        CurrentOrg::clear();
        $this->app->call([$job, 'handle']);
        CurrentOrg::set($org);

        $this->assertSkipped($this->reply((int) $message->id), $reason);
        $this->assertSame(0, $this->aiCalls);
        $this->assertSame(0, $this->metaCalls);
        $this->assertSame(0, $this->outboundRows());
    }

    // ============================================================ review fixes: Meta window + codes

    /**
     * 44 (C4) — with the trigger-age guard widened past Meta's window, a 25 h old trigger reaches
     * the outbound service's own window refusal: failed, no Meta call, no outbound row. The model
     * WAS called once (the window is only checked at send time) — the cost of a misconfiguration,
     * not a send.
     */
    public function test_a_trigger_past_the_messaging_window_is_refused_by_the_outbound_service(): void
    {
        [$org, $conversation] = $this->wired();
        config(['ai_auto_reply.max_trigger_age_seconds' => 100000]);
        $message = $this->inbound($conversation, self::BODY_CANARY, now()->subHours(25));

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertFailed($reply, AutoReply::FAILED, 'send_outside_messaging_window');
        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(0, $this->metaCalls);
        $this->assertSame(0, $this->outboundRows());
        $this->assertNull($reply->outbound_message_id);
    }

    // ============================================================ review fixes: log redaction

    /** @return array<string, array{string}> */
    public static function redactedFailures(): array
    {
        return [
            'invalid output' => ['invalid_output'],
            'model 500' => ['ai_500'],
            'unexpected exception' => ['exception'],
        ];
    }

    /** 45 (C7) — failure paths log codes and classes, never the rejected draft or a message. */
    #[DataProvider('redactedFailures')]
    public function test_failure_paths_never_log_the_draft_or_an_exception_message(string $case): void
    {
        [$org, $conversation] = $this->wired();
        $message = $this->inbound($conversation);
        $canary = 'LEAKCANARY-7731';
        $logs = $this->captureLogs();

        match ($case) {
            'invalid_output' => $this->aiAnswer = fn () => Http::response(self::completion($canary.' see https://x.test'), 200),
            'ai_500' => $this->aiAnswer = fn () => Http::response(['error' => ['message' => $canary]], 500),
            'exception' => (function () use ($canary): void {
                $throwing = \Mockery::mock(ReplyDraftService::class);
                $throwing->shouldReceive('draft')->once()->andThrow(new RuntimeException($canary));
                $this->app->instance(ReplyDraftService::class, $throwing);
            })(),
        };

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertSame(AutoReply::FAILED, $reply->status);
        $this->assertSame(match ($case) {
            'invalid_output' => 'invalid_output',
            'ai_500' => 'ai_upstream_error',
            'exception' => 'exception',
        }, $reply->failure_code);
        $this->assertSame(0, $this->metaCalls);

        $lines = $logs();
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            foreach ([$canary, 'BODYCANARY', self::AI_KEY, self::TOKEN] as $needle) {
                $this->assertStringNotContainsString($needle, $line);
            }
        }
        if ($case === 'exception') {
            $this->assertNotEmpty(array_filter($lines, fn (string $l): bool => str_starts_with($l, 'crm.ai_auto_reply.exception') && str_contains($l, 'RuntimeException')));
        }

        foreach ($this->activitiesFor($reply) as $activity) {
            $this->assertStringNotContainsString($canary, json_encode($activity->properties).$activity->description);
        }
    }

    // ============================================================ review fixes: job failed() hook

    /** 46 (N2) — the worker's failed() hook closes a still-open claim and never throws. */
    public function test_the_job_failed_hook_closes_an_open_claim_without_rethrowing(): void
    {
        [$org, $conversation] = $this->wired();
        $logs = $this->captureLogs();

        $drafting = $this->inbound($conversation);
        $this->claimRow($drafting, AutoReply::DRAFTING);
        $sending = $this->inbound($conversation);
        $this->claimRow($sending, AutoReply::SENDING);
        $done = $this->inbound($conversation);
        $this->claimRow($done, AutoReply::SENT);
        $unclaimed = $this->inbound($conversation);

        CurrentOrg::clear();
        foreach ([$drafting, $sending, $done, $unclaimed] as $trigger) {
            (new AiAutoReplyJob($org, (int) $conversation->id, (int) $trigger->id))->failed(new RuntimeException('JOBCANARY-1'));
        }
        // No exception at all is also tolerated.
        (new AiAutoReplyJob($org, (int) $conversation->id, (int) $done->id))->failed(null);
        CurrentOrg::set($org);

        $this->assertFailed($this->reply((int) $drafting->id), AutoReply::FAILED, 'job_failed');
        $this->assertFailed($this->reply((int) $sending->id), AutoReply::AMBIGUOUS, 'job_failed');

        $untouched = $this->reply((int) $done->id);
        $this->assertSame(AutoReply::SENT, $untouched->status);
        $this->assertNull($untouched->failure_code);
        $this->assertCount(0, $this->activitiesFor($untouched));

        $this->assertNull($this->reply((int) $unclaimed->id), 'no row is invented for an unclaimed trigger');
        $this->assertSame(0, $this->aiCalls + $this->metaCalls);
        $this->assertSame(0, $this->outboundRows());

        foreach ($logs() as $line) {
            $this->assertStringNotContainsString('JOBCANARY', $line);
        }
    }

    /** 46b (N2) — a failure inside the hook itself is swallowed too. */
    public function test_the_job_failed_hook_swallows_its_own_failure(): void
    {
        // The service is final; make resolving it fail instead.
        $this->app->bind(AiAutoReplyService::class, fn () => throw new RuntimeException('synthetic'));
        $logs = $this->captureLogs();

        (new AiAutoReplyJob(1, 2, 3))->failed(new RuntimeException('x'));

        $this->assertSame(0, AutoReply::query()->withoutGlobalScopes()->count());
        $this->assertNotEmpty(array_filter($logs(), fn (string $l): bool => str_starts_with($l, 'crm.ai_auto_reply.record_failure_failed')));
    }

    // ============================================================ review fixes: stale in-flight

    /** 47 (N4) — a crashed run's in-flight row stops blocking the thread after 10 minutes. */
    public function test_a_stale_in_flight_row_no_longer_blocks(): void
    {
        [$org, $conversation] = $this->wired();
        $this->priorReply($conversation, AutoReply::SENDING, now()->subDays(3));
        $message = $this->inbound($conversation);

        $reply = $this->runJob($org, (int) $conversation->id, (int) $message->id);

        $this->assertSame(AutoReply::SENT, $reply->status);
        $this->assertSame(1, $this->aiCalls);
        $this->assertSame(1, $this->metaCalls);
    }

    /** @return array<string, array{string}> */
    public static function strandedSends(): array
    {
        return [
            'marked delivery_unknown' => ['marked'],
            // What a worker killed mid-send really leaves: claimed, pending, no outcome, no code.
            'claimed and never recorded' => ['crashed'],
        ];
    }

    /** 47b (N4) — but the send that crashed run may have stranded still blocks it. */
    #[DataProvider('strandedSends')]
    public function test_a_stale_in_flight_row_with_a_stranded_send_still_blocks(string $shape): void
    {
        [$org, $conversation, $actor] = $this->wired();
        $this->priorReply($conversation, AutoReply::SENDING, now()->subDays(3));

        if ($shape === 'marked') {
            $this->outbound($conversation, 'pending', 'delivery_unknown');
        } else {
            $persister = app(OutboundMessagePersister::class);
            $request = new OutboundMessageRequest($conversation, $actor, 'stranded', 'ai-auto:crashed');
            $row = $persister->pending($request, app(OutboundIdempotency::class)->keyFor($request));
            $this->assertTrue($persister->claim($row));
            DB::table('crm_messages')->where('id', $row->id)->update(['created_at' => now()->subDays(3), 'send_attempted_at' => now()->subDays(3)]);
            $this->assertNull($row->refresh()->failure_code);
            $this->assertSame('pending', $row->delivery_status);
        }

        $message = $this->inbound($conversation);

        $this->assertSkipped($this->runJob($org, (int) $conversation->id, (int) $message->id), 'outbound_unresolved');
        $this->assertSame(0, $this->aiCalls);
    }
}
