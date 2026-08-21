<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Niche;
use App\Modules\Crm\Services\NicheCanonicalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Nearby Clients and the Business Niche registry.
 *
 * A lead stores its niche as the plain `crm_leads.industry` string; the registry link is a
 * CASE-INSENSITIVE NAME MATCH that every other CRM surface already applies (`Niche::leadsMatching`,
 * `isInUse`, `repointLeads`, the Leads industry filter). Nearby derives its Business Niche filter
 * options from whatever the API returns, so until now `pharmacy` and `Pharmacy` were two dropdown
 * entries that each missed the other's leads.
 *
 * These tests pin the two halves of the fix and, just as importantly, its LIMITS: discovery
 * legitimately produces niches the registry has never heard of, and those must survive untouched
 * rather than being hidden or rewritten.
 */
class NearbyClientsNicheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // LeadFactory resolves `created_by` to a random EXISTING user and falls back to id 1, which
        // violates the FK when no users exist yet. Prospects below are unowned, so one user has to
        // exist first. It owns nothing and affects no assertion.
        User::factory()->create(['status' => 'active']);
    }

    private function bd(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');

        return $user;
    }

    /** A discovered prospect — unowned, unqualified, in the pool. */
    private function prospect(?string $industry): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => null,
            'qualified_at' => null,
            'source' => 'openstreetmap',
            'industry' => $industry,
        ]);
    }

    /** @return list<string|null> the `niche` value of every row GET /nearby-clients returns */
    private function niches(): array
    {
        Sanctum::actingAs($this->bd());

        return collect($this->getJson('/api/v1/crm/nearby-clients')->assertOk()->json('data'))
            ->pluck('niche')
            ->all();
    }

    // --- Canonicalization ---------------------------------------------------

    public function test_a_registered_niche_is_returned_in_the_registry_spelling(): void
    {
        Niche::create(['name' => 'Pharmacy', 'is_custom' => true]);
        $this->prospect('pharmacy');

        $this->assertSame(['Pharmacy'], $this->niches());
    }

    /** The whole point: differently-cased leads collapse to ONE filter option instead of two. */
    public function test_case_variants_of_one_registered_niche_collapse_to_a_single_option(): void
    {
        Niche::create(['name' => 'Pharmacy', 'is_custom' => true]);
        $this->prospect('pharmacy');
        $this->prospect('PHARMACY');
        $this->prospect('Pharmacy');

        $niches = $this->niches();

        $this->assertCount(3, $niches);
        $this->assertSame(['Pharmacy'], array_values(array_unique($niches)));
    }

    /** A custom niche created through Create Lead → Add Business Niche is recognised the same way. */
    public function test_a_custom_niche_is_recognised(): void
    {
        Niche::create(['name' => 'Coworking Space', 'is_custom' => true]);
        $this->prospect('coworking space');

        $this->assertSame(['Coworking Space'], $this->niches());
    }

    // --- Limits: what it must NOT do ----------------------------------------

    /**
     * Discovery derives `industry` from provider tags and will produce values the registry has never
     * heard of. Those are real prospects and must appear exactly as recorded.
     */
    public function test_an_unregistered_niche_passes_through_unchanged(): void
    {
        $this->prospect('Veterinary Clinic');

        $this->assertSame(['Veterinary Clinic'], $this->niches());
    }

    public function test_a_lead_with_no_niche_stays_null(): void
    {
        $this->prospect(null);

        $this->assertSame([null], $this->niches());
    }

    public function test_canonicalization_never_writes_to_the_lead(): void
    {
        Niche::create(['name' => 'Pharmacy', 'is_custom' => true]);
        $lead = $this->prospect('pharmacy');

        $this->niches();

        $this->assertSame('pharmacy', $lead->fresh()->industry, 'the stored value is untouched');
    }

    /** Reading the pool must not invent registry entries for unknown industries. */
    public function test_canonicalization_never_registers_a_new_niche(): void
    {
        $this->prospect('Something Unregistered');
        $before = Niche::query()->count();

        $this->niches();

        $this->assertSame($before, Niche::query()->count());
    }

    // --- Rename cascade -----------------------------------------------------

    /**
     * Renaming repoints `leads.industry` (Niche::repointLeads), so Nearby follows automatically —
     * pinned here because the filter options are derived from that column.
     */
    public function test_renaming_a_niche_is_reflected_in_the_pool(): void
    {
        $niche = Niche::create(['name' => 'Pharmacy', 'is_custom' => true]);
        $this->prospect('Pharmacy');

        Niche::repointLeads('Pharmacy', 'Drugstore');
        $niche->update(['name' => 'Drugstore']);

        $this->assertSame(['Drugstore'], $this->niches());
    }

    // --- The service in isolation -------------------------------------------

    public function test_the_canonicalizer_reports_registration_status(): void
    {
        Niche::create(['name' => 'Pharmacy', 'is_custom' => true]);
        $canonicalizer = app(NicheCanonicalizer::class);

        $this->assertTrue($canonicalizer->isRegistered('pharmacy'));
        $this->assertFalse($canonicalizer->isRegistered('Veterinary Clinic'));
        $this->assertFalse($canonicalizer->isRegistered(null));
        $this->assertFalse($canonicalizer->isRegistered('   '));
    }

    public function test_the_canonicalizer_trims_before_matching(): void
    {
        Niche::create(['name' => 'Pharmacy', 'is_custom' => true]);

        $this->assertSame('Pharmacy', app(NicheCanonicalizer::class)->canonical('  pharmacy  '));
    }

    /**
     * The registry is memoized per instance, so serializing a whole pool costs ONE query rather than
     * one per row. The container binding is `scoped`, which is what makes that safe across requests.
     */
    public function test_the_registry_is_loaded_once_per_instance(): void
    {
        Niche::create(['name' => 'Pharmacy', 'is_custom' => true]);
        $canonicalizer = app(NicheCanonicalizer::class);

        $canonicalizer->canonical('pharmacy');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        foreach (range(1, 25) as $_) {
            $canonicalizer->canonical('pharmacy');
        }

        $this->assertSame(0, $queries, 'the registry must not be re-queried per row');
    }

    // --- Scraper contract ---------------------------------------------------

    /**
     * OSM discovery categories and the CRM niche registry are separate vocabularies. Ingest must keep
     * storing exactly what the provider supplied; canonicalization happens on the way OUT only.
     */
    public function test_ingest_still_stores_the_providers_raw_industry(): void
    {
        Niche::create(['name' => 'Pharmacy', 'is_custom' => true]);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/crm/leads/discovered', [
            'dry_run' => false,
            'businesses' => [[
                'business_name' => 'Some Drugstore',
                'industry' => 'pharmacy',
                'source' => 'openstreetmap',
                'place_id' => 'osm-node-1',
            ]],
        ])->assertSuccessful();

        $lead = Lead::query()->where('place_id', 'osm-node-1')->firstOrFail();

        $this->assertSame('pharmacy', $lead->industry, 'the ingest contract is unchanged');
        $this->assertSame('Pharmacy', app(NicheCanonicalizer::class)->canonical($lead->industry));
    }
}
