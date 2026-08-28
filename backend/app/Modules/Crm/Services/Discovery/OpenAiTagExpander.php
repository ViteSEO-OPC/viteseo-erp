<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Services\Discovery\Contracts\TagExpander;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks OpenAI which OpenStreetMap tags a category can hide under, using the CRM's stored credential.
 *
 * IT PROPOSES A SEARCH, NOT A RESULT. The model returns `key=value` strings and nothing else. Every
 * business, identifier and coordinate still comes from Overpass, so the worst a wrong answer can do
 * is describe a query that matches nothing. That is a materially smaller trust surface than naming
 * businesses, and it is why this capability can be enabled on evidence rather than on faith.
 *
 * CACHED WITH NO EXPIRY, WHICH IS THE WHOLE COST STORY. The answer does not depend on an area or on
 * when it is asked — `amenity=fast_food` will not stop meaning fast food — so a category is asked
 * about once and never again. Nineteen categories bounds this capability at nineteen small calls for
 * its entire life. A TTL would buy an identical answer at a price, so there is none; the cache is
 * cleared deliberately, by {@see forget()}, when someone wants the question re-asked.
 *
 * The credential handling is the proposal path's, unchanged: the key stays encrypted here, the
 * outbound call is made here, and the scraper never receives one.
 */
class OpenAiTagExpander implements TagExpander
{
    /** Hard ceiling on selectors per request. Bounds both the model's output and the Overpass query. */
    public const MAX_SELECTORS = 8;

    /** Cache key prefix. Keyed on CATEGORY ALONE — see the class docblock on why no area appears. */
    private const CACHE_PREFIX = 'crm:discovery:tag-expansion:';

    private const SYSTEM_PROMPT = <<<'PROMPT'
    You map a business category to the OpenStreetMap tags that businesses of that kind are actually
    tagged with.

    Return OSM tag selectors only, as "key=value" strings. Do not return business names, addresses,
    coordinates, counts or ids — those come from an authoritative source and anything you supplied
    would be discarded.

    Rules:
    - Only keys that describe what a business IS: shop, amenity, office, craft, healthcare, tourism,
      leisure. Selectors using any other key are discarded.
    - Only tags in real, common OSM usage. An invented tag matches nothing and wastes a query.
    - Do NOT repeat selectors the caller says are already covered. Additional ones only.
    - Never return tags for infrastructure — parking, benches, toilets, playgrounds. Those are not
      businesses and are rejected.
    - Returning FEWER real tags is better than padding. Every selector is validated and executed, so
      a wrong one costs accuracy and gains nothing.

    Reply with JSON only: {"selectors": ["key=value", "key=value"]}
    PROMPT;

    public function __construct(private readonly NerbyProviderSettingService $settings) {}

    /**
     * @param  list<string>  $knownSelectors
     * @return array{status: string, selectors: list<string>, usage: array<string, mixed>, error: string|null}
     */
    public function expand(string $category, array $knownSelectors = []): array
    {
        $category = trim($category);

        // A CACHED ANSWER IS RETURNED WITHOUT A CALL. Only successful answers are ever cached — see
        // the write below — so this can never pin a failure in place.
        $cached = Cache::get(self::CACHE_PREFIX.mb_strtolower($category));
        if (is_array($cached)) {
            return [
                'status' => self::STATUS_OK,
                'selectors' => $cached,
                // Zeroed rather than absent: this call really did cost nothing, which is a different
                // fact from "cost was not measured".
                'usage' => ['model' => 'cache', 'prompt_tokens' => 0, 'completion_tokens' => 0],
                'error' => null,
            ];
        }

        $row = $this->settings->for(NerbyProvider::OPENAI);

        // Defence in depth, as on the proposal path: the controller already refuses unless OpenAI is
        // the effective provider, but a generator that would call out uncredentialed is one refactor
        // away from being reached by something that forgot to check.
        if (! $row->exists || ! $row->hasApiKey()) {
            return $this->failure(self::STATUS_NOT_CONFIGURED);
        }

        $model = (string) ($row->option('model') ?: config('openai.model', 'gpt-4o-mini'));

        try {
            $response = Http::withToken((string) $row->api_key)
                ->timeout((float) config('openai.timeout', 30))
                ->post(rtrim((string) config('openai.base_url', 'https://api.openai.com/v1'), '/').'/chat/completions', [
                    'model' => $model,
                    // Deterministic: the same category must not yield different tag sets on two runs,
                    // or a cached answer would differ from the one that produced the measurement.
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        [
                            'role' => 'user',
                            'content' => json_encode([
                                'category' => $category,
                                'already_covered' => array_values($knownSelectors),
                                'max_results' => self::MAX_SELECTORS,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                        ],
                    ],
                ]);
        } catch (ConnectionException) {
            return $this->failure(self::STATUS_UNREACHABLE, 'connection');
        } catch (Throwable $e) {
            // The message is deliberately not logged: the request carried the credential, and an
            // exception message can quote the request.
            Log::warning('Nearby tag expansion failed', ['class' => $e::class]);

            return $this->failure(self::STATUS_UNREACHABLE, $e::class);
        }

        if ($response->failed()) {
            $status = match (true) {
                in_array($response->status(), [401, 403], true) => self::STATUS_AUTH_FAILED,
                $response->status() === 429 => self::STATUS_RATE_LIMITED,
                default => self::STATUS_UNREACHABLE,
            };
            Log::warning('Nearby tag expansion rejected', [
                'status' => $status,
                'http' => $response->status(),
            ]);

            return $this->failure($status, 'http_'.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || ($selectors = $this->parseSelectors($content)) === null) {
            Log::warning('Nearby tag expansion response was unreadable');

            return $this->failure(self::STATUS_INVALID_RESPONSE, 'unreadable_body');
        }

        $selectors = array_slice($selectors, 0, self::MAX_SELECTORS);

        // ONLY SUCCESS IS CACHED, and it is cached forever. Caching a failure would turn a transient
        // outage into a permanent "this category has no other tags", which is exactly the confusion
        // the status vocabulary exists to prevent.
        Cache::forever(self::CACHE_PREFIX.mb_strtolower($category), $selectors);

        return [
            'status' => self::STATUS_OK,
            'selectors' => $selectors,
            'usage' => [
                'model' => $model,
                'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
                'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
            ],
            'error' => null,
        ];
    }

    /** Drop a category's cached expansion so the next call re-asks. The only way an answer changes. */
    public static function forget(string $category): void
    {
        Cache::forget(self::CACHE_PREFIX.mb_strtolower(trim($category)));
    }

    /**
     * @return array{status: string, selectors: list<string>, usage: array<string, mixed>, error: string|null}
     */
    private function failure(string $status, ?string $error = null): array
    {
        return ['status' => $status, 'selectors' => [], 'usage' => [], 'error' => $error];
    }

    /**
     * Selectors out of the model's JSON, or NULL when the body could not be understood.
     *
     * Null and empty-list are deliberately different returns, as on the proposal path: an unreadable
     * body is a failure, while `{"selectors": []}` is a model legitimately saying a category has no
     * other common tags — which for a narrow category is the right answer.
     *
     * Shape only is enforced here. Whether a key is one the sweep may use is decided by the consumer
     * that owns the OSM vocabulary; this refuses what is not even a selector.
     *
     * @return list<string>|null
     */
    private function parseSelectors(string $content): ?array
    {
        $data = json_decode($content, true);
        if (! is_array($data) || ! isset($data['selectors']) || ! is_array($data['selectors'])) {
            return null;
        }

        $selectors = [];
        foreach ($data['selectors'] as $item) {
            // A model that returns objects, nulls or numbers among the selectors loses those entries
            // rather than the whole batch.
            if (! is_string($item)) {
                continue;
            }
            $value = trim($item);
            if ($value !== '' && substr_count($value, '=') === 1) {
                $selectors[] = $value;
            }
        }

        return array_values(array_unique($selectors));
    }
}
