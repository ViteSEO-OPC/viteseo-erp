<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Modules\Crm\Services\Discovery\GooglePlacesAttestation;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use App\Support\CurrentOrg;
use Illuminate\Testing\TestResponse;

/**
 * Authenticate the following requests exactly as the NERBY scraper does in production: the
 * least-privilege service account, with a REAL org-bound, route-confined personal access token.
 *
 * Not Sanctum::actingAs() — that fakes the token, and the org binding and route allowlist are read
 * from the real token's abilities. Machine-only callbacks (discovered ingest, Website Intelligence
 * findings) accept nothing else.
 */
trait ActsAsNerbyService
{
    /** Id of the service token issued by the last actingAsNerbyService() call. */
    protected ?int $nerbyTokenId = null;

    protected function actingAsNerbyService(?int $orgId = null): User
    {
        $orgId ??= CurrentOrg::id() ?? $this->tenant()->id;

        $service = app(NerbyServiceIdentity::class);
        $token = $service->issue($orgId, 'test', now()->addHour());
        $this->nerbyTokenId = (int) $token->accessToken->getKey();

        // Drop any user a previous Sanctum::actingAs() pinned on the guard, so the bearer token is
        // what authenticates.
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken);

        return $service->user();
    }

    /**
     * Precondition for ingest tests: "the CRM's own /discovery/places returned these Google places to
     * this service token". Recorded through the real attestation service, from the rows about to be
     * ingested — the recording path itself is covered by its own tests.
     *
     * @param  list<array<string, mixed>>  $businesses  ingest rows (`place_id` must be `places/...`)
     */
    protected function attestAsGoogle(array $businesses): void
    {
        app(GooglePlacesAttestation::class)->record((int) $this->nerbyTokenId, array_map(
            static fn (array $b): array => [
                'provider_id' => $b['place_id'] ?? null,
                'name' => $b['business_name'] ?? null,
                'latitude' => $b['latitude'] ?? null,
                'longitude' => $b['longitude'] ?? null,
                'phone' => $b['phone'] ?? null,
                'website' => $b['website'] ?? null,
            ],
            $businesses,
        ));
    }

    /**
     * POST /leads/discovered exactly as the scraper does: as the service identity, with every Google
     * row attested as returned by the CRM's own /discovery/places to that token.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function ingestDiscovered(array $payload, ?int $orgId = null): TestResponse
    {
        $this->actingAsNerbyService($orgId);

        $google = array_values(array_filter(
            (array) ($payload['businesses'] ?? []),
            static fn ($b): bool => is_array($b) && str_starts_with((string) ($b['place_id'] ?? ''), 'places/'),
        ));
        if ($google !== []) {
            $this->attestAsGoogle($google);
        }

        return $this->postJson('/api/v1/crm/leads/discovered', $payload);
    }
}
