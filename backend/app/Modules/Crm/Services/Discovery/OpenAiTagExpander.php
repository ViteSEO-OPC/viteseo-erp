<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Services\Discovery\Contracts\TagExpander;
use App\Modules\Crm\Services\Discovery\Prompts\NerbyTrustBoundary;
use App\Support\CurrentOrg;
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

    /**
     * Hard ceiling on the completion. The smallest of the three caps because the answer is the
     * smallest: at most 8 short `key=value` strings. Still sized well above that maximum, so a
     * complete answer is never truncated into an invalid-JSON failure.
     */
    public const MAX_COMPLETION_TOKENS = 400;

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
    PROMPT.NerbyTrustBoundary::TEXT;

    /**
     * The keys a business selector may use — the SAME set the engine enforces
     * (scraper/providers/openstreetmap.py `_CATEGORY_KEYS`). Checked here too, so an out-of-vocabulary
     * selector is never cached and never leaves this process.
     *
     * @var list<string>
     */
    public const ALLOWED_KEYS = ['shop', 'amenity', 'tourism', 'office', 'leisure', 'craft', 'healthcare'];

    /**
     * THIS PROMPT'S OWN VERSION, in the cache key beside the trust boundary's.
     *
     * The key already carried NerbyTrustBoundary::VERSION, which covers the shared security text —
     * but not this lane's own wording. So improving the question could not invalidate the answers
     * cached under the worse one without bumping a constant shared by the proposal and strategy
     * lanes too, which would needlessly discard their caches. Bump this on any change to
     * SYSTEM_PROMPT.
     */
    public const PROMPT_VERSION = '2026-09-21.1';

    /** A tag value: the engine's own pattern (tag_expansion.py VALUE_PATTERN), bounded. */
    private const VALUE_PATTERN = '/^[a-z][a-z0-9_]{0,59}$/';

    private const MAX_CATEGORY_LENGTH = 120;

    /** Caller-supplied already-covered selectors sent to the model; a bound on a prompt input. */
    private const MAX_KNOWN_SELECTORS = 32;

    public function __construct(private readonly NerbyProviderSettingService $settings) {}

    /**
     * @param  list<string>  $knownSelectors
     * @return array{status: string, selectors: list<string>, usage: array<string, mixed>, error: string|null}
     */
    public function expand(string $category, array $knownSelectors = []): array
    {
        // INBOUND: the only runtime values the model sees — the category (cleaned, bounded) and the
        // already-covered selectors (each re-validated to the selector shape, capped). A caller
        // cannot smuggle free text into the prompt through either.
        $category = UntrustedText::clean($category, self::MAX_CATEGORY_LENGTH);
        if ($category === '') {
            return $this->failure(self::STATUS_INVALID_RESPONSE, 'empty_category');
        }
        $known = array_slice($this->validSelectors($knownSelectors), 0, self::MAX_KNOWN_SELECTORS);

        // A CACHED ANSWER IS RETURNED WITHOUT A CALL. Only successful answers are ever cached — see
        // the write below — so this can never pin a failure in place.
        $cached = Cache::get(self::cacheKey($category, $known));
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
        // OpenAI or OpenRouter, chosen by the stored key; OpenRouter gets a vendor-prefixed model id.
        $endpoint = AiChatEndpoint::for((string) $row->api_key, $model);

        try {
            $response = Http::withToken((string) $row->api_key)
                ->timeout((float) config('openai.timeout', 30))
                ->post($endpoint->chatCompletionsUrl(), [
                    'model' => $endpoint->model,
                    // Deterministic: the same category must not yield different tag sets on two runs,
                    // or a cached answer would differ from the one that produced the measurement.
                    'temperature' => 0,
                    // Bounds a runaway completion. See MAX_COMPLETION_TOKENS on why it is generous.
                    'max_tokens' => self::MAX_COMPLETION_TOKENS,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                        [
                            'role' => 'user',
                            // TRUSTED limits beside, never inside, the untrusted target data.
                            'content' => json_encode([
                                'untrusted_target_data' => [
                                    'category' => $category,
                                    'already_covered' => $known,
                                ],
                                'limits' => ['max_results' => self::MAX_SELECTORS],
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
            // ONE mapping for every caller, so a status like 402 is understood identically
            // wherever it lands. See AiChatEndpoint::classify().
            $status = AiChatEndpoint::classify($response->status());
            Log::warning('Nearby tag expansion rejected', [
                'status' => $status,
                'http' => $response->status(),
            ]);

            return $this->failure($status, 'http_'.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || ($selectors = $this->parseSelectors($content, $known)) === null) {
            Log::warning('Nearby tag expansion response was unreadable');

            return $this->failure(self::STATUS_INVALID_RESPONSE, 'unreadable_body');
        }

        // ONLY SUCCESS IS CACHED, and it is cached forever. Caching a failure would turn a transient
        // outage into a permanent "this category has no other tags", which is exactly the confusion
        // the status vocabulary exists to prevent.
        Cache::forever(self::cacheKey($category, $known), $selectors);

        return [
            'status' => self::STATUS_OK,
            'selectors' => $selectors,
            'usage' => [
                'model' => $endpoint->model,
                'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
                'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
            ],
            'error' => null,
        ];
    }

    /**
     * Drop a category's cached expansion so the next call re-asks. The only way an answer changes.
     *
     * TAKES THE SAME ALREADY-COVERED SET THE CALL USED, because that set is part of the key. Calling
     * this with a different one forgets a different entry — which is the point: the entries are
     * genuinely distinct answers, not one answer with an incidental label.
     *
     * @param  list<string>  $knownSelectors
     */
    public static function forget(string $category, array $knownSelectors = []): void
    {
        Cache::forget(self::cacheKey(
            UntrustedText::clean($category, self::MAX_CATEGORY_LENGTH),
            $knownSelectors,
        ));
    }

    /**
     * Keyed by the TRUST-BOUNDARY VERSION, the ORG, the ALREADY-COVERED SET and the category.
     *
     * The version means a hardened prompt cannot be bypassed by answers cached under an older one;
     * the org means one tenant's cached answer is never replayed to another.
     *
     * THE COVERED SET IS IN THE KEY BECAUSE IT IS PART OF THE QUESTION. The prompt tells the model
     * which selectors are already covered and asks for ADDITIONAL ones only, so the same category
     * asked with a different covered set is a different question with a legitimately different
     * answer. Without it the first caller's answer was replayed to every later one — observed in
     * practice: a diagnostic call made with no covered set cached an answer consisting solely of
     * the baseline's own selectors, and that useless answer was then served to the real discovery
     * lane, which had asked the narrower question and would have got usable alternatives. The
     * entries are distinct answers, not one answer under an incidental label.
     *
     * Same fingerprint shape the strategist's coverage ledger uses: sorted, sha1, 12 chars, with an
     * empty set named rather than hashed so an unconstrained ask is obvious in a key.
     *
     * @param  list<string>  $known  already-covered selectors, as sent to the model
     */
    private static function cacheKey(string $category, array $known = []): string
    {
        return self::CACHE_PREFIX
            .NerbyTrustBoundary::VERSION.'|'
            .'p'.self::PROMPT_VERSION.'|'
            .'org:'.(CurrentOrg::id() ?? 'none').'|'
            .'covered:'.self::coveredFingerprint($known).'|'
            .mb_strtolower($category);
    }

    /**
     * A deterministic, order-independent fingerprint of the already-covered set.
     *
     * @param  list<string>  $known
     */
    private static function coveredFingerprint(array $known): string
    {
        $normalized = [];
        foreach ($known as $selector) {
            if (is_string($selector) && ($clean = mb_strtolower(trim($selector))) !== '') {
                $normalized[$clean] = true;
            }
        }

        if ($normalized === []) {
            return 'none';
        }

        $sorted = array_keys($normalized);
        sort($sorted);

        return substr(sha1(implode("\n", $sorted)), 0, 12);
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
     * STRICT SCHEMA: `{"selectors": ["key=value", ...]}`, other keys ignored. Each selector must use
     * an allowed business key and a plain tag value; anything else, anything already covered, and
     * anything past MAX_SELECTORS is dropped — never truncated or repaired. The engine re-checks the
     * same rules (plus its non-business list), so this is defence in depth, not the only line.
     *
     * @param  list<string>  $known  already-covered selectors, excluded from the answer
     * @return list<string>|null
     */
    private function parseSelectors(string $content, array $known = []): ?array
    {
        $data = json_decode($content, true);
        if (! is_array($data) || array_is_list($data) || ! isset($data['selectors'])
            || ! is_array($data['selectors']) || ! array_is_list($data['selectors'])) {
            return null;
        }

        return array_slice(
            array_values(array_diff($this->validSelectors($data['selectors']), $known)),
            0,
            self::MAX_SELECTORS,
        );
    }

    /**
     * `key=value` selectors normalised to lower case, keeping only well-formed ones on an allowed
     * key, de-duplicated in order. Anything that is not a string is dropped rather than coerced.
     *
     * @return list<string>
     */
    private function validSelectors(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $item) {
            $text = UntrustedText::output($item, 121);
            if ($text === null || substr_count($text, '=') !== 1) {
                continue;
            }

            [$key, $value] = array_map(
                static fn (string $part): string => mb_strtolower(trim($part)),
                explode('=', $text, 2),
            );

            if (! in_array($key, self::ALLOWED_KEYS, true) || preg_match(self::VALUE_PATTERN, $value) !== 1) {
                continue;
            }

            $selector = $key.'='.$value;
            if (! in_array($selector, $out, true)) {
                $out[] = $selector;
            }
        }

        return $out;
    }
}
