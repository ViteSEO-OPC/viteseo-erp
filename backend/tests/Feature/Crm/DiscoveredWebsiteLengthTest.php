<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ONE OVERSIZED PROVIDER FIELD MUST NOT DESTROY A WHOLE DISCOVERY TARGET.
 *
 * The incident these tests freeze: Google Places returned a bakery whose website was a 290-character
 * booking.com URL carrying UTM parameters. The ingest validator accepted it (`max:2048`) and the
 * INSERT then failed on a `varchar(255)` column with SQLSTATE[22001]. `/crm/leads/discovered`
 * answered 500, the scraper's `raise_for_status()` killed the target, and every OTHER valid business
 * found in that same search was lost with it — the run still reported "completed".
 *
 * Two independent guarantees, because either alone would leave a hole:
 *
 *   1. A long-but-legitimate URL is STORED WHOLE. The column now matches the contract the validator
 *      and every Website Intelligence URL column already keep (2048). Truncating instead would have
 *      produced a link that resolves to nothing.
 *   2. A URL beyond even that ceiling cannot reach SQL. It is dropped and THE BUSINESS IS STILL
 *      INGESTED, alongside its neighbours — a bad field costs its own value, never the batch.
 *
 * Deduplication, scoring and pooling are deliberately not exercised here: `website` takes no part in
 * any of them, and that is precisely why dropping it is safe.
 */
class DiscoveredWebsiteLengthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);
    }

    /** A discovered business as the Google Places path sends it. */
    private function business(string $placeId, ?string $website): array
    {
        return array_filter([
            'business_name' => 'Test Bakery '.$placeId,
            'industry' => 'Bakery',
            'city' => 'Bacolod',
            'region' => 'Western Visayas',
            'latitude' => 10.6765,
            'longitude' => 122.9509,
            'place_id' => $placeId,
            'source' => 'google_places',
            'website' => $website,
        ], fn ($v) => $v !== null);
    }

    /**
     * The exact shape that broke: longer than the old column, well inside the validator's ceiling.
     *
     * ON THE BUSINESS'S OWN DOMAIN, deliberately. This fixture was originally a booking.com URL,
     * chosen only because it was a realistic long one — but a booking.com link is a directory listing
     * and is no longer stored as a website at all ({@see WebsiteUrlClassifier}), so the fixture would
     * have been testing the classifier rather than the column width. The subject here is LENGTH, so
     * the URL has to be one that is genuinely a website.
     */
    private function longUrl(int $length): string
    {
        $prefix = 'https://www.negrosstaycations.ph/rooms/some-very-long-staycation-slug/booking?ref=seogooglelocal&utm_campaign=';

        return $prefix.str_repeat('x', max(0, $length - strlen($prefix)));
    }

    public function test_a_290_character_website_url_is_ingested_and_stored_whole(): void
    {
        $url = $this->longUrl(290);
        $this->assertSame(290, strlen($url), 'the fixture must reproduce the reported length');

        $this->postJson('/api/v1/crm/leads/discovered', [
            'businesses' => [$this->business('places/OVERSIZED', $url)],
        ])->assertOk();

        $lead = Lead::query()->where('place_id', 'places/OVERSIZED')->firstOrFail();

        // Stored intact — not cut down to 255, which would leave a broken link behind.
        $this->assertSame($url, $lead->website);
    }

    public function test_one_oversized_url_does_not_take_its_neighbours_down_with_it(): void
    {
        // Beyond the storage contract entirely. Previously this raised SQLSTATE[22001] inside the
        // batch transaction, so NONE of the three businesses landed.
        $absurd = $this->longUrl(5000);

        $this->postJson('/api/v1/crm/leads/discovered', [
            'businesses' => [
                $this->business('places/GOOD-1', 'https://goodbakery.example.ph'),
                $this->business('places/BAD', $absurd),
                $this->business('places/GOOD-2', 'https://otherbakery.example.ph'),
            ],
        ])->assertOk();

        // All three businesses survived.
        $this->assertSame(3, Lead::query()->whereIn('place_id', [
            'places/GOOD-1', 'places/BAD', 'places/GOOD-2',
        ])->count());

        // The valid neighbours kept their URLs...
        $this->assertSame(
            'https://goodbakery.example.ph',
            Lead::query()->where('place_id', 'places/GOOD-1')->value('website')
        );

        // ...and the offender lost only the unusable field, not its row.
        $bad = Lead::query()->where('place_id', 'places/BAD')->firstOrFail();
        $this->assertNull($bad->website);
        $this->assertSame('Test Bakery places/BAD', $bad->business_name);
    }

    public function test_an_oversized_url_never_reaches_the_database_as_a_truncated_link(): void
    {
        $this->postJson('/api/v1/crm/leads/discovered', [
            'businesses' => [$this->business('places/NO-STUMP', $this->longUrl(5000))],
        ])->assertOk();

        $stored = Lead::query()->where('place_id', 'places/NO-STUMP')->value('website');

        // A half-URL would be worse than none: it looks usable and resolves to nothing.
        $this->assertNull($stored);
    }
}
