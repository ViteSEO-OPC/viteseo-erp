<?php

namespace App\Modules\Crm\Services\Copilot;

use App\Models\User;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Services\Copilot\Prompts\CopilotInstruction;
use App\Modules\Crm\Services\Copilot\Retrieval\AuthorizedLead;
use App\Modules\Crm\Services\Copilot\Retrieval\CapabilityRegistry;
use App\Modules\Crm\Services\Copilot\Retrieval\LeadCapabilityRegistry;
use App\Modules\Crm\Services\Copilot\Retrieval\RetrievedFacts;
use App\Modules\Crm\Services\Discovery\AiChatEndpoint;
use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The Copilot's AI: a real model answers a CRM question in its own words, from facts the server
 * selected and authorised for this user.
 *
 * TWO HALVES, NEVER MIXED:
 *   - CODE (this class and its collaborators) controls ACCESS: the three gates, cost limits, which
 *     read-only capabilities run ({@see CopilotRetrievalPlanner}), what each may read (their own
 *     permission checks and ownership scopes), what leaves the server ({@see CopilotContextBuilder}),
 *     and whether the model's reply is safe and grounded ({@see CopilotAnswerValidator}).
 *   - THE MODEL controls the ANSWER: understanding the question, reasoning over the facts, and every
 *     word of the reply. No code here writes, completes or rephrases an answer.
 *
 * ONE RETRY, FOR WORDING ONLY. When the validator rejects an answer for a FIXABLE reason — a wording
 * fault such as "your" on a whole-CRM figure, and only when nothing harder is wrong with it — the
 * model is asked once more with the SAME facts (no new retrieval), its own reply, and a fixed
 * correction note ({@see CopilotInstruction::CORRECTIONS}). The retry passes the full validator or the
 * deterministic reply stands. It is a second model call in every respect: gated by the circuit
 * breaker, counted against the daily cap, and audited as its own row (outcome `retry_…`).
 *
 * READ-ONLY AND FAIL-SAFE. Nothing here writes CRM data or the NERBY provider row whose credential it
 * borrows (a 402 is not recorded as insufficient credit). Every failure returns null, and the Copilot
 * shows the deterministic reply it has always shown. The only write is one metadata audit row.
 */
class CopilotAiService
{
    /** Hard ceiling on the provider body before anything decodes it. */
    private const MAX_RESPONSE_BYTES = 32768;

    public function __construct(
        private readonly NerbyProviderSettingService $settings,
        private readonly CopilotSettingService $copilotSettings,
        private readonly CopilotRetrievalPlanner $planner,
        private readonly CapabilityRegistry $registry,
        private readonly LeadCapabilityRegistry $leadRegistry,
        private readonly CopilotContextBuilder $contextBuilder,
        private readonly CopilotAnswerValidator $validator,
        private readonly CopilotUsageGuard $guard,
        private readonly CopilotAuditLogger $audit,
    ) {}

    /**
     * The model's accepted answer, or null when the Copilot should keep its deterministic reply.
     *
     * `$leadId` is the one lead the browser says the question is about. It is re-authorised here
     * ({@see AuthorizedLead::resolve()}) before any retrieval, provider call or daily-cap use; a lead
     * that is missing, deleted, in another org or not viewable by this user stops the request with
     * the same null as any unanswered question, audited as `lead_denied`.
     */
    public function answer(User $user, string $question, ?int $leadId = null): ?string
    {
        // Ops kill switch, then the Super Admin toggle, then the credential — cheapest first. Off
        // means no retrieval, no provider call and no audit row: the Copilot is exactly as before.
        if (! $this->copilotSettings->aiAvailable()) {
            return null;
        }

        $started = hrtime(true);
        $finish = function (string $outcome, ?string $reason = null, ?CopilotContext $context = null, ?string $model = null, array $usage = []) use ($user, $question, &$started): void {
            $this->audit->record($user, $question, $outcome, $reason, $context?->recordCounts, $model, intdiv(hrtime(true) - $started, 1_000_000), $usage);
        };

        $lead = null;
        if ($leadId !== null) {
            $lead = AuthorizedLead::resolve($user, $leadId);
            if ($lead === null) {
                $finish('lead_denied');

                return null;
            }
        }

        if ($this->guard->breakerOpen()) {
            $finish('circuit_open');

            return null;
        }
        if (! $this->guard->consumeDaily($user)) {
            $finish('daily_limit');

            return null;
        }

        $context = $this->contextBuilder->build($question, [
            ...$this->retrieve($user, $question),
            ...($lead === null ? [] : $this->retrieveForLead($user, $lead, $question)),
        ]);

        [$content, $failure, $model, $usage] = $this->ask($context);
        if ($content === null) {
            $this->guard->recordProviderFailure();
            $finish($failure, null, $context, $model);

            return null;
        }
        $this->guard->recordProviderSuccess();

        [$reason, $answer] = $this->validator->validate($content, $context, $question);
        if ($answer !== null) {
            $finish('answered', null, $context, $model, $usage);

            return $answer;
        }
        $finish($this->outcomeFor($reason), $reason, $context, $model, $usage);

        if (! in_array($reason, CopilotAnswerValidator::FIXABLE, true)) {
            return null;
        }

        // ── The one retry. ────────────────────────────────────────────────────────────────────────
        if ($this->guard->breakerOpen() || ! $this->guard->consumeDaily($user)) {
            return null;
        }
        $started = hrtime(true);
        [$content, $failure, $model, $usage] = $this->ask($context, [
            ['role' => 'assistant', 'content' => $content],
            ['role' => 'user', 'content' => CopilotInstruction::CORRECTION_PREFIX.CopilotInstruction::CORRECTIONS[$reason].CopilotInstruction::CORRECTION_SUFFIX],
        ]);
        if ($content === null) {
            $this->guard->recordProviderFailure();
            $finish('retry_'.$failure, null, $context, $model);

            return null;
        }
        $this->guard->recordProviderSuccess();

        [$retryReason, $answer] = $this->validator->validate($content, $context, $question);
        $finish($answer === null ? 'retry_'.$this->outcomeFor($retryReason) : 'retry_answered', $answer === null ? $retryReason : null, $context, $model, $usage);

        return $answer;
    }

    /**
     * Run the planned capabilities that this user is permitted, as this user. A capability that is
     * refused is skipped silently — the model is never told data exists that the user cannot see —
     * and one that fails is skipped too, so a broken source degrades the answer, not the Copilot.
     *
     * @return list<RetrievedFacts>
     */
    private function retrieve(User $user, string $question): array
    {
        $retrieved = [];
        foreach ($this->planner->plan($question) as $key) {
            $capability = $this->registry->get($key);
            if (! $capability->permits($user)) {
                continue;
            }
            try {
                $retrieved[] = $capability->retrieve($user, $question);
            } catch (Throwable $e) {
                Log::warning('Copilot capability failed', ['capability' => $key, 'class' => $e::class]);
            }
        }

        return $retrieved;
    }

    /**
     * Run every lead-scoped capability this user is permitted, for the one lead already authorised.
     * Same rules as {@see retrieve()}: a refused or failing capability is skipped silently.
     *
     * @return list<RetrievedFacts>
     */
    private function retrieveForLead(User $user, AuthorizedLead $lead, string $question): array
    {
        $retrieved = [];
        foreach ($this->leadRegistry->keys() as $key) {
            $capability = $this->leadRegistry->get($key);
            if (! $capability->permits($user)) {
                continue;
            }
            try {
                $retrieved[] = $capability->retrieve($user, $lead, $question);
            } catch (Throwable $e) {
                Log::warning('Copilot lead capability failed', ['capability' => $key, 'class' => $e::class]);
            }
        }

        return $retrieved;
    }

    /**
     * One call to the model. Returns [content, failure outcome, model, usage]. `$followUp` carries the
     * retry's two extra turns (the rejected reply and the fixed correction) after the same facts.
     *
     * @param  list<array{role: string, content: string}>  $followUp
     * @return array{0: string|null, 1: string, 2: string|null, 3: array{prompt_tokens?: int|null, completion_tokens?: int|null}}
     */
    private function ask(CopilotContext $context, array $followUp = []): array
    {
        $row = $this->settings->for(NerbyProvider::OPENAI);
        $model = (string) ($row->option('model') ?: config('openai.model', 'gpt-4o-mini'));
        $endpoint = AiChatEndpoint::for((string) $row->api_key, $model);

        try {
            $response = Http::withToken((string) $row->api_key)
                ->timeout((float) config('copilot.ai.timeout', 15))
                ->post($endpoint->chatCompletionsUrl(), [
                    'model' => $endpoint->model,
                    'temperature' => (float) config('copilot.ai.temperature', 0.2),
                    'max_tokens' => (int) config('copilot.ai.max_tokens', 500),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => CopilotInstruction::TEXT],
                        ['role' => 'user', 'content' => (string) json_encode($context->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                        ...$followUp,
                    ],
                ]);
        } catch (ConnectionException) {
            return [null, 'timeout', $endpoint->model, []];
        } catch (Throwable $e) {
            // The class only: the request carried the credential, and a message could quote it.
            Log::warning('Copilot AI request failed', ['class' => $e::class]);

            return [null, 'provider_error', $endpoint->model, []];
        }

        // Any refusal — credit included — is simply "no answer". Deliberately NOT recorded on the
        // shared NERBY row: the Copilot must never change NERBY's provider state.
        if ($response->failed() || strlen((string) $response->body()) > self::MAX_RESPONSE_BYTES) {
            return [null, 'provider_error', $endpoint->model, []];
        }

        $content = $response->json('choices.0.message.content');
        $usage = [
            'prompt_tokens' => is_int($response->json('usage.prompt_tokens')) ? $response->json('usage.prompt_tokens') : null,
            'completion_tokens' => is_int($response->json('usage.completion_tokens')) ? $response->json('usage.completion_tokens') : null,
        ];

        return is_string($content) ? [$content, 'answered', $endpoint->model, $usage] : [null, 'provider_error', $endpoint->model, $usage];
    }

    private function outcomeFor(string $reason): string
    {
        return match (true) {
            $reason === 'declined' => 'declined',
            str_starts_with($reason, 'leak_'), $reason === 'action_claim' => 'unsafe',
            str_starts_with($reason, 'ungrounded_'), str_starts_with($reason, 'unsupported_'), $reason === 'unknown_term',
            in_array($reason, ['misbound_number', 'unbound_number', 'misplaced_reference_number'], true),
            in_array($reason, CopilotAnswerValidator::FIXABLE, true) => 'ungrounded',
            default => 'invalid',
        };
    }
}
