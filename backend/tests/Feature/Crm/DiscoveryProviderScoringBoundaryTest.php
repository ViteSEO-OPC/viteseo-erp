<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE SCORING BOUNDARY, stated precisely: **scoring LOGIC is frozen; scoring INPUTS are not.**
 *
 * The distinction matters commercially, and getting it wrong in either direction is a real cost.
 *
 * Too loose, and a provider could hand the CRM a score. Too strict — "a business must score the same
 * no matter which provider found it" — and the whole point of a better provider evaporates: if
 * OpenAI legitimately discovers a real contact person that OpenStreetMap had no record of, the
 * frozen scorer SHOULD award the contact_person signal for it. That is not a provider changing the
 * rules; it is a provider supplying better verified facts to rules that never moved.
 *
 * So the invariant under test is provider-INDEPENDENCE of the function, not provider-independence of
 * the result:
 *
 *     score = f(verified fit-data)          and f does not take the provider as an argument
 *
 * Same verified fit-data, different provider  -> identical score, priority, status, website_status.
 * Different verified fit-data                 -> the score may differ, and legitimately should.
 * A provider-supplied score or priority       -> refused outright; the allowlist never accepts them.
 *
 * Assertions are differential rather than absolute. Hard-coded expected scores would drift with the
 * scoring config and get "re-tuned" by whoever changed it, at which point the test records behaviour
 * instead of guarding a boundary.
 *
 * Worth naming the near-miss this guards: nothing in the provider switch calls the scorer, so "safe
 * by inspection" is tempting. The same was true of the AI website-adjudication work, where a
 * provider-side change reached `website_status` — a frozen scoring INPUT — and moved scores without
 * touching a single scoring file.
 */
class DiscoveryProviderScoringBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const FAKE_KEY = 'sk-test-not-a-real-credential-0000abcd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsScraper(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function selectOpenAi(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'openai',
            'is_active' => true,
            'enabled' => true,
            'api_key' => self::FAKE_KEY,
            'key_last4' => mb_substr(self::FAKE_KEY, -4),
        ]);
    }

    /**
     * One discovered business. Identical whichever provider is named as its source, EXCEPT for the
     * optional contact person — the one piece of discovery fit-data in this fixture that a scoring
     * signal actually reads (`contact_person`, +5, fires when a real contact differs from the
     * business name). That is what lets these tests tell "richer data" apart from "different
     * provider" without touching a scoring file.
     */
    private function business(string $source, string $placeId, ?string $contactName = null): array
    {
        return array_filter([
            'business_name' => 'Kopi Roasters',
            'contact_name' => $contactName,
            'email' => null,
            'phone' => '+63 2 8123 4567',
            'industry' => 'Cafe',
            'website' => 'https://kopiroasters.ph',
            'region' => 'Metro Manila',
            'city' => 'Makati',
            'latitude' => 14.5,
            'longitude' => 121.0,
            'place_id' => $placeId,
            'source' => $source,
            // Deliberately claimed by the provider — the ingest must refuse it either way.
            'website_status' => 'no-website',
        ], fn ($v): bool => $v !== null);
    }

    /** Ingest one business and return the scoring-relevant state of the lead it produced. */
    private function ingestAndSnapshot(string $source, string $placeId, ?string $contactName = null): array
    {
        $this->postJson('/api/v1/crm/leads/discovered', [
            'businesses' => [$this->business($source, $placeId, $contactName)],
        ])->assertOk();

        $lead = Lead::query()->where('place_id', $placeId)->firstOrFail();

        // Qualify it, because a prospect is outside Lead::scopeActive and is never scored — without
        // this the score would be null on both sides and the strongest assertion would prove nothing.
        $lead->forceFill(['qualified_at' => now()])->saveQuietly();
        app(LeadScorer::class)->refresh($lead->fresh());

        $lead = $lead->fresh();

        return [
            'score' => $lead->score,
            'priority' => $lead->priority,
            'status' => $lead->status,
            'website_status' => $lead->website_status,
            'assigned_to' => $lead->assigned_to,
            'qualified' => $lead->qualified_at !== null,
        ];
    }

    public function test_identical_verified_fit_data_scores_identically_whichever_provider_supplied_it(): void
    {
        $this->actingAsScraper();

        // Baseline: the default provider, nothing configured.
        $viaOsm = $this->ingestAndSnapshot('openstreetmap', 'node/111', 'Maria Santos');

        // Now switch the CRM to AI-assisted discovery and ingest the SAME fit-data.
        $this->selectOpenAi();
        $viaOpenAi = $this->ingestAndSnapshot('openai', 'node/222', 'Maria Santos');

        // Every field the frozen pipeline owns is identical. Only provenance differs.
        $this->assertSame($viaOsm, $viaOpenAi, 'the discovery provider leaked into the lead pipeline');
    }

    public function test_richer_verified_fit_data_may_legitimately_score_differently(): void
    {
        // THE COMMERCIAL POINT. A provider that discovers a real contact person where the other found
        // none has supplied a better verified fact, and the frozen scorer awards the existing
        // contact_person signal for it. Requiring identical scores here would make a better provider
        // worthless by definition — the advantage is fit-data accuracy, not a thumb on the scale.
        $this->actingAsScraper();

        $withContact = $this->ingestAndSnapshot('openstreetmap', 'node/331', 'Maria Santos');
        $withoutContact = $this->ingestAndSnapshot('openstreetmap', 'node/332');

        $this->assertNotSame(
            $withContact['score'],
            $withoutContact['score'],
            'richer verified fit-data must be allowed to move the score through the existing signal',
        );
        // And the difference came from the DATA, not from any provider-supplied score.
        $this->assertSame($withContact['priority'], $withoutContact['priority']);
    }

    public function test_the_score_follows_the_fit_data_and_not_the_provider(): void
    {
        // The 2x2 that pins provider-independence without forbidding data-driven differences: swap
        // which provider supplies which fit-data and the scores swap with the DATA. If the provider
        // were an input to the scorer, the scores would follow the source instead.
        $this->actingAsScraper();

        $richViaOsm = $this->ingestAndSnapshot('openstreetmap', 'node/441', 'Maria Santos');
        $sparseViaOsm = $this->ingestAndSnapshot('openstreetmap', 'node/442');

        $this->selectOpenAi();
        $richViaOpenAi = $this->ingestAndSnapshot('openai', 'node/443', 'Maria Santos');
        $sparseViaOpenAi = $this->ingestAndSnapshot('openai', 'node/444');

        // Same data, different provider -> same score.
        $this->assertSame($richViaOsm['score'], $richViaOpenAi['score']);
        $this->assertSame($sparseViaOsm['score'], $sparseViaOpenAi['score']);
        // Different data -> different score, on BOTH providers alike.
        $this->assertNotSame($richViaOsm['score'], $sparseViaOsm['score']);
        $this->assertNotSame($richViaOpenAi['score'], $sparseViaOpenAi['score']);
        // The delta is a property of the data and is identical whichever provider carried it.
        $this->assertSame(
            $richViaOsm['score'] - $sparseViaOsm['score'],
            $richViaOpenAi['score'] - $sparseViaOpenAi['score'],
            'the scoring function must not take the provider as an argument',
        );
    }

    public function test_provenance_is_the_only_difference_between_the_two_leads(): void
    {
        $this->actingAsScraper();

        $this->ingestAndSnapshot('openstreetmap', 'node/111');
        $this->selectOpenAi();
        $this->ingestAndSnapshot('openai', 'node/222');

        // `source` is what cohorts the provider comparison — it must differ, and it is the ONLY
        // thing that may.
        $this->assertSame('openstreetmap', Lead::query()->where('place_id', 'node/111')->value('source'));
        $this->assertSame('openai', Lead::query()->where('place_id', 'node/222')->value('source'));
    }

    public function test_no_provider_can_claim_a_website_status(): void
    {
        $this->actingAsScraper();
        $this->selectOpenAi();

        // Both payloads assert `no-website`, which carries the +25 greenfield scoring signal — the
        // largest Fit weight in the model. LeadImportService overrides it regardless of provider
        // ("not a passthrough — a refusal"), so no discovery engine can award itself score.
        $this->ingestAndSnapshot('openai', 'node/333');

        $this->assertSame(
            'website-unknown',
            Lead::query()->where('place_id', 'node/333')->value('website_status'),
        );
    }

    public function test_no_provider_can_set_engagement_or_ownership_fields(): void
    {
        $this->actingAsScraper();
        $this->selectOpenAi();

        // The ingest allowlist excludes these entirely, so a provider cannot reach them even by
        // sending them. Asserted for the AI path specifically, since it is the new one.
        $this->postJson('/api/v1/crm/leads/discovered', [
            'businesses' => [[
                ...$this->business('openai', 'node/444'),
                'priority' => 'high',
                'status' => 'interested',
                'score' => 99,
                'assigned_to' => 1,
                'email_outreach' => 'replied',
                'cold_calling' => 'called',
            ]],
        ])->assertOk();

        $lead = Lead::query()->where('place_id', 'node/444')->firstOrFail();

        $this->assertNotSame('high', $lead->priority);
        $this->assertNotSame('interested', $lead->status);
        $this->assertNull($lead->assigned_to);
        // A prospect is outside Business Computation until a human qualifies it.
        $this->assertNull($lead->qualified_at);
    }
}
