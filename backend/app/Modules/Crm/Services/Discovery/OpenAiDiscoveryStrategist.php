<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Services\Discovery\Contracts\DiscoveryStrategist;
use App\Modules\Crm\Services\Discovery\Prompts\NerbyDiscoveryInstruction;
use App\Support\CurrentOrg;
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
 * CACHED ON (instruction version, category, scope), WHICH IS THE COST STORY. Whether a niche maps
 * to `shop=chemist`, or which sub-areas a metro region contains, does not change between runs — so
 * a pair is asked about once and reused indefinitely. Only successful answers are cached, so a
 * failure is never pinned in place. Scope is part of the key because sub-areas depend on it;
 * category-only answers would be wrong the moment a second scope asked. The INSTRUCTION VERSION
 * leads the key so that a reviewed prompt change breaks the cache by construction — see
 * {@see cacheKey()} for why a permanent cache without it would apply an upgrade only to niches
 * nobody had searched yet.
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
    /*
     * THE FOUR OUTPUT DIALECTS, named so a caller can reason about them without string literals.
     *
     * Each is a way of EXPRESSING A SEARCH, and no provider speaks all of them: a structured places
     * provider executes queries and place types, an OSM sweep executes tag selectors. Asking for all
     * four always meant generating a set nobody could run and discarding it.
     */
    public const DIALECT_SELECTORS = 'selectors';

    public const DIALECT_QUERIES = 'queries';

    public const DIALECT_PLACE_TYPES = 'placeTypes';

    public const DIALECT_SUB_AREAS = 'subAreas';

    /** Every dialect. The default, and what a caller that names no provider still receives. */
    public const ALL_DIALECTS = [
        self::DIALECT_SELECTORS,
        self::DIALECT_QUERIES,
        self::DIALECT_PLACE_TYPES,
        self::DIALECT_SUB_AREAS,
    ];

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

    /**
     * Hard ceiling on the completion, so a runaway generation cannot bill without bound.
     *
     * SIZED ABOVE THE SCHEMA'S OWN MAXIMUM, ON PURPOSE. The largest answer this class will accept is
     * 8 selectors + 8 queries + 8 place types + 16 sub-areas, each at most MAX_VALUE_LENGTH — about
     * 1,200 tokens of JSON at the absolute worst. The cap sits above that so a well-formed maximal
     * answer can never be cut off: a truncated completion is invalid JSON, which this class reports
     * as STATUS_INVALID_RESPONSE, and silently converting "the model answered fully" into "the model
     * failed" would be a far worse bug than the spend this bounds. It exists to stop a pathological
     * generation, not to trim a legitimate one.
     */
    public const MAX_COMPLETION_TOKENS = 1500;

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

    public function __construct(
        private readonly NerbyProviderSettingService $settings,
        private readonly ExecutedQueryLedger $ledger,
        private readonly QueryFeedback $feedback,
        private readonly StrategyOutcomeLog $outcomes,
    ) {}

    /**
     * Note a consultation that produced no query this search can run.
     *
     * WHY BOTH RETURN PATHS CALL THIS. A cached empty plan and a freshly generated one are the same
     * fact for the run — this target got no usable phrase — so recording only the live path would
     * hide most of the saturation, a permanently cached question never reaching the model again.
     * They are marked apart so cost stays readable.
     *
     * ONLY WHERE QUERIES WERE ASKED FOR. A plan with no `queries` is not a refusal when queries were
     * never a requested dialect — on the OpenStreetMap lane that is the correct, complete answer,
     * and logging it would manufacture an exhaustion signal out of a provider difference.
     *
     * @param  array{queries: list<string>}  $strategy
     * @param  list<string>  $dialects
     * @param  list<string>  $covered
     */
    private function noteEmptyPlan(
        string $category,
        string $scope,
        array $strategy,
        array $dialects,
        array $covered,
        string $source,
    ): void {
        if (! in_array(self::DIALECT_QUERIES, $dialects, true) || $strategy['queries'] !== []) {
            return;
        }

        $this->outcomes->recordEmptyPlan($category, $scope, $source, $dialects, count($covered));
    }

    /**
     * A plan that cannot shape the search it was asked for.
     *
     * WHY THIS IS NOT CACHEABLE, THOUGH THE CALL SUCCEEDED. Pinning it closes a loop that cannot
     * reopen: the plan carries no query, so no phrase executes, so the ledger cannot change, so the
     * coverage fingerprint in the cache key cannot change — and the same entry answers this target
     * forever without the model ever being asked again. Measured on run 85: 77 of 100 targets served
     * by a cached empty plan, every one of them a cache hit, and not one live call among them. It is
     * the same reason a failure is not cached, applied to a non-answer that happens to arrive with a
     * 200.
     *
     * IT IS JUDGED ON QUERIES, NOT ON EMPTINESS, and the difference is the whole fix. The pinned
     * entries were not empty — they carried a place type and no queries — and because the category
     * was in the engine's static type table that place type was discarded before the search. So an
     * "is the plan empty" test would have matched none of the 77.
     *
     * ONLY WHERE QUERIES WERE ASKED FOR. On the OpenStreetMap lane queries are not a requested
     * dialect and an empty list is the correct, complete answer; refusing to cache it there would
     * spend a model call on every repeat of a question already answered properly.
     *
     * @param  array{queries: list<string>}  $strategy
     * @param  list<string>  $dialects
     */
    private function isUnusable(array $strategy, array $dialects): bool
    {
        return in_array(self::DIALECT_QUERIES, $dialects, true) && $strategy['queries'] === [];
    }

    /**
     * @param  list<string>  $knownSelectors
     * @return array{status: string, strategy: array{selectors: list<string>, queries: list<string>, placeTypes: list<string>, subAreas: list<string>}, usage: array<string, mixed>, error: string|null}
     */
    public function plan(
        string $category,
        string $scope = '',
        array $knownSelectors = [],
        ?int $limit = null,
        ?NerbyProvider $provider = null,
    ): array {
        // SANITISED AT THE DOOR, not just at the form request: this service is reachable directly,
        // and a boundary that only holds for one caller is not a boundary.
        $category = $this->sanitize($category, self::MAX_CATEGORY_LENGTH);
        $scope = $this->sanitize($scope, self::MAX_SCOPE_LENGTH);
        $knownSelectors = $this->strings($knownSelectors, self::MAX_KNOWN_SELECTORS);

        // WHICH OUTPUT SHAPES THIS SEARCH CAN ACTUALLY RUN. Resolved before the cache is read
        // because two providers asking about the same niche are asking DIFFERENT questions, and
        // their answers must not share an entry.
        $dialects = $this->dialectsFor($provider);

        // WHAT HAS ALREADY BEEN COVERED, EXPRESSED IN THE DIALECT BEING ASKED FOR.
        //
        // The parameter has always carried OSM selectors, which no caller ever populated and which a
        // places provider is not even asked for any more — so on the live Google path the model was
        // told nothing about what had been searched, and a cached plan repeated the same phrases on
        // every run forever. When queries are the dialect, the phrases this CRM actually dispatched
        // are the honest answer; otherwise the caller's selectors stand, unchanged.
        //
        // Sanitised on the way in exactly like every other untrusted value: these phrases originated
        // from a model, and a value that has been out of this system comes back in as data.
        $covered = in_array(self::DIALECT_QUERIES, $dialects, true)
            ? $this->strings($this->ledger->covered($category, $scope), self::MAX_KNOWN_SELECTORS)
            : $knownSelectors;

        // WHAT MEASUREMENT HAS SHOWN about phrases tried here before. Resolved before the cache,
        // because a question asked with new measurements is a DIFFERENT question — a permanent cache
        // that ignored them would keep answering the pre-measurement one forever.
        //
        // MEASURED IS NOT COVERED. This informs; it excludes nothing. A probed phrase has not
        // necessarily been searched in production, and only `$covered` may remove a phrase from a
        // plan. Empty whenever feedback is switched off, or when queries are not the dialect —
        // verdicts are about query phrases and nothing else measures them.
        $measured = in_array(self::DIALECT_QUERIES, $dialects, true)
            ? $this->feedback->reportFor($category, $scope)
            : [];

        $cached = Cache::get($this->cacheKey($category, $scope, $dialects, $covered, $measured));
        if (is_array($cached)) {
            $shaped = $this->shape($cached);
            $this->noteEmptyPlan($category, $scope, $shaped, $dialects, $covered, StrategyOutcomeLog::SOURCE_CACHE);

            return [
                'status' => self::STATUS_OK,
                'strategy' => $shaped,
                // Zeroed rather than absent: this call really did cost nothing, which is a different
                // fact from "cost was not measured".
                'usage' => [
                    'model' => 'cache',
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    // Which instruction this plan was produced under. On a hit it is necessarily the
                    // current one — the version is part of the key, so an older entry cannot be read
                    // here at all. Reported anyway so a reader never has to know that to trust it.
                    'prompt_version' => NerbyDiscoveryInstruction::VERSION,
                    'requested_dialects' => $dialects,
                ],
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
                    // Bounds a runaway completion. See MAX_COMPLETION_TOKENS on why it is generous.
                    'max_tokens' => self::MAX_COMPLETION_TOKENS,
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
                                    // Kept inside the UNTRUSTED envelope even though the CRM
                                    // dispatched these itself: they began as model output, and
                                    // anything that has been out of this system returns as data.
                                    'already_covered' => $covered,
                                    // INSIDE THE UNTRUSTED ENVELOPE, deliberately. The verdicts are
                                    // computed by this backend, but they are KEYED BY PHRASES that
                                    // began as model output — and a fact is only as trusted as its
                                    // least trusted part. The verdict itself is a closed enum, so
                                    // nothing here can carry injected content.
                                    //
                                    // A SEPARATE LIST FROM `already_covered`, never merged into it:
                                    // measured and executed are different sets, and conflating them
                                    // would suppress searches that never actually ran.
                                    'measured' => $measured,
                                ],
                                // TRUSTED BACKEND FACT, and deliberately NOT inside
                                // `untrusted_target_data`: this says what the CRM can execute, it
                                // never originates outside this system, and it must not be
                                // confusable with anything the target supplied. It is still only a
                                // statement about OUTPUT SHAPE — it tells the model nothing about
                                // the world, which is why it is safe to trust.
                                'requested_dialects' => $dialects,
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
            // ONE mapping for every caller, so a status like 402 is understood identically
            // wherever it lands. See AiChatEndpoint::classify().
            $status = AiChatEndpoint::classify($response->status());

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

        // ENFORCED, NOT REQUESTED. The instruction asks for the named dialects only; a model that
        // answers in a dialect nobody can execute has its extra values dropped here. The prompt
        // saves the tokens, this guarantees the shape — the same "ask politely, enforce in code"
        // split the rest of this class already uses.
        $strategy = $this->shape([
            'selectors' => in_array(self::DIALECT_SELECTORS, $dialects, true)
                ? $this->strings($decoded['selectors'] ?? null, self::MAX_SELECTORS) : [],
            'queries' => in_array(self::DIALECT_QUERIES, $dialects, true)
                ? $this->rankedUsable(
                    $this->strings($decoded['queries'] ?? null, self::MAX_QUERIES),
                    $covered,
                )
                : [],
            'placeTypes' => in_array(self::DIALECT_PLACE_TYPES, $dialects, true)
                ? $this->placeTypes($decoded['placeTypes'] ?? null) : [],
            'subAreas' => in_array(self::DIALECT_SUB_AREAS, $dialects, true)
                ? $this->strings($decoded['subAreas'] ?? null, self::MAX_SUB_AREAS) : [],
        ]);

        // Only successful answers are cached — a failure must never be pinned in place, and a plan
        // that cannot shape a search is not an answer either. See {@see isUnusable()}.
        if (! $this->isUnusable($strategy, $dialects)) {
            Cache::forever($this->cacheKey($category, $scope, $dialects, $covered, $measured), $strategy);
        }

        $this->noteEmptyPlan($category, $scope, $strategy, $dialects, $covered, StrategyOutcomeLog::SOURCE_LIVE);

        return [
            'status' => self::STATUS_OK,
            'strategy' => $strategy,
            'usage' => [
                'model' => $endpoint->model,
                'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
                'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
                // Pinned beside the model, for the same reason the model is: a plan stays
                // explainable after the instruction moves on. `model` alone cannot explain an
                // answer whose wording came from a prompt that no longer exists.
                'prompt_version' => NerbyDiscoveryInstruction::VERSION,
                // What was asked for, so a plan with an empty dialect is readable as "not wanted"
                // rather than as "the model had nothing".
                'requested_dialects' => $dialects,
            ],
            'error' => null,
        ];
    }

    /**
     * Drop a cached plan so the question is asked again.
     *
     * Clears the entry for EVERY dialect set, because a plan is now cached per (provider shape,
     * category, scope): forgetting only one shape would leave the same niche answered from cache
     * for a different provider, which is the confusing half-state this method exists to avoid.
     */
    public function forget(string $category, string $scope = ''): void
    {
        $category = trim($category);
        $scope = trim($scope);

        // Both the untouched state and the coverage this target actually has: those are the two
        // fingerprints a live entry can be under, and clearing only one would leave a plan cached.
        $coverages = [[], $this->ledger->covered($category, $scope)];

        foreach ([self::ALL_DIALECTS, ...array_map(
            fn (NerbyProvider $provider): array => $this->dialectsFor($provider),
            NerbyProvider::cases(),
        )] as $dialects) {
            foreach ($coverages as $covered) {
                // Both the no-feedback state and whatever feedback this target currently has: the
                // two identities a live entry can be under.
                foreach ([[], $this->feedback->reportFor($category, $scope)] as $measured) {
                    Cache::forget($this->cacheKey($category, $scope, $dialects, $covered, $measured));
                }
            }
        }
    }

    /**
     * THE INSTRUCTION VERSION IS PART OF THE KEY, and that is the whole point of this method.
     *
     * Plans are cached with `Cache::forever`, so without the version every answer produced by an
     * older instruction would outlive it indefinitely: the prompt could be rewritten, reviewed and
     * deployed, and a (category, scope) that had already been asked would keep returning the OLD
     * model's plan for the life of the cache — with nothing anywhere recording which text produced
     * it. A prompt upgrade would silently apply only to niches nobody had searched yet, which is the
     * worst possible half-state for a change that is reviewed as a security contract.
     *
     * Including it makes a version bump a clean, automatic cache break: new keys miss, are asked
     * again under the new instruction, and the old entries simply age out of use. No migration, no
     * manual flush, and no window in which two instructions are both in force without a way to tell
     * which one answered.
     */
    private function cacheKey(
        string $category,
        string $scope,
        array $dialects,
        array $covered,
        array $measured = [],
    ): string {
        return self::CACHE_PREFIX
            .NerbyDiscoveryInstruction::VERSION.'|'
            // THE TENANT IS PART OF THE KEY. Plans are cached forever, and without it one org's cached
            // answer was replayed to another (and `usage.model: cache` told the second tenant that
            // the first had already planned this niche and area). Coverage and feedback were already
            // org-scoped; now the plan itself is.
            .'org:'.(CurrentOrg::id() ?? 'none').'|'
            .implode(',', $dialects).'|'
            // COVERAGE IS PART OF THE QUESTION. "What else should I search for this niche, given I
            // have already tried these three phrases" is a different question from the one asked on
            // a fresh target, and a permanent cache that ignored the difference would answer the
            // first question forever — which is exactly the repetition this is meant to end.
            .$this->ledger->fingerprint($covered).'|'
            // FEEDBACK IS PART OF THE QUESTION TOO. Measurements change what a good answer looks
            // like, so a plan formed before them must not be replayed after them.
            .$this->feedback->fingerprint($measured).'|'
            .mb_strtolower($category).'|'
            .mb_strtolower($scope);
    }

    /**
     * Which dialects the provider that will execute this search can actually run.
     *
     * NULL ASKS FOR EVERYTHING, and that is the pre-existing behaviour preserved exactly: a caller
     * that does not know which provider will run must not have one guessed on its behalf, and every
     * caller that existed before this parameter keeps the answer it always got.
     *
     * SUB-AREAS ARE ALWAYS REQUESTED, whatever the provider. They are not a provider dialect at all
     * — they are advice for PARTITIONING a scope that is too large to search in one request, which
     * is a question about geography rather than about query syntax, and the partitioner that uses
     * them sits upstream of every provider. Dropping them for a specific provider would silently
     * disable partitioning for that provider's runs.
     *
     * AN UNRECOGNISED PROVIDER FALLS BACK TO EVERYTHING rather than to nothing. A new provider added
     * to the enum without being taught here must lose a token saving, never lose its strategy.
     *
     * @return list<string>
     */
    private function dialectsFor(?NerbyProvider $provider): array
    {
        return match ($provider) {
            // A structured places provider takes free text and its own type vocabulary. It cannot
            // execute an OSM tag selector, and `selectors` is consumed by nothing on this path.
            NerbyProvider::GOOGLE_PLACES => [
                self::DIALECT_QUERIES,
                self::DIALECT_PLACE_TYPES,
                self::DIALECT_SUB_AREAS,
            ],
            // Both OSM lanes sweep Overpass by tag. A text query has nothing to execute it, and the
            // AI-assisted lane still resolves everything it proposes against OSM.
            NerbyProvider::OPENSTREETMAP, NerbyProvider::OPENAI => [
                self::DIALECT_SELECTORS,
                self::DIALECT_SUB_AREAS,
            ],
            null => self::ALL_DIALECTS,
        };
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
     * The phrases worth spending a slot on, IN THE ORDER THE MODEL RANKED THEM.
     *
     * WHY ORDER IS THE WHOLE POINT. The provider executes only its first few phrases and discards
     * the rest, so position decides which searches actually happen. The instruction asks for
     * descending expected usefulness; this method's job is to make sure nothing downstream disturbs
     * that ranking, and that a leading slot is never spent on a search that cannot pay for itself.
     *
     * IT DOES NOT RE-RANK. Judging which phrasing will find more businesses is exactly the judgement
     * the model is better placed to make, and a backend heuristic would only fight it. Order is
     * preserved untouched; entries are removed, never moved.
     *
     * TWO KINDS OF ENTRY ARE REMOVED, both because they would waste a slot rather than because they
     * are wrong:
     *
     *   - ALREADY SEARCHED. A phrase in the E2 ledger has run for this target, so running it again
     *     is a paid request guaranteed to return ground already covered.
     *   - A NEAR-DUPLICATE OF SOMETHING ALREADY KEPT. Two phrases that a text provider reads as the
     *     same search spend two of three slots on one search. The instruction asks the model not to
     *     produce them; this is what happens when it does anyway.
     *
     * FIRST OCCURRENCE WINS, which is what makes removal safe: when two phrases collapse, the one
     * the model ranked higher is the one that survives.
     *
     * QUERIES ONLY. The selector lane's covered list is the caller's own advisory input, and its
     * contract has always said the caller de-duplicates — filtering it here would change how the
     * OpenStreetMap path behaves, which this is not allowed to do.
     *
     * @param  list<string>  $values  the model's answer, in its ranked order
     * @param  list<string>  $covered  phrases already searched for this target
     * @return list<string>
     */
    private function rankedUsable(array $values, array $covered): array
    {
        $spent = [];
        foreach ($covered as $phrase) {
            $spent[$this->canonical($phrase)] = true;
        }

        $out = [];
        foreach ($values as $value) {
            $key = $this->canonical($value);

            // A phrase with no letters or digits is not a search term at all.
            if ($key === '' || isset($spent[$key])) {
                continue;
            }

            $spent[$key] = true;
            $out[] = $value;
        }

        return $out;
    }

    /**
     * The form in which two phrases are THE SAME SEARCH.
     *
     * Lower-cased, stripped of everything that is not a letter or a digit, and with the remaining
     * words sorted — so case, punctuation, spacing and word order stop distinguishing phrases that
     * a text provider would answer identically.
     *
     * DELIBERATELY NOT A STEMMER. Nothing is truncated to a root and no suffix is stripped, because
     * that is language-specific: a rule that folds an English plural also mangles words in the local
     * vocabulary this system is meant to search in, and wrongly collapsing two genuinely different
     * phrasings costs a real search that would have found real businesses. Requiring the same words
     * is conservative on purpose — it catches the restatements, and leaves anything arguable alone.
     */
    private function canonical(string $value): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($words);

        return implode(' ', $words);
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
        // SHAPE, THEN VOCABULARY. A well-formed invention ("artisan_bakery") is still not a type the
        // provider accepts, and one sent as `includedType` made Google refuse the whole request —
        // which used to push the rest of a scoped run onto OpenStreetMap. Only Google's own Table A
        // survives; everything else is dropped and the search runs on text.
        return array_values(array_filter(
            $this->strings($values, self::MAX_PLACE_TYPES),
            fn (string $type): bool => preg_match(self::PLACE_TYPE_PATTERN, $type) === 1
                && GooglePlaceTypes::isIncludedType($type),
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
