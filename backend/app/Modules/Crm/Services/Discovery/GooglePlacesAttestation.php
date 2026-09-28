<?php

namespace App\Modules\Crm\Services\Discovery;

use Illuminate\Support\Facades\Cache;

/**
 * "Did the CRM itself hand out this Google place?" — the check that stops a forged identity.
 *
 * THE GAP IT CLOSES. Discovered-business ingest accepted any `place_id`, coordinates, phone and
 * website the caller sent. For Google the CRM is the ONLY party that talks to the provider
 * (`/discovery/places` makes the call with the encrypted key), so it can know exactly which places it
 * returned — and to which service token. Ingest then accepts a `places/<id>` row only when that same
 * token was given that place, and only when every identity field it submits matches what Google
 * said. A made-up place id, a moved pin, a swapped phone or website is refused, not stored.
 *
 * Keyed by the SERVICE TOKEN, not the org: a token is minted per run, so an attestation from one run
 * cannot be replayed by another. Stores only the fields ingest compares — no response body.
 *
 * OpenStreetMap identities (`node|way|relation/<n>`) cannot be attested here: the engine queries the
 * public Overpass service directly and the CRM never sees the answer. Those rows are limited to the
 * machine caller and to a strict identity shape instead (see {@see check()}).
 */
class GooglePlacesAttestation
{
    public const GOOGLE_PREFIX = 'places/';

    private const OSM_PATTERN = '#^(node|way|relation)/[1-9][0-9]{0,18}$#';

    private const COORDINATE_TOLERANCE = 0.000001;

    /** Remember what `/discovery/places` returned to this token. */
    public function record(int $tokenId, array $places): void
    {
        $ttl = max(60, (int) config('discovery.attestation_ttl', 21600));

        foreach ($places as $place) {
            $id = is_array($place) ? ($place['provider_id'] ?? null) : null;
            if (! is_string($id) || ! str_starts_with($id, self::GOOGLE_PREFIX)) {
                continue;
            }

            Cache::put($this->key($tokenId, $id), [
                'business_name' => $this->text($place['name'] ?? null),
                'latitude' => is_numeric($place['latitude'] ?? null) ? (float) $place['latitude'] : null,
                'longitude' => is_numeric($place['longitude'] ?? null) ? (float) $place['longitude'] : null,
                'phone' => $this->text($place['phone'] ?? null),
                'website' => $this->text($place['website'] ?? null),
            ], $ttl);
        }
    }

    /**
     * May this ingest row be stored, given who submitted it?
     *
     * @param  array<string, mixed>  $row  one validated `businesses.*` row
     */
    public function check(int $tokenId, array $row): bool
    {
        $placeId = isset($row['place_id']) && $row['place_id'] !== '' ? (string) $row['place_id'] : null;
        $source = (string) ($row['source'] ?? '');

        // A Google-sourced row must carry a Google identity, and vice versa — no relabelling.
        if ($source === 'google_places' && ($placeId === null || ! str_starts_with($placeId, self::GOOGLE_PREFIX))) {
            return false;
        }

        if ($placeId === null) {
            // No identity claimed: nothing to forge. Deduplicated by email, as before.
            return true;
        }

        if (preg_match(self::OSM_PATTERN, $placeId) === 1) {
            return $source !== 'google_places';
        }

        if (! str_starts_with($placeId, self::GOOGLE_PREFIX)) {
            // Any other identity shape is not something a provider in this system produces.
            return false;
        }

        $attested = Cache::get($this->key($tokenId, $placeId));
        if (! is_array($attested)) {
            return false;
        }

        foreach (['business_name', 'phone', 'website'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                if ($this->text($row[$field]) !== $attested[$field]) {
                    return false;
                }
            }
        }

        foreach (['latitude', 'longitude'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                if ($attested[$field] === null
                    || abs((float) $row[$field] - (float) $attested[$field]) > self::COORDINATE_TOLERANCE) {
                    return false;
                }
            }
        }

        return true;
    }

    private function key(int $tokenId, string $placeId): string
    {
        return 'crm:nerby:attest:'.$tokenId.':'.sha1($placeId);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
