<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Services\Discovery\Contracts\DiscoveryStrategist;
use App\Modules\Crm\Services\Discovery\Prompts\NerbyDiscoveryInstruction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the model HOW to search for a category — in every dialect the discovery providers speak.
 *
 * This is {@see OpenAiTagExpander} generalised. That class answers one provider's question (which OSM
 * tags a category hides under) and is therefore silent whenever a structured places provider is the
 * one running. Here the same question is answered four ways at once, so a single call serves an OSM
 * sweep, a text search, a type-filtered places query and a scope that needs partitioning.
 *
 * IT PROPOSES A SEARCH, NOT A RESULT — the property the whole AI layer rests on. Selectors, queries,
 * place types and sub-area names are all instructions for a deterministic provider, which then
 * supplies every business, identifier and coordinate. A hallucinated tag matches nothing; an invented
 * place name fails to geocode and is discarded before it is searched. There is no wrong answer here
 * that can become a lead.
 *
 * CACHED ON (category, scope), WHICH IS THE COST STORY. Whether a niche maps to `shop=chemist`, or
 * which sub-areas a metro region contains, does not change between runs — so a pair is asked about
 * once and reused indefinitely. Only successful answers are cached, so a failure is never pinned in
 * place. Scope is part of the key because sub-areas depend on it; category-only answers would be
 * wrong the moment a second scope asked.
 *
 * THE LIMIT IS DELIBERATELY NOT IN THE KEY. It is context for how broad a plan is worth proposing,
 * not an input the plan should vary with: HOW to search for a niche in a place does not change
 * because a run wants 20 businesses instead of 30. Keying on it would buy an identical answer at
 * full price, and would quietly imply a cached plan had been sized to a run it never saw.
 *
 * Credential handling is the proposal path's, unchanged: the key stays encrypted here, the outbound
 * call is made here, and the scraper never receives one.
 */
class OpenAiDiscoveryStrategist implements DiscoveryStrategist
{
    /** Per-dialect ceilings. They bound the model's output AND every query built from it. */
    public const MAX_SELECTORS = 8;

    public const MAX_QUERIES = 8;

    public const MAX_PLACE_TYPES = 8;

    /**
     * Sub-areas per scope. Bounded because each one becomes a provider request: this is the number
     * that decides whether partitioning stays a controlled fan-out or becomes a crawler.
     */
    public const MAX_SUB_AREAS = 16;

    /** How many "already covered" selectors a caller may send. Advisory input, still bounded. */
    public const MAX_KNOWN_SELECTORS = 32;

    private const CACHE_PREFIX = 'crm:discovery:strategy:';

    /**
     * Longest accepted value, for anything crossing this boundary in either direction.
     *
     * A search phrase, a tag selector or an area name is short by nature. Anything longer is either
     * broken or is trying to be something other than a search term — a wall of text smuggled through
     * a target field, or a payload aimed at whatever reads the value next. Rejected rather than
     * truncated: a truncated phrase is a DIFFERENT phrase, and silently searching for it would be a
     * worse outcome than declining the value.
     */
    public const MAX_VALUE_LENGTH = 120;

    /** Longest accepted niche and area, mirroring the form request so direct callers are bound too. */
    public const MAX_CATEGORY_LENGTH = 120;

    public const MAX_SCOPE_LENGTH = 255;

    /** Place types are a closed, machine vocabulary — validated by shape, not by hope. */
    private const PLACE_TYPE_PATTERN = '/^[a-z][a-z0-9_]{1,59}$/';

    public function __construct(private readonly NerbyProviderSettingService $settings) {}

    /**
     * @param  list<string>  $knownSelectors
     * @return array{status: string, strategy: array{selectors: list<string>, queries: list<string>, placeTypes: list<string>, subAreas: list<string>}, usage: array<string, mixed>, error: string|null}
     */
    public function plan(
        string $category,
        string $scope = '',
        array $knownSelectors = [],
        ?int $limit = null,
    ): array {
        // SANITISED AT THE DOOR, not just at the form request: this service is reachable directly,
        // and a boundary that only holds for one caller is not a boundary.
        $category = $this->sanitize($category, self::MAX_CATEGORY_LENGTH);
        $scope = $this->sanitize($scope, self::MAX_SCOPE_LENGTH);
        $knownSelectors = $this->strings($knownSelectors, self::MAX_KNOWN_SELECTORS);

        $cached = Cache::get($this->cacheKey($category, $scope));
        if (is_array($cached)) {
            return [
                'status' => self::STATUS_OK,
                'strategy' => $this->shape($cached),
                // Zeroed rather than absent: this call really did cost nothing, which is a different
                // fact from "cost was not measured".
                'usage' => ['model' => 'cache', 'prompt_tokens' => 0, 'completion_tokens' => 0],
                'error' => null,
            ];
        }

        $row = $this->settings->for(NerbyProvider::OPENAI);

        // Defence in depth. The controller already refuses when AI is not enabled, but a service that
        // would call out uncredentialed is one refactor away from being reached by something that
        // forgot to check.
        if (! $row->exists || ! $row->hasApiKey()) {
            return $this->failure(self::STATUS_NOT_CONFIGURED);
        }

        $model = (string) ($row->option('model') ?: config('openai.model', 'gpt-4o-mini'));
        $endpoint = AiChatEndpoint::for((string) $row->api_key, $model);

        try {
            $response = Http::withToken((string) $row->api_key)
                ->timeout((float) config('openai.timeout', 30))
                ->post($endpoint->chatCompletionsUrl(), [
                    'model' => $endpoint->model,
                    // Deterministic: the same question must not yield a different plan on two runs,
                    // or a cached answer would differ from the one that produced the measurement.
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        // THE ONLY TRUSTED CONTENT IN THE REQUEST. Nothing is interpolated into it:
                        // a prompt that formats runtime values into its own trusted section has an
                        // injection point built in. See NerbyDiscoveryInstruction.
                        ['role' => 'system', 'content' => NerbyDiscoveryInstruction::text()],
                        [
                            'role' => 'user',
                            // THE RUNTIME TARGET, KEPT STRUCTURALLY SEPARATE AND LABELLED UNTRUSTED.
                            //
                            // These values can originate outside this system — a remote target list,
                            // a category carried from provider metadata — so they are nested under a
                            // key that names them as data, and each one is sanitised first. The
                            // instruction tells the model this section is never instructions; the
                            // sanitising is what makes that more than a request, because a value
                            // stripped of line breaks and length cannot present itself as a new
                            // instruction block in the first place.
                            'content' => json_encode([
                                'untrusted_target_data' => [
                                    'category' => $category,
                                    // The target's real area: local trade names differ by region, and
                                    // sub-areas can only be proposed from it. Never echoed as data.
                                    'scope' => $scope,
                                    // Context only: how broad a plan is worth proposing. Caps nothing.
                                    'result_target' => $limit,
                                    'already_covered' => $knownSelectors,
                                ],
                                'limits' => [
                                    'max_selectors' => self::MAX_SELECTORS,
                                    'max_queries' => self::MAX_QUERIES,
                                    'max_place_types' => self::MAX_PLACE_TYPES,
                                    'max_sub_areas' => self::MAX_SUB_AREAS,
                                    'max_value_length' => self::MAX_VALUE_LENGTH,
                                ],
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            return $this->failure(self::STATUS_UNREACHABLE, 'connection');
        } catch (Throwable $e) {
            // The class only: the request carried the credential, and a message can quote the request.
            Log::warning('Nearby discovery strategy failed', ['class' => $e::class]);

            return $this->failure(self::STATUS_UNREACHABLE);
        }

        if (! $response->successful()) {
            $status = match (true) {
                in_array($response->status(), [401, 403], true) => self::STATUS_AUTH_FAILED,
                $response->status() === 429 => self::STATUS_RATE_LIMITED,
                default => self::STATUS_UNREACHABLE,
            };

            Log::warning('Nearby discovery strategy rejected', [
                'status' => $status,
                'http' => $response->status(),
            ]);

            return $this->failure($status, 'http_'.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;
        if (! is_array($decoded)) {
            return $this->failure(self::STATUS_INVALID_RESPONSE, 'unreadable_body');
        }

        $strategy = $this->shape([
            'selectors' => $this->strings($decoded['selectors'] ?? null, self::MAX_SELECTORS),
            'queries' => $this->strings($decoded['queries'] ?? null, self::MAX_QUERIES),
            'placeTypes' => $this->placeTypes($decoded['placeTypes'] ?? null),
            'subAreas' => $this->strings($decoded['subAreas'] ?? null, self::MAX_SUB_AREAS),
        ]);

        // Only successful answers are cached — a failure must never be pinned in place.
        Cache::forever($this->cacheKey($category, $scope), $strategy);

        return [
            'status' => self::STATUS_OK,
            'strategy' => $strategy,
            'usage' => [
                'model' => $endpoint->model,
                'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
                'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
            ],
            'error' => null,
        ];
    }

    /** Drop a cached plan so the question is asked again. */
    public function forget(string $category, string $scope = ''): void
    {
        Cache::forget($this->cacheKey(trim($category), trim($scope)));
    }

    private function cacheKey(string $category, string $scope): string
    {
        return self::CACHE_PREFIX.mb_strtolower($category).'|'.mb_strtolower($scope);
    }

    /**
     * Trimmed, de-duplicated, bounded strings. Anything that is not a string is dropped rather than
     * coerced: a model that answered with an object has not answered this question.
     *
     * @return list<string>
     */
    private function strings(mixed $values, int $cap): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $text = $this->sanitize($value, self::MAX_VALUE_LENGTH + 1);

            // REJECTED, NOT TRUNCATED — a shortened phrase is a different phrase, and searching for
            // it would be worse than declining it.
            if ($text === '' || mb_strlen($text) > self::MAX_VALUE_LENGTH) {
                continue;
            }

            // A SEARCH TERM IS NEVER A URL. This is the one shape whose presence would mean the
            // model is proposing somewhere to go rather than something to look for, which is the
            // shape an exfiltration attempt would take. Refused outright.
            if (str_contains($text, '://')) {
                continue;
            }

            if (! in_array($text, $out, true)) {
                $out[] = $text;
            }
            if (count($out) >= $cap) {
                break;
            }
        }

        return $out;
    }

    /**
     * Place types, validated against the machine vocabulary they belong to.
     *
     * A structured place type is `lower_snake_case` and nothing else. Checking the SHAPE here — not
     * only downstream where the request is built — means an invalid value never travels at all, and
     * a value carrying anything a type cannot contain is refused before it can be anything else.
     *
     * @return list<string>
     */
    private function placeTypes(mixed $values): array
    {
        return array_values(array_filter(
            $this->strings($values, self::MAX_PLACE_TYPES),
            fn (string $type): bool => preg_match(self::PLACE_TYPE_PATTERN, $type) === 1,
        ));
    }

    /**
     * One untrusted string, reduced to something that can only be a value.
     *
     * Control characters and line breaks are replaced with spaces and runs of whitespace collapsed,
     * so a value cannot lay itself out as a new instruction block; then it is bounded. This is the
     * ENFORCED half of the trust boundary — the instruction asks the model to treat target data as
     * data, and this makes the data unable to look like anything else.
     */
    private function sanitize(string $value, int $max): string
    {
        // \p{C} covers control, format and unassigned code points — including the invisible
        // formatting characters used to hide text from a reviewer while a model still reads it.
        $clean = preg_replace('/[\p{C}]+/u', ' ', $value) ?? $value;
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        return mb_substr(trim($clean), 0, $max);
    }

    /**
     * The strategy's four keys, always present.
     *
     * A caller reading `queries` must never have to ask whether the key exists — an absent dialect
     * and an empty one are the same fact ("no advice"), and only one of them should have a shape.
     *
     * @param  array<string, mixed>  $plan
     * @return array{selectors: list<string>, queries: list<string>, placeTypes: list<string>, subAreas: list<string>}
     */
    private function shape(array $plan): array
    {
        return [
            'selectors' => array_values(array_filter((array) ($plan['selectors'] ?? []), 'is_string')),
            'queries' => array_values(array_filter((array) ($plan['queries'] ?? []), 'is_string')),
            'placeTypes' => array_values(array_filter((array) ($plan['placeTypes'] ?? []), 'is_string')),
            'subAreas' => array_values(array_filter((array) ($plan['subAreas'] ?? []), 'is_string')),
        ];
    }

    /**
     * @return array{status: string, strategy: array{selectors: list<string>, queries: list<string>, placeTypes: list<string>, subAreas: list<string>}, usage: array<string, mixed>, error: string|null}
     */
    private function failure(string $status, ?string $error = null): array
    {
        return [
            'status' => $status,
            'strategy' => $this->shape([]),
            'usage' => [],
            'error' => $error,
        ];
    }
}
