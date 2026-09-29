<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\DiscoveryRun;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsNerbyService;
use Tests\TestCase;

/**
 * PROVENANCE-ONLY AI FINDINGS: AI-lane businesses the engine's per-search limit dropped from the
 * result, sent beside the batch so a lead the CRM ALREADY holds can still be recorded as found by AI.
 *
 * They annotate `crm_lead_discovery_lanes` and nothing else: never a new lead, never a change to a
 * lead row, never counted in the batch's total / valid / duplicates / rejected, never on a Google
 * lead, never outside the Philippines, and accepted only from a discovery run's own token.
 */
class ProvenanceOnlyDiscoveryLanesTest extends TestCase
{
    use ActsAsNerbyService;
    use RefreshDatabase;

    private DiscoveryRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['status' => 'active']);
        $this->run = DiscoveryRun::query()->create([
            'uuid' => (string) Str::uuid(), 'requested_by' => User::query()->value('id'),
            'scope_class' => DiscoveryRun::SCOPE_NATIONWIDE, 'status' => DiscoveryRun::STATUS_RUNNING,
            'scope' => ['niches' => ['bakery']],
        ]);
    }

    private function row(string $placeId, string $source, array $extra = []): array
    {
        return [
            'business_name' => 'Bakery '.$placeId, 'industry' => 'Bakery', 'city' => 'Bacolod',
            'latitude' => 10.6765, 'longitude' => 122.9509, 'place_id' => $placeId, 'source' => $source,
            ...$extra,
        ];
    }

    private function finding(string $placeId, array $overrides = []): array
    {
        return ['place_id' => $placeId, 'lane' => 'openai', 'latitude' => 10.6765, 'longitude' => 122.9509, ...$overrides];
    }

    /** Ingest as a discovery RUN — the only caller whose provenance-only findings are accepted. */
    private function ingestAsRun(array $payload): TestResponse
    {
        $token = app(NerbyServiceIdentity::class)->issue($this->tenant()->id, 'run:'.$this->run->uuid, now()->addHour());
        $this->app['auth']->forgetGuards();

        return $this->withToken($token->plainTextToken)->postJson('/api/v1/crm/leads/discovered', $payload);
    }

    /** An existing lead in the shape a BD works with: owned, qualified, scored, prioritised. */
    private function existingLead(string $placeId, string $source = 'openstreetmap'): Lead
    {
        $owner = User::factory()->create(['status' => 'active']);
        $owner->assignRole('crm_bd');

        return Lead::factory()->create([
            'place_id' => $placeId, 'source' => $source, 'assigned_to' => $owner->id,
            'qualified_at' => now()->subDay(), 'status' => 'contacted', 'priority' => 'high',
            'business_name' => 'Existing '.$placeId, 'latitude' => 10.6765, 'longitude' => 122.9509,
        ]);
    }

    /** @return list<object> */
    private function lanes(): array
    {
        return DB::table('crm_lead_discovery_lanes')->orderBy('id')->get()->all();
    }

    // ── what it records ──────────────────────────────────────────────────────────────────────

    public function test_a_dropped_ai_finding_annotates_an_existing_osm_lead_and_touches_nothing_else(): void
    {
        $lead = $this->existingLead('node/1');
        $before = DB::table('crm_leads')->where('id', $lead->id)->first();
        $activities = DB::table('crm_activities')->count();
        $leads = DB::table('crm_leads')->count();

        $this->ingestAsRun([
            'businesses' => [$this->row('node/500', 'openstreetmap')],
            'provenance_only' => [$this->finding('node/1')],
        ])->assertOk()
            // The batch's own numbers describe the batch alone.
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.valid', 1)
            ->assertJsonPath('data.summary.duplicates', 0)
            ->assertJsonPath('data.summary.rejected', 0)
            ->assertJsonPath('data.summary.provenanceOnly', ['received' => 1, 'invalid' => 0, 'outsidePh' => 0, 'recorded' => 1]);

        $this->assertEquals($before, DB::table('crm_leads')->where('id', $lead->id)->first(), 'the lead row is byte-identical');
        $this->assertSame($activities + 1, DB::table('crm_activities')->count(), 'only the batch\'s new lead logs its creation');
        $this->assertSame($leads + 1, DB::table('crm_leads')->count(), 'only the batch\'s own row became a lead');

        $lanes = $this->lanes();
        $this->assertCount(1, $lanes);
        $this->assertSame($lead->id, (int) $lanes[0]->lead_id);
        $this->assertSame('openai', $lanes[0]->lane);
        $this->assertSame((int) $this->run->id, (int) $lanes[0]->first_run_id);
        $this->assertSame('openstreetmap', $lead->fresh()->source);
    }

    public function test_an_existing_non_osm_non_google_lead_is_annotated_too(): void
    {
        $lead = $this->existingLead('node/2', 'nearby');

        $this->ingestAsRun(['businesses' => [$this->row('node/501', 'openstreetmap')], 'provenance_only' => [$this->finding('node/2')]])
            ->assertOk()->assertJsonPath('data.summary.provenanceOnly.recorded', 1);

        $this->assertSame([$lead->id], collect($this->lanes())->pluck('lead_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('nearby', $lead->fresh()->source);
    }

    public function test_a_finding_the_same_batch_just_created_as_a_sweep_lead_is_recorded_on_it(): void
    {
        // Another cell's sweep kept the business; this cell's AI lane found it but had to drop it.
        $this->ingestAsRun([
            'businesses' => [$this->row('node/3', 'openstreetmap')],
            'provenance_only' => [$this->finding('node/3')],
        ])->assertOk()->assertJsonPath('data.summary.valid', 1)->assertJsonPath('data.summary.provenanceOnly.recorded', 1);

        $lead = Lead::query()->where('place_id', 'node/3')->sole();
        $this->assertSame('openstreetmap', $lead->source, 'still one lead, OpenStreetMap primary');
        $this->assertCount(1, $this->lanes());
        $this->assertSame($lead->id, (int) $this->lanes()[0]->lead_id);
    }

    public function test_a_finding_sent_both_as_a_row_and_provenance_only_is_one_sighting(): void
    {
        $this->existingLead('node/4');

        $this->ingestAsRun([
            'businesses' => [$this->row('node/4', 'openai')],
            'provenance_only' => [$this->finding('node/4'), $this->finding('node/4')],
        ])->assertOk();

        $this->assertCount(1, $this->lanes());
        $this->assertSame(1, (int) $this->lanes()[0]->times_seen);
    }

    // ── what it never does ───────────────────────────────────────────────────────────────────

    public function test_an_unknown_business_is_never_created_from_a_finding(): void
    {
        $leads = DB::table('crm_leads')->count();

        $this->ingestAsRun([
            'businesses' => [$this->row('node/502', 'openstreetmap')],
            'provenance_only' => [$this->finding('node/999')],
        ])->assertOk()->assertJsonPath('data.summary.provenanceOnly.recorded', 0);

        $this->assertSame($leads + 1, DB::table('crm_leads')->count());
        $this->assertFalse(Lead::query()->where('place_id', 'node/999')->exists());
        $this->assertSame([], $this->lanes());
    }

    public function test_a_google_lead_never_receives_the_lane(): void
    {
        $google = Lead::factory()->create(['place_id' => 'node/5', 'source' => 'google_places', 'assigned_to' => null, 'qualified_at' => null]);
        $before = DB::table('crm_leads')->where('id', $google->id)->first();

        $this->ingestAsRun([
            'businesses' => [$this->row('node/503', 'openstreetmap')],
            'provenance_only' => [$this->finding('node/5')],
        ])->assertOk()->assertJsonPath('data.summary.provenanceOnly.recorded', 0);

        $this->assertSame([], $this->lanes());
        $this->assertEquals($before, DB::table('crm_leads')->where('id', $google->id)->first());
    }

    public function test_a_google_place_id_is_refused_as_invalid_and_the_batch_still_lands(): void
    {
        Lead::factory()->create(['place_id' => 'places/g-1', 'source' => 'google_places', 'assigned_to' => null, 'qualified_at' => null]);

        $this->ingestAsRun([
            'businesses' => [$this->row('node/504', 'openstreetmap')],
            'provenance_only' => [$this->finding('places/g-1')],
        ])->assertOk()
            ->assertJsonPath('data.summary.valid', 1)
            ->assertJsonPath('data.summary.provenanceOnly', ['received' => 1, 'invalid' => 1, 'outsidePh' => 0, 'recorded' => 0]);

        $this->assertSame([], $this->lanes());
    }

    public function test_malformed_entries_are_dropped_and_counted_never_fatal(): void
    {
        $this->existingLead('node/6');

        $this->ingestAsRun([
            'businesses' => [$this->row('node/505', 'openstreetmap')],
            'provenance_only' => [
                $this->finding('node/6', ['lane' => 'google_places']),
                $this->finding('node/6', ['latitude' => null]),
                $this->finding('node/6', ['longitude' => 'east']),
                $this->finding('node/6', ['latitude' => 91]),
                $this->finding('node/0'),
                'node/6',
                ['place_id' => 'node/6'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.summary.valid', 1)
            ->assertJsonPath('data.summary.provenanceOnly', ['received' => 7, 'invalid' => 7, 'outsidePh' => 0, 'recorded' => 0]);

        $this->assertSame([], $this->lanes());
    }

    public function test_a_finding_outside_the_philippines_is_not_recorded(): void
    {
        $this->existingLead('node/7');

        $this->ingestAsRun([
            'businesses' => [$this->row('node/506', 'openstreetmap')],
            'provenance_only' => [$this->finding('node/7', ['latitude' => 35.6762, 'longitude' => 139.6503])],
        ])->assertOk()
            ->assertJsonPath('data.summary.rejected', 0)
            ->assertJsonPath('data.summary.provenanceOnly', ['received' => 1, 'invalid' => 0, 'outsidePh' => 1, 'recorded' => 0]);

        $this->assertSame([], $this->lanes());
    }

    public function test_a_dry_run_records_nothing(): void
    {
        $this->existingLead('node/8');

        $this->ingestAsRun([
            'dry_run' => true,
            'businesses' => [$this->row('node/507', 'openstreetmap')],
            'provenance_only' => [$this->finding('node/8')],
        ])->assertOk()->assertJsonPath('data.summary.provenanceOnly.recorded', 0);

        $this->assertSame([], $this->lanes());
    }

    public function test_a_soft_deleted_lead_is_not_annotated(): void
    {
        $this->existingLead('node/9')->delete();

        $this->ingestAsRun([
            'businesses' => [$this->row('node/508', 'openstreetmap')],
            'provenance_only' => [$this->finding('node/9')],
        ])->assertOk()->assertJsonPath('data.summary.provenanceOnly.recorded', 0);

        $this->assertSame([], $this->lanes());
    }

    public function test_only_a_discovery_runs_token_may_send_findings(): void
    {
        $this->existingLead('node/10');

        // A NERBY caller that is not a discovery run (an enrichment tick, here the test purpose).
        $this->ingestDiscovered([
            'businesses' => [$this->row('node/509', 'openstreetmap')],
            'provenance_only' => [$this->finding('node/10')],
        ])->assertOk()->assertJsonMissingPath('data.summary.provenanceOnly');

        $this->assertSame([], $this->lanes());
    }

    public function test_a_request_without_findings_keeps_its_exact_response_shape(): void
    {
        $response = $this->ingestAsRun(['businesses' => [$this->row('node/510', 'openstreetmap')]])->assertOk();

        $this->assertSame(
            ['total', 'valid', 'duplicates', 'updated', 'websiteCandidates', 'unverified', 'rejected'],
            array_keys($response->json('data.summary')),
        );
    }

    public function test_at_most_five_hundred_findings_are_read(): void
    {
        $this->existingLead('node/11');
        $findings = array_map(fn (int $i) => $this->finding('node/'.(1000 + $i)), range(1, 500));
        $findings[] = $this->finding('node/11'); // the 501st — past the cap, so never read

        $this->ingestAsRun(['businesses' => [$this->row('node/511', 'openstreetmap')], 'provenance_only' => $findings])
            ->assertOk()
            ->assertJsonPath('data.summary.provenanceOnly.received', 501)
            ->assertJsonPath('data.summary.provenanceOnly.invalid', 1)
            ->assertJsonPath('data.summary.provenanceOnly.recorded', 0);

        $this->assertSame([], $this->lanes());
    }
}
