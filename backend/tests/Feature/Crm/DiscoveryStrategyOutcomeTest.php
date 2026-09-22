<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\ExecutedQueryLedger;
use App\Modules\Crm\Services\Discovery\OpenAiDiscoveryStrategist;
use App\Modules\Crm\Services\Discovery\StrategyOutcomeLog;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "AI WAS CONSULTED AND HAD NOTHING" IS NOT "AI WAS NEVER CONSULTED".
 *
 * Three separate mechanisms used to erase that distinction — the yield log ignores an empty phrase
 * list, the text search substitutes the category phrase so a plan of nothing still searches, and a
 * run's `ai_outcome` only ever rises. Each is correct alone; together they made an exhausted target
 * indistinguishable from a disabled capability. These tests pin the record that tells them apart,
 * and pin just as hard that it never invents a phrase or suppresses a search.
 */
class DiscoveryStrategyOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-test-not-a-real-credential-0000abcd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    private function actingAsRole(string $role = 'crm_admin'): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function aiConfigured(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'openai',
            'is_active' => false,
            'enabled' => true,
            'api_key' => self::KEY,
            'key_last4' => mb_substr(self::KEY, -4),
            'options' => ['model' => 'gpt-4o-mini'],
        ]);
    }

    private function googleActive(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places',
            'is_active' => true,
            'enabled' => true,
            'api_key' => 'AIzaSy-test-not-a-real-google-key-00wxyz',
            'key_last4' => 'wxyz',
        ]);
    }

    /** @param array<string, mixed> $plan */
    private function modelReturns(array $plan): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($plan)]]],
            'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 25],
        ])]);
    }

    private function outcomes(): StrategyOutcomeLog
    {
        return app(StrategyOutcomeLog::class);
    }

    private function plan(array $plan, string $scope = 'bacolod'): array
    {
        $this->modelReturns($plan);

        return app(OpenAiDiscoveryStrategist::class)->plan('pharmacy', $scope);
    }

    // --- the distinction itself ----------------------------------------------

    public function test_a_plan_with_no_usable_query_is_recorded_as_an_empty_plan(): void
    {
        $this->actingAsRole();
        $this->aiConfigured();
        $this->googleActive();

        $this->plan(['selectors' => [], 'queries' => [], 'placeTypes' => [], 'subAreas' => []]);

        $entries = $this->outcomes()->recent('pharmacy', 'bacolod');
        $this->assertCount(1, $entries);
        $this->assertSame(StrategyOutcomeLog::REASON_EMPTY_PLAN, $entries[0]['reason']);
        $this->assertSame(StrategyOutcomeLog::SOURCE_LIVE, $entries[0]['source']);
    }

    public function test_a_plan_that_produced_a_query_records_nothing(): void
    {
        $this->actingAsRole();
        $this->aiConfigured();
        $this->googleActive();

        $this->plan(['selectors' => [], 'queries' => ['drugstore'], 'placeTypes' => [], 'subAreas' => []]);

        $this->assertSame([], $this->outcomes()->recent('pharmacy', 'bacolod'));
    }

    public function test_a_target_never_consulted_has_no_outcome_at_all(): void
    {
        // The absence that carries the meaning: no record is how "never asked" reads.
        $this->assertSame([], $this->outcomes()->recent('pharmacy', 'never-searched'));
    }

    // --- every refusal is recorded, and none of them is pinned ---------------

    public function test_a_repeated_empty_plan_is_recorded_again_and_reaches_the_model_again(): void
    {
        $this->actingAsRole();
        $this->aiConfigured();
        $this->googleActive();

        $this->plan(['selectors' => [], 'queries' => [], 'placeTypes' => [], 'subAreas' => []]);

        // THE SAME QUESTION AGAIN REACHES THE MODEL. An empty query plan is no longer pinned, so the
        // repeat is a fresh consultation rather than a permanent cached answer. That is the whole
        // point: while it was cached, the plan carried no phrase, so no phrase executed, so the
        // ledger could not change, so the fingerprint could not change — and this target was
        // answered from that one entry forever without the model being asked again.
        app(OpenAiDiscoveryStrategist::class)->plan('pharmacy', 'bacolod');

        $entries = $this->outcomes()->recent('pharmacy', 'bacolod');
        // BOTH consultations are recorded. A saturated target must not look busy once and silent
        // afterwards, whichever path answered it.
        $this->assertCount(2, $entries);
        $this->assertSame(StrategyOutcomeLog::SOURCE_LIVE, $entries[0]['source']);
        $this->assertSame(StrategyOutcomeLog::SOURCE_LIVE, $entries[1]['source']);
    }

    // --- the saturation signal -----------------------------------------------

    public function test_the_record_carries_how_much_history_the_model_was_working_against(): void
    {
        $this->actingAsRole();
        $this->aiConfigured();
        $this->googleActive();

        $ledger = app(ExecutedQueryLedger::class);
        $ledger->record('pharmacy', 'bacolod', ['pharmacy', 'drugstore', 'chemist'], 3);

        $this->plan(['selectors' => [], 'queries' => [], 'placeTypes' => [], 'subAreas' => []]);

        $entries = $this->outcomes()->recent('pharmacy', 'bacolod');
        // An exhausted target and a model that simply answered poorly are opposite problems.
        $this->assertSame(3, $entries[0]['covered']);
    }

    // --- what it must never do -----------------------------------------------

    public function test_an_empty_plan_is_still_returned_unchanged_and_no_phrase_is_invented(): void
    {
        $this->actingAsRole();
        $this->aiConfigured();
        $this->googleActive();

        $result = $this->plan(['selectors' => [], 'queries' => [], 'placeTypes' => [], 'subAreas' => []]);

        // Recording a refusal must not become a way of answering one.
        $this->assertSame(OpenAiDiscoveryStrategist::STATUS_OK, $result['status']);
        $this->assertSame([], $result['strategy']['queries']);
    }

    public function test_the_record_holds_no_business_data(): void
    {
        $this->actingAsRole();
        $this->aiConfigured();
        $this->googleActive();

        $this->plan(['selectors' => [], 'queries' => [], 'placeTypes' => [], 'subAreas' => []]);

        $entry = $this->outcomes()->recent('pharmacy', 'bacolod')[0];
        $this->assertSame(
            ['at', 'reason', 'source', 'dialects', 'covered'],
            array_keys($entry),
            'the outcome record grew a field; every one of them must be a status, a count or a time',
        );
    }

    public function test_outcomes_are_scoped_to_one_organization(): void
    {
        $this->actingAsRole();
        $this->aiConfigured();
        $this->googleActive();

        CurrentOrg::set(1);
        $this->plan(['selectors' => [], 'queries' => [], 'placeTypes' => [], 'subAreas' => []]);

        CurrentOrg::set(2);
        // Exhaustion is a fact about one organization's searching, never a shared one.
        $this->assertSame([], $this->outcomes()->recent('pharmacy', 'bacolod'));
    }

    public function test_the_series_is_capped_so_an_observability_record_cannot_grow_without_bound(): void
    {
        $log = $this->outcomes();
        for ($i = 0; $i < StrategyOutcomeLog::MAX_ENTRIES + 10; $i++) {
            $log->recordEmptyPlan('pharmacy', 'bacolod', StrategyOutcomeLog::SOURCE_LIVE, ['queries'], $i);
        }

        $entries = $log->recent('pharmacy', 'bacolod');
        $this->assertCount(StrategyOutcomeLog::MAX_ENTRIES, $entries);
        // Oldest first, newest kept: the cap drops history, never the present.
        $this->assertSame(StrategyOutcomeLog::MAX_ENTRIES + 9, $entries[array_key_last($entries)]['covered']);
    }
}
