<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\AiDraft;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Services\Outbound\OutboundMessageService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks a language model for a DRAFT reply. Phase 4A-lite.
 *
 * THIS CLASS CANNOT SEND. It builds no OutboundMessageRequest, never calls
 * {@see OutboundMessageService}, writes no `crm_messages` row and touches no provider account or
 * credential. Its only side effect is one HTTP request to the configured model endpoint. The agent
 * who reads the draft sends it — or does not — through the ordinary reply endpoint, where every
 * permission, tenancy, window and idempotency check already lives.
 *
 * ONE ATTEMPT, NO RETRY. The flag and the key are checked before any HTTP; then exactly one request
 * is made with the configured timeout. Every failure maps to a fixed code in
 * {@see ReplyDraftResult}; the upstream body is never read for text and never logged, because a
 * provider error can quote the prompt, and the prompt is customer text.
 *
 * LOGGING carries identifiers, the code, the HTTP status, an exception CLASS and token counts — never
 * the prompt, a message body, the draft, or an exception message.
 */
class ReplyDraftService
{
    private const TEMPERATURE = 0.4;

    public function __construct(private readonly ReplyDraftPromptBuilder $prompts) {}

    /** Whether the feature is switched on and has a key. No HTTP, no query. */
    public static function configurationFailure(): ?string
    {
        if (! config('ai_drafts.enabled')) {
            return ReplyDraftResult::DISABLED;
        }

        if (blank(config('ai_drafts.api_key'))) {
            return ReplyDraftResult::NOT_CONFIGURED;
        }

        return null;
    }

    public function draft(Conversation $conversation, ?int $actorId = null): ReplyDraftResult
    {
        $model = (string) config('ai_drafts.model');

        if (($code = self::configurationFailure()) !== null) {
            return ReplyDraftResult::failed($code, $model);
        }

        $messages = $this->prompts->build(
            $this->recentMessages($conversation),
            (int) config('ai_drafts.context_messages', 10),
            (int) config('ai_drafts.max_chars_per_message', 1000),
        );

        if ($messages === []) {
            return $this->fail($conversation, $actorId, ReplyDraftResult::EMPTY_CONVERSATION, $model);
        }

        try {
            $response = Http::withToken((string) config('ai_drafts.api_key'))
                ->withHeaders($this->attributionHeaders())
                ->acceptJson()
                ->timeout((int) config('ai_drafts.timeout', 15))
                ->post(rtrim((string) config('ai_drafts.base_url'), '/').'/chat/completions', [
                    'model' => $model,
                    'temperature' => self::TEMPERATURE,
                    'max_tokens' => (int) config('ai_drafts.max_output_tokens', 500),
                    'messages' => $messages,
                ]);
        } catch (ConnectionException $e) {
            return $this->fail($conversation, $actorId, ReplyDraftResult::TIMEOUT, $model, exception: $e);
        } catch (Throwable $e) {
            return $this->fail($conversation, $actorId, ReplyDraftResult::UPSTREAM_ERROR, $model, exception: $e);
        }

        if ($response->failed()) {
            $code = match (true) {
                in_array($response->status(), [401, 403], true) => ReplyDraftResult::AUTH_FAILED,
                $response->status() === 429 => ReplyDraftResult::RATE_LIMITED,
                default => ReplyDraftResult::UPSTREAM_ERROR,
            };

            return $this->fail($conversation, $actorId, $code, $model, httpStatus: $response->status());
        }

        $json = $response->json();
        $content = is_array($json) ? data_get($json, 'choices.0.message.content') : null;
        $draft = is_string($content) ? trim($content) : '';

        // A 200 carrying an `error` object is a failure some gateways report in-band.
        if ($draft === '' || isset($json['error'])) {
            return $this->fail($conversation, $actorId, ReplyDraftResult::INVALID_RESPONSE, $model, httpStatus: $response->status());
        }

        $draft = mb_substr($draft, 0, max(1, (int) config('ai_drafts.max_draft_chars', 2000)));

        Log::info('crm.ai_draft.generated', [
            'conversation_id' => (int) $conversation->id,
            'org_id' => (int) $conversation->org_id,
            'actor_id' => $actorId,
            'model' => $model,
            'prompt_version' => ReplyDraftPromptBuilder::PROMPT_VERSION,
            'prompt_tokens' => (int) data_get($json, 'usage.prompt_tokens', 0),
            'completion_tokens' => (int) data_get($json, 'usage.completion_tokens', 0),
        ]);

        return ReplyDraftResult::drafted($draft, $model);
    }

    /**
     * The newest N turns, oldest first — the same ordering as the inbox thread.
     *
     * `reorder()` is required: the relation carries an ASC order that an appended DESC would not
     * override (see ConversationController::show). Only the two columns the prompt may use are
     * selected, so nothing else is even hydrated.
     *
     * @return iterable<ConversationMessage>
     */
    private function recentMessages(Conversation $conversation): iterable
    {
        return $conversation->messages()
            ->reorder()
            ->orderByRaw('COALESCE(occurred_at, received_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(max(1, (int) config('ai_drafts.context_messages', 10)))
            ->get(['id', 'direction', 'body'])
            ->reverse()
            ->values();
    }

    /** @return array<string, string> */
    private function attributionHeaders(): array
    {
        return array_filter([
            'HTTP-Referer' => (string) config('ai_drafts.referer', ''),
            'X-Title' => (string) config('ai_drafts.app_title', ''),
        ], fn (string $value): bool => $value !== '');
    }

    private function fail(
        Conversation $conversation,
        ?int $actorId,
        string $code,
        string $model,
        ?int $httpStatus = null,
        ?Throwable $exception = null,
    ): ReplyDraftResult {
        Log::warning('crm.ai_draft.failed', [
            'conversation_id' => (int) $conversation->id,
            'org_id' => (int) $conversation->org_id,
            'actor_id' => $actorId,
            'failure_code' => $code,
            'http_status' => $httpStatus,
            // The CLASS only: an exception message can quote the request, which carried the key.
            'exception' => $exception !== null ? $exception::class : null,
            'model' => $model,
        ]);

        return ReplyDraftResult::failed($code, $model);
    }
}
