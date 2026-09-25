<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsNerbyService;
use Tests\TestCase;

/**
 * MULTI-PROVENANCE (change set D): AI-assisted discovery is recorded BESIDE a lead, never on it.
 *
 * A business the AI lanes find is recorded in `crm_lead_discovery_lanes` (lane `openai`) whether it
 * became a new lead or matched an existing one — including the case where the OpenStreetMap baseline
 * and an AI lane found it in the same search (`discovery_also_found_by`). The lead row itself is
 * never written for this: its source, owner, status, score, priority and qualification stay exactly
 * as they were, no observer fires, no activity is logged, and no duplicate lead is created.
 *
 * Google Places is out of scope entirely: a Google row never writes a lane, and a lane is never
 * written onto a Google lead.
 */
class LeadDiscoveryLanesTest extends TestCase
{
    use ActsAsNerbyService;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // LeadFactory needs an existing user for `created_by`.
        User::factory()->create(['status' => 'active']);
    }

    private function row(string $placeId, string $source, array $extra = []): array
    {
        return [
            'business_name' => 'Bakery '.$placeId,
            'industry' => 'Bakery',
            'city' => 'Bacolod',
            'latitude' => 10.6765,
            'longitude' => 122.9509,
            'place_id' => $placeId,
            'source' => $source,
            ...$extra,
        ];
    }

    /** @return list<object> */
    private function lanes(): array
    {
        return DB::table('crm_lead_discovery_lanes')->orderBy('id')->get()->all();
    }

    private function lead(string $placeId): Lead
    {
        return Lead::query()->where('place_id', $placeId)->sole();
    }

    /** An existing lead in the shape a BD works with: owned, qualified, scored, prioritised. */
    private function existingOsmLead(string $placeId, array $overrides = []): Lead
    {
        $owner = User::factory()->create(['status' => 'active']);
        $owner->assignRole('crm_bd');

        return Lead::factory()->create([
            'place_id' => $placeId,
            'source' => 'openstreetmap',
            'assigned_to' => $owner->id,
            'qualified_at' => now()->subDay(),
            'status' => 'contacted',
            'priority' => 'high',
            'business_name' => 'Existing '.$placeId,
            'latitude' => 10.6765,
            'longitude' => 122.9509,
            ...$overrides,
        ]);
    }

    // ── an existing lead rediscovered by AI ──────────────────────────────────────────────────

    public function test_ai_rediscovering_an_existing_osm_lead_records_the_lane_and_touches_nothing_else(): void
    {
        $lead = $this->existingOsmLead('node/1');
        $before = DB::table('crm_leads')->where('id', $lead->id)->first();
        $activities = DB::table('crm_activities')->count();
        $leads = DB::table('crm_leads')->count();

        Carbon::setTestNow(now()->addHour());
        $this->ingestDiscovered(['businesses' => [$this->row('node/1', 'openai')]])
            ->assertOk()->assertJsonPath('data.summary.duplicates', 1);

        // The lead row is byte-identical — source, owner, status, score, priority, qualification and
        // updated_at included — no activity was logged and no second lead exists.
        $this->assertEquals($before, DB::table('crm_leads')->where('id', $lead->id)->first());
        $this->assertSame($activities, DB::table('crm_activities')->count());
        $this->assertSame($leads, DB::table('crm_leads')->count());

        $lanes = $this->lanes();
        $this->assertCount(1, $lanes);
        $this->assertSame($lead->id, (int) $lanes[0]->lead_id);
        $this->assertSame('openai', $lanes[0]->lane);
        $this->assertSame(1, (int) $lanes[0]->times_seen);
        $this->assertSame((int) $lead->org_id, (int) $lanes[0]->org_id);
    }

    public function test_a_second_rediscovery_counts_and_keeps_the_first_sighting(): void
    {
        $this->existingOsmLead('node/1');
        $run = DiscoveryRun::query()->create([
            'uuid' => (string) Str::uuid(), 'requested_by' => User::query()->value('id'),
            'scope_class' => DiscoveryRun::SCOPE_NATIONWIDE, 'status' => DiscoveryRun::STATUS_RUNNING,
            'scope' => ['niches' => ['bakery']],
        ]);

        // First sighting from a real run token: its run is remembered.
        $token = app(NerbyServiceIdentity::class)->issue($this->tenant()->id, 'run:'.$run->uuid, now()->addHour());
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/crm/leads/discovered', ['businesses' => [$this->row('node/1', 'openai')]])->assertOk();
        $first = $this->lanes()[0];

        Carbon::setTestNow(now()->addDay());
        $this->ingestDiscovered(['businesses' => [$this->row('node/1', 'openai')]])->assertOk();

        $lanes = $this->lanes();
        $this->assertCount(1, $lanes, 'one row per lead and lane, however often it is seen');
        $this->assertSame(2, (int) $lanes[0]->times_seen);
        $this->assertSame($first->first_seen_at, $lanes[0]->first_seen_at);
        $this->assertSame((int) $run->id, (int) $lanes[0]->first_run_id);
        $this->assertNotSame($first->last_seen_at, $lanes[0]->last_seen_at);
    }

    public function test_one_batch_counts_a_lead_once(): void
    {
        $this->existingOsmLead('node/1');

        $this->ingestDiscovered(['businesses' => [
            $this->row('node/1', 'openai'),
            $this->row('node/1', 'openai'),
        ]])->assertOk();

        $this->assertSame(1, (int) $this->lanes()[0]->times_seen);
    }

    // ── new leads ────────────────────────────────────────────────────────────────────────────

    public function test_a_same_search_overlap_inserts_one_osm_lead_with_the_ai_lane(): void
    {
        $this->ingestDiscovered(['businesses' => [
            $this->row('node/2', 'openstreetmap', ['discovery_also_found_by' => ['openai']]),
        ]])->assertOk()->assertJsonPath('data.summary.valid', 1);

        $lead = $this->lead('node/2');
        $this->assertSame('openstreetmap', $lead->source, 'the primary source stays OpenStreetMap');
        $this->assertCount(1, $this->lanes());
        $this->assertSame($lead->id, (int) $this->lanes()[0]->lead_id);
        $this->assertSame('openai', $this->lanes()[0]->lane);
    }

    public function test_an_ai_only_lead_keeps_its_source_and_also_gets_its_lane(): void
    {
        $this->ingestDiscovered(['businesses' => [$this->row('node/3', 'openai')]])->assertOk();

        $this->assertSame('openai', $this->lead('node/3')->source);
        $this->assertCount(1, $this->lanes());
    }

    public function test_an_osm_only_lead_writes_no_lane(): void
    {
        $this->ingestDiscovered(['businesses' => [$this->row('node/4', 'openstreetmap')]])->assertOk();

        $this->assertSame('openstreetmap', $this->lead('node/4')->source);
        $this->assertSame([], $this->lanes());
    }

    public function test_a_dry_run_writes_no_lane(): void
    {
        $this->existingOsmLead('node/1');

        $this->ingestDiscovered(['dry_run' => true, 'businesses' => [
            $this->row('node/1', 'openai'),
            $this->row('node/5', 'openstreetmap', ['discovery_also_found_by' => ['openai']]),
        ]])->assertOk();

        $this->assertSame([], $this->lanes());
    }

    // ── what is never recorded ───────────────────────────────────────────────────────────────

    public function test_a_google_row_never_writes_a_lane_even_if_it_claims_one(): void
    {
        $this->ingestDiscovered(['businesses' => [
            $this->row('places/g-1', 'google_places', ['discovery_also_found_by' => ['openai']]),
        ]])->assertOk();

        $this->assertSame('google_places', $this->lead('places/g-1')->source);
        $this->assertSame([], $this->lanes());
    }

    public function test_an_ai_row_matching_a_google_lead_writes_no_lane(): void
    {
        $google = Lead::factory()->create(['place_id' => 'node/9', 'source' => 'google_places', 'assigned_to' => null, 'qualified_at' => null]);
        $before = DB::table('crm_leads')->where('id', $google->id)->first();

        $this->ingestDiscovered(['businesses' => [$this->row('node/9', 'openai')]])->assertOk();

        $this->assertSame([], $this->lanes());
        $this->assertEquals($before, DB::table('crm_leads')->where('id', $google->id)->first());
    }

    public function test_an_email_only_match_writes_no_lane(): void
    {
        Lead::factory()->create(['place_id' => 'node/10', 'email' => 'hello@bakery.test', 'source' => 'openstreetmap', 'assigned_to' => null, 'qualified_at' => null]);

        $this->ingestDiscovered(['businesses' => [
            $this->row('node/11', 'openai', ['email' => 'hello@bakery.test']),
        ]])->assertOk()->assertJsonPath('data.summary.duplicates', 1);

        $this->assertSame([], $this->lanes());
    }

    public function test_only_the_ai_lane_may_be_claimed(): void
    {
        $this->ingestDiscovered(['businesses' => [
            $this->row('node/12', 'openstreetmap', ['discovery_also_found_by' => ['google_places']]),
        ]])->assertStatus(422);

        $this->assertSame([], $this->lanes());
    }

    public function test_a_rejected_row_writes_no_lane(): void
    {
        // Outside the Philippines: refused at the national boundary, so nothing is recorded.
        $this->ingestDiscovered(['businesses' => [
            $this->row('node/13', 'openai', ['latitude' => 35.68, 'longitude' => 139.69]),
        ]])->assertOk();

        $this->assertSame([], $this->lanes());
    }

    // ── the Nearby board ─────────────────────────────────────────────────────────────────────

    public function test_nearby_reports_every_lane_that_found_a_lead(): void
    {
        $this->ingestDiscovered(['businesses' => [
            $this->row('node/20', 'openstreetmap'),
            $this->row('node/21', 'openai'),
            $this->row('node/22', 'openstreetmap', ['discovery_also_found_by' => ['openai']]),
        ]])->assertOk();
        Lead::factory()->create(['place_id' => 'places/g-2', 'source' => 'google_places', 'business_name' => 'Google Bakery', 'assigned_to' => null, 'qualified_at' => null]);

        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');
        $this->app['auth']->forgetGuards();
        $this->withoutToken();
        Sanctum::actingAs($bd);
        $rows = collect($this->getJson('/api/v1/crm/nearby-clients')->assertOk()->json('data'))->keyBy('business');

        $this->assertSame('openstreetmap', $rows['Bakery node/20']['foundBy']);
        $this->assertSame(['openstreetmap'], $rows['Bakery node/20']['foundByAll']);
        $this->assertSame('ai-assisted', $rows['Bakery node/21']['foundBy']);
        $this->assertSame(['ai-assisted'], $rows['Bakery node/21']['foundByAll']);
        // ONE lead, two lanes: its primary lane is unchanged, and it is ALSO AI-assisted.
        $this->assertSame('openstreetmap', $rows['Bakery node/22']['foundBy']);
        $this->assertSame(['openstreetmap', 'ai-assisted'], $rows['Bakery node/22']['foundByAll']);
        // Google is exactly as it was.
        $this->assertSame('google-places', $rows['Google Bakery']['foundBy']);
        $this->assertSame(['google-places'], $rows['Google Bakery']['foundByAll']);
        $this->assertCount(1, $rows->filter(fn ($r) => $r['business'] === 'Bakery node/22'), 'one row, not two');
    }
}
