<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Requests\DiscoveryPlacesRequest;
use App\Modules\Crm\Requests\DiscoveryProposalRequest;
use App\Modules\Crm\Requests\DiscoveryStrategyRequest;
use App\Modules\Crm\Requests\DiscoveryTagExpansionRequest;
use App\Modules\Crm\Services\Discovery\Contracts\DiscoveryStrategist;
use App\Modules\Crm\Services\Discovery\Contracts\ProposalGenerator;
use App\Modules\Crm\Services\Discovery\Contracts\TagExpander;
use App\Modules\Crm\Services\Discovery\GooglePlacesTextSearch;
use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The discovery service's window onto its own configuration — and the only way it can reach OpenAI.
 *
 * TWO ENDPOINTS, ONE PURPOSE: let the scraper act on the provider an admin chose in Settings without
 * ever handling the credential that makes it work.
 *
 * `config` ANSWERS WITH THE EFFECTIVE PROVIDER, NOT THE SELECTED ONE. This is the design decision
 * that keeps the fallback rule in one place. "OpenAI is selected but has no key" resolves to
 * OpenStreetMap here, inside the CRM, so the scraper needs no fallback logic of its own — it does
 * what it is told and records what it did. A second copy of that rule in Python would be a copy that
 * eventually disagrees.
 *
 * `tagExpansions` ASKS FOR A SEARCH STRATEGY RATHER THAN A RESULT. The model returns OSM tag
 * selectors — `shop=chemist` beside `amenity=pharmacy` — and Overpass then supplies every business
 * those tags match. A wrong selector describes a query that finds nothing; it cannot invent a
 * business, because the answer never describes one. That makes it a smaller trust surface than
 * `proposals`, not merely a different one.
 *
 * `proposals` MAKES THE OPENAI CALL SERVER-SIDE. The scraper sends an area and a category and gets
 * back names. It never receives, stores or logs the API key, because the key never leaves this
 * process. Names are a hypothesis: the scraper's deterministic OSM resolver confirms each one before
 * anything can become a prospect, so nothing here can invent a `place_id`, a coordinate, or an
 * identity.
 *
 * Authorized by `can('import', Lead::class)` — the same permission the enrichment ingest and audit
 * queue use, because this is the same service doing the same job. `proposals` is additionally
 * throttled: it spends money per call, so it must not be usable as a load generator.
 */
class DiscoveryConfigController extends Controller
{
    public function __construct(private readonly NerbyProviderSettingService $settings) {}

    /**
     * Which provider the scraper should actually use, and why.
     *
     * Deliberately carries no credential, no model and no provider options: the scraper has no use
     * for any of them now that the CRM makes the outbound call, and the smallest payload that answers
     * the question is the one that cannot leak anything.
     */
    public function config(): JsonResponse
    {
        // Not `resolveQuietly`: a scraper run that silently gets the baseline instead of the selected
        // provider is precisely the condition an operator needs to find in the log.
        $effective = $this->settings->resolve();

        return response()->json([
            'data' => [
                'effectiveProvider' => $effective->provider->value,
                'configuredProvider' => $effective->configured->value,
                'reason' => $effective->reason,
                'mode' => $effective->mode,
                'isFallback' => $effective->isFallback(),
            ],
        ]);
    }

    /**
     * Candidate business names for one (area, category) pair.
     *
     * REFUSES unless the effective provider is OpenAI. A well-behaved scraper checks `config` first
     * and never asks; refusing anyway means a caller that skipped that step cannot spend money on a
     * provider the admin has not actually enabled. 409 rather than 403 — the request is authorized,
     * the system is simply not in a state where it makes sense.
     */
    public function proposals(
        DiscoveryProposalRequest $request,
        ProposalGenerator $generator,
    ): JsonResponse {
        $effective = $this->settings->resolveQuietly();

        if ($effective->provider !== NerbyProvider::OPENAI) {
            return response()->json([
                'message' => 'AI-assisted discovery is not the effective provider.',
                'data' => [
                    'effectiveProvider' => $effective->provider->value,
                    'reason' => $effective->reason,
                    'proposals' => [],
                ],
            ], Response::HTTP_CONFLICT);
        }

        $result = $generator->propose(
            (string) $request->validated('area'),
            (string) $request->validated('category'),
            (int) ($request->validated('count') ?? 20),
        );

        $payload = [
            'effectiveProvider' => $effective->provider->value,
            // WHETHER THE CALL WORKED, always — never inferred from the list being empty. A rejected
            // credential and a genuinely empty answer are both zero names, and a caller that could
            // only see the list would report a broken integration as a successful quiet run.
            'executionStatus' => $result['status'],
            'errorReason' => $result['error'],
            // Names only. Nothing here is authoritative and nothing here is a credential.
            'proposals' => $result['proposals'],
            // Token counts so the scraper's run metrics can carry cost without ever seeing a key.
            'usage' => $result['usage'],
        ];

        if ($result['status'] !== ProposalGenerator::STATUS_OK) {
            // 502: the CRM is fine, its upstream provider is not. Deliberately NOT 200 — a scraper
            // that only checked the HTTP status would otherwise treat a failed model call as a
            // successful discovery of nothing, which is the exact confusion this endpoint must avoid.
            return response()->json([
                'message' => 'The discovery provider could not be reached or refused the request.',
                'data' => $payload,
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Additional OSM tag selectors worth sweeping for one category.
     *
     * REFUSES unless the effective provider is OpenAI, exactly as `proposals` does and for the same
     * reason: a caller that skipped `config` must not be able to spend money on a provider the admin
     * has not enabled.
     *
     * Selectors leave here UNVALIDATED against the OSM key vocabulary. That check belongs with the
     * scraper, which owns the tag vocabulary it is about to execute; duplicating it here would create
     * a second copy that eventually disagrees with the one that matters.
     */
    public function tagExpansions(
        DiscoveryTagExpansionRequest $request,
        TagExpander $expander,
    ): JsonResponse {
        $effective = $this->settings->resolveQuietly();

        if ($effective->provider !== NerbyProvider::OPENAI) {
            return response()->json([
                'message' => 'AI-assisted discovery is not the effective provider.',
                'data' => [
                    'effectiveProvider' => $effective->provider->value,
                    'reason' => $effective->reason,
                    'selectors' => [],
                ],
            ], Response::HTTP_CONFLICT);
        }

        /** @var list<string> $known */
        $known = array_values((array) ($request->validated('knownSelectors') ?? []));

        $result = $expander->expand((string) $request->validated('category'), $known);

        $payload = [
            'effectiveProvider' => $effective->provider->value,
            // Whether the call worked, always — never inferred from the list being empty. A narrow
            // category legitimately has no additional tags, and that must not read as a failure.
            'executionStatus' => $result['status'],
            'errorReason' => $result['error'],
            // Search strategy only. Nothing here is a business, and nothing here is a credential.
            'selectors' => $result['selectors'],
            'usage' => $result['usage'],
        ];

        if ($result['status'] !== TagExpander::STATUS_OK) {
            // 502 for the same reason as `proposals`: a scraper that only read the HTTP status must
            // not mistake a failed model call for a category that genuinely has no other tags.
            return response()->json([
                'message' => 'The discovery provider could not be reached or refused the request.',
                'data' => $payload,
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Google Places (API New) Text Search, made HERE with the encrypted credential. The scraper sends an
     * area, a category, an optional Places type and the area's bounding box, and gets business records
     * back in the provider-neutral discovery shape — never the credential.
     */
    public function places(DiscoveryPlacesRequest $request, GooglePlacesTextSearch $places): JsonResponse
    {
        $effective = $this->settings->resolveQuietly();

        if ($effective->provider !== NerbyProvider::GOOGLE_PLACES) {
            return response()->json([
                'message' => 'Google Places is not the effective provider.',
                'data' => [
                    'effectiveProvider' => $effective->provider->value,
                    'reason' => $effective->reason,
                    'businesses' => [],
                ],
            ], Response::HTTP_CONFLICT);
        }

        $includedType = $request->validated('includedType');

        $result = $places->search(
            (string) $request->validated('area'),
            (string) $request->validated('category'),
            (int) ($request->validated('limit') ?? GooglePlacesTextSearch::PAGE_SIZE),
            is_string($includedType) ? $includedType : null,
            $request->boundingBox(),
            $request->plannedQueries(),
        );

        $payload = [
            'effectiveProvider' => $effective->provider->value,
            'executionStatus' => $result['status'],
            'errorReason' => $result['error'],
            'businesses' => $result['places'],
            'pages' => $result['pages'],
            // How many planned phrases were actually run. Reported because "AI proposed three
            // queries" and "three queries were paid for" are different facts, and only the second
            // one is a cost.
            'queriesExecuted' => $result['queries_executed'] ?? 1,
        ];

        if ($result['status'] !== GooglePlacesTextSearch::STATUS_OK) {
            // 502, like `proposals`: a failed Google call must never read as an area with no businesses.
            return response()->json([
                'message' => 'The discovery provider could not be reached or refused the request.',
                'data' => $payload,
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * HOW to search — the provider-neutral AI strategy capability.
     *
     * DELIBERATELY NOT GATED ON THE EFFECTIVE PROVIDER, unlike `proposals` and `places`. Those ask a
     * provider to DO something, so asking one that is not running would be incoherent. This asks how
     * a search should be SHAPED, and that answer is useful to whichever provider is executing — which
     * is the entire point of making the capability provider-neutral. Gating it the other way is what
     * previously meant an AI-assisted Google run received no assistance at all.
     *
     * It is still gated on AI being configured AND enabled, because it spends money per call. 409
     * when it is not: the request is authorized, the optional capability is simply switched off, and
     * a caller must treat that as "no advice" rather than as an error.
     */
    public function strategy(
        DiscoveryStrategyRequest $request,
        DiscoveryStrategist $strategist,
    ): JsonResponse {
        $ai = $this->settings->for(NerbyProvider::OPENAI);
        $empty = ['selectors' => [], 'queries' => [], 'placeTypes' => [], 'subAreas' => []];

        if (! $ai->exists || ! $ai->hasApiKey() || ! $ai->enabled) {
            return response()->json([
                'message' => 'AI-assisted discovery strategy is not available.',
                'data' => [
                    'available' => false,
                    'reason' => $ai->exists && $ai->hasApiKey() ? 'provider_disabled' : 'provider_not_configured',
                    'strategy' => $empty,
                ],
            ], Response::HTTP_CONFLICT);
        }

        $known = $request->validated('knownSelectors');

        $limit = $request->validated('limit');

        $result = $strategist->plan(
            (string) $request->validated('category'),
            (string) ($request->validated('scope') ?? ''),
            is_array($known) ? array_values(array_filter($known, 'is_string')) : [],
            is_numeric($limit) ? (int) $limit : null,
        );

        $payload = [
            'available' => true,
            // WHETHER THE CALL WORKED, always — never inferred from an empty strategy. "This category
            // needs no extra strategy" and "the key was rejected" are both nothing, and a caller that
            // could only see the plan would report a broken integration as a settled question.
            'executionStatus' => $result['status'],
            'errorReason' => $result['error'],
            // Queries, never records. Nothing here is authoritative and nothing here is a credential.
            'strategy' => $result['strategy'],
            'usage' => $result['usage'],
        ];

        if ($result['status'] !== DiscoveryStrategist::STATUS_OK) {
            // 502, like `proposals`: the CRM is fine, its upstream provider is not.
            return response()->json([
                'message' => 'The discovery strategy provider could not be reached or refused the request.',
                'data' => $payload,
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json(['data' => $payload]);
    }
}
