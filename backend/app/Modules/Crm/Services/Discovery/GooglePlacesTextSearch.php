<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google Places API (New) — Text Search, made SERVER-SIDE with the encrypted Nearby credential.
 *
 * The scraper asks the CRM for businesses of a category in an area; this service calls Google and
 * returns business records already in the provider-neutral shape the scraper turns into RawBusiness.
 * The credential travels only in the `X-Goog-Api-Key` header of the outbound request: it is never
 * returned, never logged (failures log a status code only), and never reaches the scraper.
 *
 * Deliberately narrow: Text Search only (no Nearby Search, no Place Details), no ratings, reviews or
 * hours, one field mask, and a hard page cap. Identity is Google's own stable id, namespaced
 * `places/<id>` so it can never collide with an OpenStreetMap `node/123` key.
 */
class GooglePlacesTextSearch
{
    public const SEARCH_URL = 'https://places.googleapis.com/v1/places:searchText';

    /** Text Search returns at most 20 places per page and 60 in total. */
    public const PAGE_SIZE = 20;

    public const MAX_PAGES = 3;

    /**
     * Planned query phrases executed for ONE discovery task.
     *
     * A query count is a MULTIPLIER on paid provider calls, so this is the number that decides
     * whether AI-assisted breadth stays a bounded improvement or becomes a crawler. Three is enough
     * for a niche to be approached from a few real angles and small enough that the worst case stays
     * legible: 3 queries x 3 pages = 9 requests for one task, and only while results are still needed.
     *
     * The caller's `limit` is NOT multiplied by it — see `search()`.
     */
    public const MAX_QUERIES = 3;

    public const TIMEOUT_SECONDS = 15.0;

    /**
     * Exactly the fields the discovery shape carries. `websiteUri` and `nationalPhoneNumber` are the
     * contactability fields RawBusiness already models; nothing else (ratings, reviews, hours) is asked for.
     */
    public const FIELD_MASK = 'places.id,places.displayName,places.location,places.types,places.primaryType,'
        .'places.addressComponents,places.websiteUri,places.nationalPhoneNumber,nextPageToken';

    /** The connection test asks for place ids only — the cheapest Text Search request. */
    public const PROBE_FIELD_MASK = 'places.id';

    public const STATUS_OK = 'ok';

    public const STATUS_AUTH_FAILED = 'auth_failed';

    public const STATUS_RATE_LIMITED = 'rate_limited';

    public const STATUS_UNREACHABLE = 'unreachable';

    public const STATUS_INVALID_REQUEST = 'invalid_request';

    public const STATUS_INVALID_RESPONSE = 'invalid_response';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    /**
     * The configured spend limit cannot cover this request, so it was never made.
     *
     * A DISTINCT WORD BECAUSE IT IS A DISTINCT FACT. This is not a provider failure, not a bad
     * credential and — critically — not an area with no businesses in it. Nothing was searched, so
     * nothing may be concluded about the ground. See GooglePlacesSpendGate.
     */
    public const STATUS_SPEND_LIMITED = 'spend_limited';

    public function __construct(
        private readonly NerbyProviderSettingService $settings,
        private readonly GooglePlacesSpendGate $spend,
    ) {}

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $bbox  south, west, north, east
     * @return array{status: string, error: ?string, places: list<array<string, mixed>>, pages: int}
     */
    public function search(
        string $area,
        string $category,
        int $limit,
        ?string $includedType = null,
        ?array $bbox = null,
        array $textQueries = [],
    ): array {
        $row = $this->settings->for(NerbyProvider::GOOGLE_PLACES);
        if (! $row->exists || ! $row->hasApiKey()) {
            return $this->failure(self::STATUS_NOT_CONFIGURED);
        }

        $limit = max(1, min($limit, self::PAGE_SIZE * self::MAX_PAGES));

        // An empty list means one search on the category-derived phrase — byte-identical to the
        // behaviour that predates planned queries, which is what keeps an unplanned run unchanged.
        $queries = $this->plannedQueries($textQueries);

        // A single-query search reports failures exactly as it always did (`..._on_page_2`); the
        // query index is added ONLY when there is more than one phrase, because that is the only
        // time it disambiguates anything. Reporting `query_1_page_2` for an unplanned search would
        // change a stable diagnostic string to say something no caller needed to know.
        $multi = count($queries) > 1;

        /** @var array<string, array<string, mixed>> $places keyed by provider_id */
        $places = [];
        $pages = 0;
        $executed = 0;
        $error = null;

        foreach ($queries as $query) {
            // THE LIMIT IS A TOTAL, NOT A BUDGET PER QUERY. Once the caller has what it asked for,
            // further queries would be spend with nothing to buy.
            if (count($places) >= $limit) {
                break;
            }

            $executed++;
            $body = $this->requestBody($area, $category, $limit, $includedType, $bbox, $query);
            $pageToken = null;
            $queryPages = 0;

            do {
                $pageBody = $pageToken === null ? $body : [...$body, 'pageToken' => $pageToken];
                $result = $this->send((string) $row->api_key, self::FIELD_MASK, $pageBody);
                $pages++;
                $queryPages++;

                if (is_string($result)) {
                    // Nothing in hand at all: the search failed. Otherwise the reason is carried
                    // alongside the real results rather than throwing them away — a later query
                    // failing must not lose what an earlier one already found.
                    $where = $multi ? 'query_'.$executed.'_page_'.$queryPages : 'page_'.$queryPages;

                    if ($places === []) {
                        return $this->failure($result, $where);
                    }
                    $error = $result.'_on_'.$where;

                    continue 2;
                }

                $raw = $result->json('places', []);
                if (! is_array($raw)) {
                    if ($places === []) {
                        return $this->failure(self::STATUS_INVALID_RESPONSE, 'places_not_a_list');
                    }
                    $error = self::STATUS_INVALID_RESPONSE.($multi ? '_on_query_'.$executed : '');

                    continue 2;
                }

                foreach ($raw as $place) {
                    $normalized = is_array($place) ? $this->normalize($place) : null;
                    if ($normalized !== null) {
                        // DEDUPLICATED ON GOOGLE'S OWN IDENTITY, across pages AND across queries.
                        // Variations of the same niche are expected to overlap heavily; without this
                        // the second query would mostly re-deliver the first one's businesses.
                        $places[$normalized['provider_id']] = $normalized;
                    }
                }

                $pageToken = $result->json('nextPageToken');
            } while (
                is_string($pageToken) && $pageToken !== ''
                && count($places) < $limit
                && $queryPages < self::MAX_PAGES
            );
        }

        return [
            'status' => self::STATUS_OK,
            'error' => $error,
            'places' => array_slice(array_values($places), 0, $limit),
            'pages' => $pages,
            'queries_executed' => $executed,
        ];
    }

    /**
     * The phrases this search will actually run: trimmed, de-duplicated, bounded.
     *
     * Case- and whitespace-insensitive de-duplication, because two planned phrases that differ only
     * in spacing or capitalisation are the same search — and paying twice for it is the specific
     * waste a query multiplier invites. `[null]` (the category-derived phrase) is the answer when
     * nothing survives, so a plan of empty strings can never turn into zero searches.
     *
     * @param  array<int, mixed>  $textQueries
     * @return list<string|null>
     */
    private function plannedQueries(array $textQueries): array
    {
        $out = [];
        $seen = [];

        foreach ($textQueries as $query) {
            if (! is_string($query)) {
                continue;
            }

            $clean = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);
            $key = mb_strtolower($clean);

            if ($clean === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $clean;

            if (count($out) >= self::MAX_QUERIES) {
                break;
            }
        }

        return $out === [] ? [null] : $out;
    }

    /**
     * One ids-only request, for the settings page's Test connection. Returns a status code.
     *
     * THE QUERY IS GENERIC AND CONFIGURED, never a sample search. A probe only has to prove that the
     * credential authenticates and the endpoint answers; encoding a real place and a real category
     * here made a test input into a runtime constant, which then reads like an intended default to
     * everyone who finds it later. See `config/nerby.php`.
     */
    public function probe(string $apiKey): string
    {
        $result = $this->send($apiKey, self::PROBE_FIELD_MASK, [
            'textQuery' => (string) config('nerby.probe_query', 'business'),
            'regionCode' => (string) config('nerby.region_code', 'PH'),
            'pageSize' => 1,
        ]);

        return is_string($result) ? $result : self::STATUS_OK;
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $bbox
     * @return array<string, mixed>
     */
    private function requestBody(
        string $area,
        string $category,
        int $limit,
        ?string $includedType,
        ?array $bbox,
        ?string $textQuery = null,
    ): array {
        // A caller-supplied phrase (an AI-planned query variant) replaces only the CATEGORY half —
        // the area always comes from the request, so a planned query can never redirect a search to
        // a different place than the one asked for.
        $phrase = $textQuery !== null && trim($textQuery) !== ''
            ? trim($textQuery)
            : str_replace('_', ' ', $category);

        $body = [
            'textQuery' => trim($phrase.' in '.$area),
            // Market settings, not a target area: they shape how an ambiguous phrase is interpreted.
            'regionCode' => (string) config('nerby.region_code', 'PH'),
            'languageCode' => (string) config('nerby.language_code', 'en'),
            'pageSize' => min($limit, self::PAGE_SIZE),
        ];

        if ($includedType !== null && $includedType !== '') {
            $body['includedType'] = $includedType;
            $body['strictTypeFiltering'] = true;
        }

        if ($bbox !== null) {
            [$south, $west, $north, $east] = $bbox;
            // A RESTRICTION, not a bias: Google returns nothing outside the area's bounding box.
            $body['locationRestriction'] = ['rectangle' => [
                'low' => ['latitude' => $south, 'longitude' => $west],
                'high' => ['latitude' => $north, 'longitude' => $east],
            ]];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return Response|string the successful response, or a status code
     */
    private function send(string $apiKey, string $fieldMask, array $body): Response|string
    {
        // THE SPEND FENCE, AND IT IS HERE FOR A REASON. This is the one function every Google Places
        // request in this module passes through — production searches, every page of every planned
        // query, and both probes. Reserving here therefore bounds the whole surface, and it does so
        // PER REQUEST rather than per run: a search that crosses the limit on its second page stops
        // on the second page. It also means the orchestrator's rate-limit retry loop is counted
        // without knowing this exists, because a retry re-enters `send()` like any other request.
        //
        // BEFORE the call, never after. A reservation taken afterwards would be a record of spending,
        // not a guard against it.
        $verdict = $this->spend->reserve($fieldMask);
        if ($verdict !== GooglePlacesSpendGate::ALLOWED) {
            Log::warning('Google Places request refused by the spend gate', ['reason' => $verdict]);

            return self::STATUS_SPEND_LIMITED;
        }

        try {
            $response = Http::withHeaders([
                'X-Goog-Api-Key' => $apiKey,
                'X-Goog-FieldMask' => $fieldMask,
            ])->acceptJson()->timeout(self::TIMEOUT_SECONDS)->post(self::SEARCH_URL, $body);
        } catch (ConnectionException) {
            // Never reached the provider, so it was never billed.
            $this->spend->refund($fieldMask);

            return self::STATUS_UNREACHABLE;
        } catch (Throwable $e) {
            $this->spend->refund($fieldMask);
            // The class only: the request carried the credential, and a message could quote it.
            Log::warning('Google Places search failed', ['class' => $e::class]);

            return self::STATUS_UNREACHABLE;
        }

        if ($response->successful()) {
            return $response;
        }

        $status = match (true) {
            in_array($response->status(), [401, 403], true) => self::STATUS_AUTH_FAILED,
            $response->status() === 429 => self::STATUS_RATE_LIMITED,
            $response->status() === 400 => self::STATUS_INVALID_REQUEST,
            default => self::STATUS_UNREACHABLE,
        };

        // A RATE REFUSAL WAS NOT SERVED, SO IT WAS NOT BILLED. Charging for it would let the
        // orchestrator's own backoff quietly eat a budget: every retry would cost twice — once for
        // the refusal and once for the attempt. Deliberately not extended to 4xx rejections, whose
        // billing this code cannot know; over-counting those spends less than allowed.
        if ($status === self::STATUS_RATE_LIMITED) {
            $this->spend->refund($fieldMask);
        }

        Log::warning('Google Places search rejected', [
            'status' => $status,
            'http' => $response->status(),
            'google_status' => is_string($response->json('error.status')) ? $response->json('error.status') : null,
        ]);

        return $status;
    }

    /**
     * One Places (New) record in the provider-neutral discovery shape, or null when it has no usable
     * identity or name.
     *
     * @param  array<string, mixed>  $place
     * @return array<string, mixed>|null
     */
    private function normalize(array $place): ?array
    {
        $id = is_string($place['id'] ?? null) ? trim($place['id']) : '';
        $name = is_string($place['displayName']['text'] ?? null) ? trim($place['displayName']['text']) : '';
        if ($id === '' || $name === '') {
            return null;
        }

        $location = is_array($place['location'] ?? null) ? $place['location'] : [];
        [$city, $province, $region] = $this->adminArea(is_array($place['addressComponents'] ?? null) ? $place['addressComponents'] : []);

        return [
            'provider_id' => 'places/'.$id,
            'name' => $name,
            'latitude' => is_numeric($location['latitude'] ?? null) ? (float) $location['latitude'] : null,
            'longitude' => is_numeric($location['longitude'] ?? null) ? (float) $location['longitude'] : null,
            'website' => is_string($place['websiteUri'] ?? null) ? $place['websiteUri'] : null,
            'phone' => is_string($place['nationalPhoneNumber'] ?? null) ? $place['nationalPhoneNumber'] : null,
            'city' => $city,
            'province' => $province,
            'region' => $region,
            'primary_type' => is_string($place['primaryType'] ?? null) ? $place['primaryType'] : null,
            'types' => array_values(array_filter((array) ($place['types'] ?? []), 'is_string')),
        ];
    }

    /**
     * City, province and region from Places address components: `locality`,
     * `administrative_area_level_2` and `administrative_area_level_1`.
     *
     * @param  array<int, mixed>  $components
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function adminArea(array $components): array
    {
        $found = ['locality' => null, 'administrative_area_level_2' => null, 'administrative_area_level_1' => null];

        foreach ($components as $component) {
            if (! is_array($component) || ! is_string($component['longText'] ?? null)) {
                continue;
            }
            foreach ((array) ($component['types'] ?? []) as $type) {
                if (array_key_exists($type, $found) && $found[$type] === null) {
                    $found[$type] = trim($component['longText']);
                }
            }
        }

        return [$found['locality'], $found['administrative_area_level_2'], $found['administrative_area_level_1']];
    }

    /** @return array{status: string, error: ?string, places: list<array<string, mixed>>, pages: int} */
    private function failure(string $status, ?string $error = null): array
    {
        return ['status' => $status, 'error' => $error, 'places' => [], 'pages' => 0];
    }
}
