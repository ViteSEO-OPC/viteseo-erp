<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MEASURES ONE SEARCH PHRASE — how many businesses it finds, and nothing else about them.
 *
 * WHY THIS IS A SIBLING RATHER THAN A METHOD ON THE SEARCH. {@see GooglePlacesTextSearch} is the
 * factual discovery path: what it returns becomes CRM records. Its behaviour, its return shape and
 * its query semantics are fixed, so measurement is built BESIDE it rather than inside it. Nothing
 * here can change what a discovery run finds, because nothing here is on that path.
 *
 * IT ASKS FOR IDENTIFIERS AND NOTHING ELSE, and that is the whole safety argument. The field mask is
 * `places.id` — the cheapest thing Google's Text Search can be asked for. The response carries no
 * name, no address, no coordinate, no phone number and no website, so a probe result is
 * STRUCTURALLY INCAPABLE of becoming a CRM record: there is no business data in it to ingest, even
 * by accident, even if some future caller tried.
 *
 * WHAT AN IDENTIFIER IS FOR HERE. Two questions, both of which are counts:
 *   - how many businesses does this phrase reach at all;
 *   - how many of them does the CRM not already hold.
 * The ids themselves are used to answer the second question and are then discarded. Only the two
 * numbers are kept — see {@see QueryYieldLog}.
 *
 * ONE PHRASE, ONE REQUEST, ONE PAGE. A measurement that paged would multiply its own cost, and a
 * measurement that batched phrases would reproduce exactly the attribution problem it exists to
 * solve — the provider merges a multi-phrase result into one set, so a batched probe could not say
 * which phrase found what.
 *
 * FAIL CLOSED. No stored credential means no request and no measurement: a missing key is reported
 * as "not configured" and never as a phrase that found nothing.
 */
class GooglePlacesIdProbe
{
    /** The cheapest field mask Text Search accepts. Identifiers only — no business data comes back. */
    public const FIELD_MASK = 'places.id';

    /** The prefix the CRM stores Google identities under, so a probe id can be compared to a lead. */
    public const ID_PREFIX = 'places/';

    private const TIMEOUT_SECONDS = 15;

    public const STATUS_OK = 'ok';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_AUTH_FAILED = 'auth_failed';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_UNREACHABLE = 'unreachable';

    public const STATUS_INVALID_RESPONSE = 'invalid_response';

    /** The spend gate refused this probe, so no request was made. Same word the search path uses. */
    public const STATUS_SPEND_LIMITED = GooglePlacesTextSearch::STATUS_SPEND_LIMITED;

    public function __construct(
        private readonly NerbyProviderSettingService $settings,
        private readonly GooglePlacesSpendGate $spend,
    ) {}

    /**
     * Count what one phrase reaches in one area.
     *
     * The phrase is composed into a query exactly as the discovery path composes it, so the two are
     * measuring the same thing: the area always comes from the caller, which is what stops a phrase
     * redirecting a measurement to somewhere else.
     *
     * @return array{status: string, ids: list<string>, capped: bool, error: string|null}
     *
     * `capped` is true when the page came back full, which means the count is a FLOOR rather than a
     * total — the phrase reaches at least this many, and how many more is unmeasured. A reader that
     * treated a capped count as a total would see two very different phrases as equally good.
     */
    public function measure(string $phrase, string $area, ?string $includedType = null): array
    {
        $phrase = trim($phrase);
        $area = trim($area);

        if ($phrase === '' || $area === '') {
            return $this->failure(self::STATUS_INVALID_RESPONSE, 'empty_target');
        }

        $row = $this->settings->for(NerbyProvider::GOOGLE_PLACES);

        // FAIL CLOSED. No credential, no request — and no measurement, which is a different fact
        // from a phrase that reached nothing.
        if (! $row->exists || ! $row->hasApiKey()) {
            return $this->failure(self::STATUS_NOT_CONFIGURED);
        }

        $pageSize = max(1, min((int) config('discovery.probe.page_size', 20), 20));

        $body = [
            'textQuery' => trim($phrase.' in '.$area),
            'regionCode' => (string) config('nerby.region_code', 'PH'),
            'languageCode' => (string) config('nerby.language_code', 'en'),
            'pageSize' => $pageSize,
        ];

        if (is_string($includedType) && $includedType !== '') {
            $body['includedType'] = $includedType;
            $body['strictTypeFiltering'] = true;
        }

        // THE SECOND GOOGLE PLACES CALL SITE, GATED IDENTICALLY. This probe asks for ids only, so
        // it is free at the provider and the gate prices it at zero — but it is still counted, and
        // it is still refused when the limit is 0, because 0 means "issue no Google Places requests"
        // rather than "issue only the free ones". A guard with a second, ungated door is not a guard.
        $verdict = $this->spend->reserve(self::FIELD_MASK);
        if ($verdict !== GooglePlacesSpendGate::ALLOWED) {
            Log::warning('Query yield probe refused by the spend gate', ['reason' => $verdict]);

            return $this->failure(self::STATUS_SPEND_LIMITED, $verdict);
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => (string) $row->api_key,
                'X-Goog-FieldMask' => self::FIELD_MASK,
            ])->acceptJson()->timeout(self::TIMEOUT_SECONDS)
                ->post(GooglePlacesTextSearch::SEARCH_URL, $body);
        } catch (ConnectionException) {
            $this->spend->refund(self::FIELD_MASK);

            return $this->failure(self::STATUS_UNREACHABLE, 'connection');
        } catch (Throwable $e) {
            $this->spend->refund(self::FIELD_MASK);
            // The class only: the request carried the credential, and a message could quote it.
            Log::warning('Query yield probe failed', ['class' => $e::class]);

            return $this->failure(self::STATUS_UNREACHABLE);
        }

        if (! $response->successful()) {
            $status = match (true) {
                in_array($response->status(), [401, 403], true) => self::STATUS_AUTH_FAILED,
                $response->status() === 429 => self::STATUS_RATE_LIMITED,
                default => self::STATUS_UNREACHABLE,
            };

            // Refused for rate, therefore not served and not billed — same rule as the search path.
            if ($status === self::STATUS_RATE_LIMITED) {
                $this->spend->refund(self::FIELD_MASK);
            }

            Log::warning('Query yield probe rejected', ['status' => $status, 'http' => $response->status()]);

            return $this->failure($status, 'http_'.$response->status());
        }

        $raw = $response->json('places');
        if (! is_array($raw)) {
            // An empty result set is reported by Google as an ABSENT key, which is a real zero. A
            // present-but-unreadable body is a failure, and the two must not be confused.
            if ($raw === null && $response->json() !== null) {
                return ['status' => self::STATUS_OK, 'ids' => [], 'capped' => false, 'error' => null];
            }

            return $this->failure(self::STATUS_INVALID_RESPONSE, 'places_not_a_list');
        }

        $ids = [];
        foreach ($raw as $place) {
            $id = is_array($place) ? ($place['id'] ?? null) : null;
            if (is_string($id) && trim($id) !== '' && ! in_array($id, $ids, true)) {
                $ids[] = trim($id);
            }
        }

        return [
            'status' => self::STATUS_OK,
            'ids' => $ids,
            'capped' => count($ids) >= $pageSize,
            'error' => null,
        ];
    }

    /**
     * @return array{status: string, ids: list<string>, capped: bool, error: string|null}
     */
    private function failure(string $status, ?string $error = null): array
    {
        return ['status' => $status, 'ids' => [], 'capped' => false, 'error' => $error];
    }
}
