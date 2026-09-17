<?php

namespace App\Modules\Crm\Services\Discovery\Contracts;

/**
 * Produces a DISCOVERY STRATEGY — how to search, never what was found.
 *
 * WHY THIS EXISTS BESIDE {@see TagExpander}. Tag expansion answers one provider's question: which
 * OpenStreetMap tags a category hides under. That answer is useless to a structured places provider,
 * which has its own type vocabulary and takes free-text queries. So the moment a second provider
 * existed, the AI layer could only assist one of them — an AI-assisted Google run got no assistance
 * at all. This contract is the provider-NEUTRAL form of the same idea: one question, answered in
 * whichever dialects the caller can execute.
 *
 * FOUR KINDS OF ANSWER, ALL OF THEM QUERIES:
 *  - `selectors`  — OSM `key=value` selectors (what TagExpander returns; unchanged in meaning).
 *  - `queries`    — free-text search phrases for a text-search provider.
 *  - `placeTypes` — structured place-type identifiers for a provider that filters by type.
 *  - `subAreas`   — names of SMALLER areas inside the requested scope, for partitioning a scope too
 *                   large to answer in one request.
 *
 * NOT ONE OF THEM IS A BUSINESS RECORD, AND THAT IS THE ENTIRE SAFETY ARGUMENT. Every field above
 * describes a search. A wrong selector matches nothing; a wrong query returns nothing; a wrong place
 * type is refused by the provider; a wrong sub-area fails to geocode and is dropped before it is ever
 * searched. There is no shape of wrong answer here that invents a business, because none of these
 * values can describe one. Identity, coordinates, phone and website still come only from the
 * deterministic provider that executes the search.
 *
 * SUB-AREAS ARE PROPOSALS, NEVER GEOGRAPHY. The model may name smaller areas, but nothing downstream
 * trusts a name: each is resolved by the geocoder, and one that does not resolve is discarded. That
 * is the same discipline the name lane already uses — the model proposes, a deterministic source
 * disposes — applied to geography instead of to businesses. It is also why no place list appears
 * anywhere in this codebase: the areas come from runtime data or from a proposal that had to survive
 * a geocoder, never from a literal.
 *
 * The status vocabulary is deliberately identical to {@see ProposalGenerator} and {@see TagExpander},
 * so the scraper reads one set of outcomes across every AI capability rather than learning a third.
 */
interface DiscoveryStrategist
{
    /** The call succeeded — the strategy is the model's real answer, even where it is empty. */
    public const STATUS_OK = 'ok';

    /** The credential was rejected. NOT the same as "no strategy applies". */
    public const STATUS_AUTH_FAILED = 'auth_failed';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    /** Timeout, DNS, socket, or any non-2xx that is not auth/rate-limit. */
    public const STATUS_UNREACHABLE = 'unreachable';

    /** A 2xx whose body could not be understood — a real failure, not an empty strategy. */
    public const STATUS_INVALID_RESPONSE = 'invalid_response';

    /** No credential stored, so no call was attempted. */
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    /**
     * How to search for `$category` businesses, optionally within `$scope`.
     *
     * @param  string  $category  A discovery category word or niche description, e.g. "restaurant".
     * @param  string  $scope  The free-text area the caller intends to search. THE TARGET'S REAL
     *                         AREA, passed so the model can reason about the actual target rather
     *                         than a bare category word: local trade names differ by place, and a
     *                         scope large enough to need splitting can only be recognised from it.
     *                         It is never echoed back as data and never becomes a default.
     * @param  list<string>  $knownSelectors  Selectors already swept, so the model ADDS rather than
     *                                        repeats. Advisory only — the caller deduplicates anyway.
     * @param  int|null  $limit  How many businesses the run wants, as CONTEXT only. It calibrates how
     *                           broad a strategy is worth proposing; it caps nothing, and no caller
     *                           may treat a returned value as sized to it.
     * @return array{
     *     status: string,
     *     strategy: array{selectors: list<string>, queries: list<string>, placeTypes: list<string>, subAreas: list<string>},
     *     usage: array<string, mixed>,
     *     error: string|null
     * }
     *
     * A non-OK `status` returns an EMPTY strategy, for the same reason the other capabilities return
     * an empty list: "this category needs no extra strategy" and "the key was rejected" are both
     * nothing, and reporting them identically would let a broken integration look like a settled
     * question forever. Only `STATUS_OK` means the strategy is the model's actual answer.
     *
     * Values are returned UNVALIDATED. Validation belongs with the consumer that will execute them —
     * next to the OSM key vocabulary, the place-type vocabulary or the geocoder it already owns.
     * Validating here would split that knowledge across two processes and let the two drift.
     */
    public function plan(
        string $category,
        string $scope = '',
        array $knownSelectors = [],
        ?int $limit = null,
    ): array;
}
