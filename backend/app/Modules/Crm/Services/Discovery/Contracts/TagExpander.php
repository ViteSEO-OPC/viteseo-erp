<?php

namespace App\Modules\Crm\Services\Discovery\Contracts;

/**
 * Produces OSM TAG SELECTORS for a category — search strategy, never business data.
 *
 * WHAT THIS ANSWERS. The discovery sweep looks for businesses by OpenStreetMap tag, from a short
 * hand-written list: `pharmacy` means `amenity=pharmacy` and nothing else. OSM holds the same kind of
 * business under tags that list does not cover — a chemist under `shop=chemist`, a fast-food outlet
 * under `amenity=fast_food` rather than `amenity=restaurant`. Measured on live Vigan data, the
 * restaurant category alone gains 68% from three additional tags. This interface asks a model which
 * tags a category can hide under.
 *
 * WHY IT IS SAFER THAN NAMING BUSINESSES, not merely different. A returned selector is a QUERY, not a
 * record. It is checked against an allowlist of OSM keys, and whatever survives is handed to Overpass
 * — which then supplies every business, every `provider_id`, every coordinate. A hallucinated tag
 * matches nothing and the sweep comes back empty; there is no shape of wrong answer that invents a
 * business, because the model's output never describes one.
 *
 * NO AREA IN THE SIGNATURE, AND THAT IS DELIBERATE. Whether `amenity=fast_food` belongs with
 * restaurants is a fact about OSM's tagging taxonomy, not about a town. Leaving the area out is what
 * makes a single answer valid everywhere and cacheable indefinitely — which is in turn what keeps
 * this capability's steady-state cost at zero calls.
 *
 * The status vocabulary is deliberately identical to {@see ProposalGenerator}'s, so the scraper reads
 * one set of outcomes across both AI capabilities rather than learning a second dialect.
 */
interface TagExpander
{
    /** The call succeeded — `selectors` is the model's real answer, even if it is empty. */
    public const STATUS_OK = 'ok';

    /** The credential was rejected. NOT the same as "no additional tags exist". */
    public const STATUS_AUTH_FAILED = 'auth_failed';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    /**
     * The credential is valid but the account behind it is out of credit.
     *
     * Kept separate from {@see STATUS_UNREACHABLE} because the two demand opposite responses: an
     * unreachable provider is usually transient and worth retrying later, while this one will fail
     * identically on every future call until a human tops up the account. Collapsing them sends an
     * operator hunting for a network fault that does not exist.
     */
    public const STATUS_INSUFFICIENT_CREDIT = 'insufficient_credit';

    /** Timeout, DNS, socket, or any non-2xx that is not auth/rate-limit. */
    public const STATUS_UNREACHABLE = 'unreachable';

    /** A 2xx whose body could not be understood — a real failure, not an empty result. */
    public const STATUS_INVALID_RESPONSE = 'invalid_response';

    /** No credential stored, so no call was attempted. */
    public const STATUS_NOT_CONFIGURED = 'not_configured';

    /**
     * Additional `key=value` OSM selectors worth sweeping for `$category`.
     *
     * @param  string  $category  A discovery category word, e.g. "restaurant".
     * @param  list<string>  $knownSelectors  Selectors already swept, so the model ADDS rather than
     *                                        repeats. Advisory only — the caller deduplicates anyway.
     * @return array{status: string, selectors: list<string>, usage: array<string, mixed>, error: string|null}
     *
     * A non-OK `status` returns an empty list, for the same reason it does on the proposal path: "the
     * category has no other tags" and "the key was rejected" are both zero selectors, and reporting
     * them identically would let a broken integration look like a settled question forever.
     *
     * Selectors are returned UNVALIDATED. Validation belongs with the consumer that will execute
     * them, next to the OSM key vocabulary it already owns — putting it here would split that
     * knowledge across two processes and let the two drift.
     */
    public function expand(string $category, array $knownSelectors = []): array;
}
