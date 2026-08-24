<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Requests\DiscoveryProposalRequest;
use App\Modules\Crm\Services\Discovery\Contracts\ProposalGenerator;
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
}
