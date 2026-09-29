<?php

declare(strict_types=1);

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Services\AiAutoReply\AiAutoReplyService;
use App\Support\CurrentOrg;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 3I — runs one AI auto-reply decision. Thin: all logic is in {@see AiAutoReplyService}.
 *
 * INTEGERS ONLY. No SerializesModels and no model properties: the serialized payload in `jobs`
 * carries three ids and nothing a customer wrote. Everything is re-read inside the job, under the
 * org the job names.
 *
 * AT MOST ONE EXECUTION THAT MATTERS. `$tries = 1` and `$maxExceptions = 1` so the worker's
 * `--tries=3` never re-runs it, and the body never throws anyway: a retry after a send that may have
 * reached Meta is exactly the double-message this phase must not produce. A duplicate delivery of
 * the job (the database queue can hand a job out twice after `retry_after`) is harmless because the
 * service's first act is a UNIQUE claim on the inbound message.
 *
 * `$timeout` 60 s sits under the connection's `retry_after` (90 s), so a slow run is killed before
 * the queue would consider it lost and hand it to a second worker. The model call (15 s) plus the
 * Meta call (10 s) fit well inside it. `$failOnTimeout` marks a killed run failed rather than
 * releasing it for another attempt.
 */
final class AiAutoReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $orgId,
        public readonly int $conversationId,
        public readonly int $inboundMessageId,
    ) {}

    public function handle(AiAutoReplyService $service): void
    {
        // The worker cleared any inherited pin before this job started (AppServiceProvider), so the
        // tenant is exactly the one this job was dispatched for.
        CurrentOrg::runAs($this->orgId, function () use ($service): void {
            try {
                // The service records anything thrown after its claim on the row it claimed.
                $service->handle($this->orgId, $this->conversationId, $this->inboundMessageId);
            } catch (Throwable $e) {
                // Only reachable before a claim exists, or if recording itself failed. Never
                // rethrow: a rethrow is a retry on some worker configuration, and a retry is a
                // second send. The CLASS only — a message can quote a query.
                Log::warning('crm.ai_auto_reply.job_exception', [
                    'org_id' => $this->orgId,
                    'conversation_id' => $this->conversationId,
                    'inbound_message_id' => $this->inboundMessageId,
                    'exception' => $e::class,
                ]);
            }
        });
    }

    /**
     * The worker gave up on the job — a timeout kill (`$failOnTimeout`), or a failure outside
     * handle(). Closes a still-open claim (`sending` → `ambiguous`, otherwise `failed` with
     * `job_failed`) and does nothing else: never rethrows, never re-dispatches.
     */
    public function failed(?Throwable $e = null): void
    {
        try {
            Log::warning('crm.ai_auto_reply.job_failed', [
                'org_id' => $this->orgId,
                'conversation_id' => $this->conversationId,
                'inbound_message_id' => $this->inboundMessageId,
                'exception' => $e === null ? null : $e::class,
            ]);

            CurrentOrg::runAs($this->orgId, function (): void {
                app(AiAutoReplyService::class)->recordJobFailure($this->orgId, $this->inboundMessageId);
            });
        } catch (Throwable $inner) {
            try {
                Log::warning('crm.ai_auto_reply.record_failure_failed', [
                    'org_id' => $this->orgId,
                    'inbound_message_id' => $this->inboundMessageId,
                    'exception' => $inner::class,
                ]);
            } catch (Throwable) {
                // Nothing left to do; the failed() hook must not throw.
            }
        }
    }
}
