<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Services\Discovery\Contracts\ProposalGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks OpenAI to name businesses likely to exist in an area, using the credential stored in the CRM.
 *
 * WHY THIS LIVES IN THE CRM AND NOT IN THE SCRAPER. The scraper used to hold an OpenAI key and call
 * the API itself. It no longer does, and cannot: the key is stored encrypted here and the outbound
 * call is made here, so the credential never crosses a process boundary, never enters a worker's
 * memory or argv, and cannot appear in a scraper log. The scraper asks this service for names.
 *
 * IT PROPOSES; IT NEVER DECIDES. The model is asked for NAMES ONLY. Coordinates, identifiers, phone
 * numbers and websites are deliberately not requested and would be discarded if volunteered — the
 * scraper's deterministic OSM resolver supplies all of those, and a name that OSM cannot confirm is
 * dropped before it can become a prospect. That is what keeps a hallucinated business out of the CRM
 * and keeps `place_id` a real natural key, so dedup and idempotency are unaffected.
 *
 * A FAILURE IS NOT AN EMPTY RESULT. Unconfigured, timed out, rate-limited, rejected credential,
 * unreadable body: each returns its own `status`, never a bare empty list. That distinction is the
 * reason this returns a status at all — "the model found no pharmacies in this town" and "the API key
 * was rejected" are both zero candidates, and reporting them identically would let a broken
 * integration look like an honest result run after run. Only `STATUS_OK` means the list is real.
 */
class OpenAiProposalGenerator implements ProposalGenerator
{
    /** Hard ceiling on names per request, whatever the caller asks for. Bounds a runaway spend. */
    public const MAX_PROPOSALS = 60;

    /**
     * Hard ceiling on the completion, bounding the same spend from the other side.
     *
     * MAX_PROPOSALS caps how many names are ASKED for; this caps how much text may come back if the
     * model ignores that. Sized above a full 60-name answer so a compliant maximal reply is never
     * truncated — a cut-off completion is invalid JSON and would be reported as a failure rather
     * than as the honest answer it was.
     */
    public const MAX_COMPLETION_TOKENS = 1000;

    private const SYSTEM_PROMPT = <<<'PROMPT'
    You list real businesses that a mapping database is likely to contain for a given place and category.

    Return business NAMES only. Do not return addresses, coordinates, phone numbers, websites or ids —
    those are looked up from an authoritative source, and anything you supplied would be discarded.

    Rules:
    - Name only businesses you have genuine reason to believe exist at that place.
    - Prefer the name as it would appear on the storefront or signage.
    - Include independent local businesses, not only well-known chains.
    - Returning FEWER real businesses is better than padding the list with plausible inventions. Every
      name is checked against an authoritative database and unverifiable ones are discarded, so
      invented names cost accuracy and gain nothing.

    Reply with JSON only: {"businesses": ["Name One", "Name Two"]}
    PROMPT;

    public function __construct(private readonly NerbyProviderSettingService $settings) {}

    /**
     * @return array{status: string, proposals: list<string>, usage: array<string, mixed>, error: string|null}
     */
    public function propose(string $area, string $category, int $count): array
    {
        $row = $this->settings->for(NerbyProvider::OPENAI);

        // Defence in depth. The controller already refuses unless the EFFECTIVE provider is OpenAI,
        // but a generator that would call out without a stored credential is one refactor away from
        // being called from somewhere that forgot to check.
        if (! $row->exists || ! $row->hasApiKey()) {
            return $this->failure(self::STATUS_NOT_CONFIGURED);
        }

        $model = (string) ($row->option('model') ?: config('openai.model', 'gpt-4o-mini'));
        // OpenAI or OpenRouter, chosen by the stored key; OpenRouter gets a vendor-prefixed model id.
        $endpoint = AiChatEndpoint::for((string) $row->api_key, $model);
        $ask = max(1, min($count, self::MAX_PROPOSALS));

        try {
            $response = Http::withToken((string) $row->api_key)
                ->timeout((float) config('openai.timeout', 30))
                ->post($endpoint->chatCompletionsUrl(), [
                    'model' => $endpoint->model,
                    // Deterministic sampling: the same target must not produce different candidate
                    // sets on two runs, or a provider comparison measures noise.
                    'temperature' => 0,
                    // Bounds a runaway completion. See MAX_COMPLETION_TOKENS on why it is generous.
                    'max_tokens' => self::MAX_COMPLETION_TOKENS,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        [
                            'role' => 'user',
                            'content' => json_encode(
                                ['area' => $area, 'category' => $category, 'max_results' => $ask],
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            ) ?: '{}',
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            // Timeout or socket failure. Expected under load, and reported as a FAILURE rather than
            // an empty result — a run that could not reach the model discovered nothing, which is a
            // different fact from a model that found nothing.
            return $this->failure(self::STATUS_UNREACHABLE, 'connection');
        } catch (Throwable $e) {
            // Deliberately not logging the message: the request carried the credential, and an
            // exception message can quote the request.
            Log::warning('Nearby proposal generation failed', ['class' => $e::class]);

            return $this->failure(self::STATUS_UNREACHABLE, $e::class);
        }

        if ($response->failed()) {
            // Mapped to a CODE, never the upstream body — an OpenAI error message can echo the
            // request, and this travels onward to the scraper's logs and metrics.
            // ONE mapping for every caller, so a status like 402 is understood identically
            // wherever it lands. See AiChatEndpoint::classify().
            $status = AiChatEndpoint::classify($response->status());
            Log::warning('Nearby proposal generation rejected', [
                'status' => $status,
                'http' => $response->status(),
            ]);

            return $this->failure($status, 'http_'.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $this->parseNames($content) === null) {
            // A 2xx we could not read is a real failure. Returning "0 candidates" here would let a
            // broken response shape masquerade as an honest empty result indefinitely.
            Log::warning('Nearby proposal response was unreadable');

            return $this->failure(self::STATUS_INVALID_RESPONSE, 'unreadable_body');
        }

        $names = $this->parseNames($content) ?? [];

        return [
            'status' => self::STATUS_OK,
            'error' => null,
            // Truncated here as well as asked for above: a model that over-delivers must not widen
            // what reaches the resolver.
            'proposals' => array_slice($names, 0, $ask),
            'usage' => [
                'model' => $endpoint->model,
                'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
                'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
            ],
        ];
    }

    /**
     * A failed attempt: no proposals, no usage, and a status saying WHY.
     *
     * @return array{status: string, proposals: list<string>, usage: array<string, mixed>, error: string|null}
     */
    private function failure(string $status, ?string $error = null): array
    {
        return ['status' => $status, 'proposals' => [], 'usage' => [], 'error' => $error];
    }

    /**
     * Names out of the model's JSON, or NULL when the body could not be understood.
     *
     * Null and empty-list are deliberately different returns: an unreadable body is a failure, while
     * `{"businesses": []}` is a model legitimately saying it found nothing. Collapsing them would
     * reintroduce exactly the ambiguity this class exists to remove.
     *
     * @return list<string>|null
     */
    private function parseNames(string $content): ?array
    {
        $data = json_decode($content, true);
        if (! is_array($data) || ! isset($data['businesses']) || ! is_array($data['businesses'])) {
            return null;
        }

        $names = [];
        foreach ($data['businesses'] as $item) {
            // Take only usable strings. A model that returns objects, nulls or numbers among the
            // names loses those entries rather than the whole batch.
            if (is_string($item) && trim($item) !== '') {
                $names[] = trim($item);
            }
        }

        return array_values(array_unique($names));
    }
}
