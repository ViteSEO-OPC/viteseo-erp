<?php

declare(strict_types=1);

namespace App\Modules\Crm\Listeners;

use App\Modules\Crm\Events\InboundConversationMessageReceived;
use App\Modules\Crm\Jobs\AiAutoReplyJob;
use App\Modules\Crm\Services\AiDraft\ReplyDraftService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 3I — decides CHEAPLY whether a new inbound turn is worth an auto-reply job, and queues one.
 *
 * SYNCHRONOUS, AND IT MUST NEVER THROW. InboundConversationMessageReceived is dispatched after the
 * ingest transaction commits, but that after-commit callback still runs INSIDE
 * InboundConversationService::ingest()'s try block and inside the webhook dispatcher's
 * `CurrentOrg::runAs`. An exception escaping from here would surface as an ingest failure — a
 * misleading persistence log, a 5xx to Meta, a redelivery that is then treated as a replay — and
 * the message would never be considered again. So everything is wrapped, and a failure costs only
 * this one auto-reply, logged by exception CLASS.
 *
 * ONLY CHEAP FILTERS HERE: config flags and the fields already on the event's models. No queries.
 * Every one of them is repeated authoritatively by AiAutoReplyService when the job runs, under a
 * lock; this layer exists so the common case — automation off, or a conversation not on the
 * allowlist — costs nothing, not so that it can be trusted.
 *
 * WHAT NEVER REACHES HERE. Replays fire no event (ingest returns early), echoes of our own sends
 * are dropped by the Meta adapter, and the auto-reply's own outbound send writes an outbound row
 * directly through the persister, which fires no inbound event. So the reply cannot trigger itself.
 */
final class QueueAiAutoReply
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function handle(InboundConversationMessageReceived $event): void
    {
        try {
            if (! $this->qualifies($event)) {
                return;
            }

            $job = (new AiAutoReplyJob(
                (int) $event->conversation->org_id,
                (int) $event->conversation->id,
                (int) $event->message->id,
            ))
                ->delay(now()->addSeconds(max(0, (int) config('ai_auto_reply.debounce_seconds', 15))))
                // The queue connection has after_commit=false. The event is already after-commit,
                // but an outer transaction opened by some future caller must still hold the job.
                ->afterCommit();

            $this->bus->dispatch($job);
        } catch (Throwable $e) {
            Log::warning('crm.ai_auto_reply.dispatch_failed', [
                'org_id' => (int) $event->conversation->org_id,
                'conversation_id' => (int) $event->conversation->id,
                'inbound_message_id' => (int) $event->message->id,
                // The CLASS only: an exception message can quote a query, and a query can carry text.
                'exception' => $e::class,
            ]);
        }
    }

    private function qualifies(InboundConversationMessageReceived $event): bool
    {
        if (! config('ai_auto_reply.enabled')
            || ReplyDraftService::configurationFailure() !== null
            || ! config('meta.outbound.enabled')) {
            return false;
        }

        $message = $event->message;
        $conversation = $event->conversation;

        if (! $message->isInbound()
            || $message->provider !== 'meta'
            || $conversation->provider !== 'meta'
            || ! in_array($message->channel, (array) config('ai_auto_reply.channels', []), true)
            || $conversation->channel !== $message->channel
            || ! in_array($message->message_type, (array) config('ai_auto_reply.message_types', []), true)
            || trim((string) $message->body) === '') {
            return false;
        }

        if ((int) $message->conversation_id !== (int) $conversation->id
            || (int) $message->org_id !== (int) $conversation->org_id) {
            return false;
        }

        return in_array((int) $conversation->id, (array) config('ai_auto_reply.conversation_ids', []), true);
    }
}
