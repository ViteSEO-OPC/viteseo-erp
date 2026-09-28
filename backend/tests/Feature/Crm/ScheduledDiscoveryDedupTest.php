<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsNerbyService;
use Tests\TestCase;

/**
 * Duplicate prevention for the UNATTENDED path: `discover-all --commit`, nightly, forever.
 *
 * The manual `discover` command was always driven by someone who would notice a pool doubling. The
 * cron is not, and it re-queries a target roughly every rotation — so "the same business arrives
 * again" is not an edge case here, it is the STEADY STATE. Overpass truncates server-side with
 * `out center {limit}` and has no offset, so a swept pair returns the same first N elements every
 * night; every one of them reaches the ingest as a re-submission.
 *
 * The contract that makes that safe is `place_id` — the provider's stable natural key (OSM
 * `node/123`) — matched CRM-wide before anything is written, with email as a secondary key. These
 * tests pin the behaviours a wider target matrix leans on:
 *
 *  - re-running a target imports NOTHING and reports the batch as duplicates;
 *  - the same element found under a DIFFERENT CATEGORY is one business, not two;
 *  - the same element found from an OVERLAPPING AREA is one business, not two (bboxes are
 *    rectangles and genuinely do spill across municipal boundaries — a "Makati City" sweep returns
 *    elements whose reverse-geocoded city is Pasay);
 *  - a batch that repeats a place_id WITHIN ITSELF lands one lead;
 *  - re-discovery may refresh fit-data (the website URL) but must never create a second row.
 *
 * Deliberately NOT asserted here, because asserting them would enshrine them: a discovered lead that
 * has been soft-deleted is not matched (`existingPlaceIdLeads` uses the default scope, unlike the
 * Excel path's `withTrashed()`), and a row with no `place_id` at all has no identity to match on.
 * Both are recorded gaps awaiting a decision, not behaviours this suite endorses.
 */
class ScheduledDiscoveryDedupTest extends TestCase
{
    use ActsAsNerbyService;
    use RefreshDatabase;

    private const INGEST = '/api/v1/crm/leads/discovered';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $importer = User::factory()->create(['status' => 'active']);
        $importer->assignRole('super_admin');
        Sanctum::actingAs($importer);
    }

    /** One normalized business exactly as the scraper's normalizer emits it. */
    private function business(string $name, string $placeId, array $overrides = []): array
    {
        return array_merge([
            'business_name' => $name,
            'place_id' => $placeId,
            'source' => 'openstreetmap',
            'city' => 'Makati',
            'region' => 'National Capital Region',
            'latitude' => 14.5547,
            'longitude' => 121.0244,
        ], $overrides);
    }

    /** A scheduled run: the scraper POSTs one batch per target with dry_run = !--commit. */
    private function nightlyRun(array $businesses): array
    {
        return $this->ingestDiscovered(['dry_run' => false, 'businesses' => $businesses])
            ->assertOk()
            ->json('data');
    }

    // ── The steady state: the same target, every night ──────────────────────────────────────────

    public function test_the_first_scheduled_run_imports_the_batch(): void
    {
        $result = $this->nightlyRun([
            $this->business('Alpha Pharmacy', 'node/1'),
            $this->business('Beta Pharmacy', 'node/2'),
        ]);

        $this->assertSame(2, $result['importedCount']);
        $this->assertSame(0, $result['summary']['duplicates']);
        $this->assertSame(2, Lead::query()->count());
    }

    public function test_re_running_the_same_target_imports_nothing(): void
    {
        $batch = [
            $this->business('Alpha Pharmacy', 'node/1'),
            $this->business('Beta Pharmacy', 'node/2'),
        ];
        $this->nightlyRun($batch);

        $second = $this->nightlyRun($batch);

        $this->assertSame(0, $second['importedCount'], 'a repeat run created leads');
        $this->assertSame(2, $second['summary']['duplicates']);
        $this->assertSame(2, Lead::query()->count(), 'the pool doubled on the second night');
    }

    public function test_a_pair_stays_stable_across_many_nights(): void
    {
        $batch = [$this->business('Alpha Pharmacy', 'node/1')];

        for ($night = 0; $night < 5; $night++) {
            $this->nightlyRun($batch);
        }

        $this->assertSame(1, Lead::query()->count(), 'the nightly cron accumulated duplicates');
    }

    // ── The same business reached by a different route ──────────────────────────────────────────

    public function test_the_same_element_found_under_another_category_is_one_business(): void
    {
        // A pharmacy tagged `amenity=pharmacy` is ONE OSM element; a later `shop=chemist` sweep
        // returns that same element, so the natural key is identical even though the run differs.
        $this->nightlyRun([$this->business('Alpha Pharmacy', 'node/1', ['industry' => 'Pharmacy'])]);

        $result = $this->nightlyRun([$this->business('Alpha Pharmacy', 'node/1', ['industry' => 'Chemist'])]);

        $this->assertSame(0, $result['importedCount']);
        $this->assertSame(1, Lead::query()->count());
        $this->assertSame('Pharmacy', Lead::query()->first()->industry, 'a re-run rewrote fit-data');
    }

    public function test_the_same_element_found_from_an_overlapping_area_is_one_business(): void
    {
        // Bounding boxes are rectangles: a Makati sweep and a Pasay sweep can both return this node.
        $this->nightlyRun([$this->business('Border Cafe', 'node/9', ['city' => 'Makati'])]);

        $result = $this->nightlyRun([$this->business('Border Cafe', 'node/9', ['city' => 'Pasay'])]);

        $this->assertSame(0, $result['importedCount']);
        $this->assertSame(1, Lead::query()->count());
    }

    public function test_a_repeated_place_id_within_one_batch_lands_once(): void
    {
        $result = $this->nightlyRun([
            $this->business('Alpha Pharmacy', 'node/1'),
            $this->business('Alpha Pharmacy', 'node/1'),
        ]);

        $this->assertSame(1, $result['importedCount']);
        $this->assertSame(1, $result['summary']['duplicates']);
        $this->assertSame(1, Lead::query()->count());
    }

    public function test_email_catches_a_business_whose_identifier_changed(): void
    {
        // An OSM element deleted and re-added gets a new id. Email is the secondary key that can
        // still recognise the business when its natural key moves underneath it.
        $this->nightlyRun([$this->business('Gamma Clinic', 'node/1', ['email' => 'hello@gamma.ph'])]);

        $result = $this->nightlyRun([$this->business('Gamma Clinic', 'way/77', ['email' => 'HELLO@gamma.ph'])]);

        $this->assertSame(0, $result['importedCount'], 'email dedup is case-sensitive');
        $this->assertSame(1, Lead::query()->count());
    }

    public function test_two_genuinely_different_businesses_both_land(): void
    {
        // The guard against over-matching: same name, different element — two real branches.
        $result = $this->nightlyRun([
            $this->business('Mercury Drug', 'node/1', ['city' => 'Makati']),
            $this->business('Mercury Drug', 'node/2', ['city' => 'Vigan']),
        ]);

        $this->assertSame(2, $result['importedCount'], 'distinct branches were merged');
        $this->assertSame(2, Lead::query()->count());
    }

    // ── Re-discovery refreshes fit-data without duplicating ─────────────────────────────────────

    public function test_a_newly_found_website_is_proposed_to_website_intelligence_not_written(): void
    {
        // H-3: discovery used to write a re-discovered URL straight into `website`, bypassing Website
        // Intelligence (and able to replace an audited URL). It is now only a candidate for the
        // identity verifier; the lead itself is not touched.
        $this->nightlyRun([$this->business('Alpha Pharmacy', 'node/1')]);

        $result = $this->nightlyRun([
            $this->business('Alpha Pharmacy', 'node/1', ['website' => 'https://alpha.ph']),
        ]);

        $lead = Lead::query()->firstOrFail();
        $this->assertSame(0, $result['importedCount']);
        $this->assertSame(0, $result['summary']['updated']);
        $this->assertSame(1, $result['summary']['websiteCandidates']);
        $this->assertNull($lead->website, 'discovery must never write an existing lead\'s website');
        $this->assertSame(1, Lead::query()->count());

        $candidate = LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('https://alpha.ph', $candidate->url);
        $this->assertSame(LeadWebsiteCandidate::SOURCE_SEARCH, $candidate->source);
        $this->assertSame('discovery:openstreetmap', $candidate->source_detail);
        $this->assertNull($candidate->verified, 'only the identity verifier may accept it');
    }

    public function test_a_dry_run_never_writes(): void
    {
        $this->ingestDiscovered([
            'dry_run' => true,
            'businesses' => [$this->business('Alpha Pharmacy', 'node/1')],
        ])->assertOk();

        $this->assertSame(0, Lead::query()->count(), 'a dry run persisted a lead');
    }

    // ── Ownership is never touched by discovery ─────────────────────────────────────────────────

    public function test_re_discovery_never_disturbs_an_existing_claim(): void
    {
        // The nightly cron re-submits businesses a BD has already claimed. Ownership, the claim
        // stamp and the engagement fields must all survive untouched.
        $this->nightlyRun([$this->business('Alpha Pharmacy', 'node/1')]);
        $bd = User::factory()->create(['status' => 'active', 'name' => 'Rea Villanueva']);
        $bd->assignRole('crm_bd');
        $lead = Lead::query()->first();
        $lead->update(['assigned_to' => $bd->id, 'qualified_at' => now(), 'qualified_by' => $bd->id]);

        $this->nightlyRun([$this->business('Alpha Pharmacy', 'node/1', ['website' => 'https://alpha.ph'])]);

        $lead->refresh();
        $this->assertSame($bd->id, $lead->assigned_to, 'discovery reassigned a claimed lead');
        $this->assertSame($bd->id, $lead->qualified_by);
        $this->assertNotNull($lead->qualified_at);
        $this->assertSame(1, Lead::query()->count());
    }
}
