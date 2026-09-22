<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Jobs\ProbeQueryYieldJob;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\ExecutedQueryLedger;
use App\Modules\Crm\Services\Discovery\GooglePlacesIdProbe;
use App\Modules\Crm\Services\Discovery\QueryFeedback;
use App\Modules\Crm\Services\Discovery\QueryYieldLog;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ADAPTIVE FEEDBACK — letting measurement inform the next plan, without letting it corrupt anything.
 *
 * THREE SETS THAT LOOK ALIKE AND ARE NOT, and keeping them apart is most of this slice:
 *   - COVERED  — phrases a production search actually RAN (the E2 ledger). Excludes from a plan.
 *   - MEASURED — phrases the ids-only probe asked Google about (Phase 3.2). Informs only.
 *   - PROPOSED — what the model suggested and ranked (E4).
 * A probe is not a search. Treating measured as covered would suppress searches that never happened.
 *
 * WHAT THE MODEL IS TOLD, AND WHAT IT IS NOT. Five closed verdicts, never a count: a number would
 * invite the model to reason about how many businesses exist, which is a factual claim about the
 * world and the one thing it may never make.
 *
 * THE VERDICT PAIR THAT MATTERS. `barren` and `no_new` are both "no new businesses" and demand
 * opposite responses — throw the wording away, versus keep the wording and change the angle.
 */
class DiscoveryAdaptiveFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const STRATEGY = '/api/v1/crm/discovery/strategy';

    private const CATEGORY = 'pharmacy';

    private const AREA = 'Cebu City';

    private const AI_KEY = 'sk-test-not-a-real-credential-0000abcd';

    private const GOOGLE_KEY = 'AIzaSy-test-not-a-real-google-key-00wxyz';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
        config()->set('discovery.feedback.enabled', true);
        config()->set('discovery.probe.enabled', true);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    /** Google effective (so QUERIES is the dialect), AI configured beside it. */
    private function googleAndAiLive(): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places', 'is_active' => true, 'enabled' => true,
            'api_key' => self::GOOGLE_KEY, 'key_last4' => 'wxyz',
        ]);
        NerbyProviderSetting::query()->create([
            'provider' => 'openai', 'is_active' => false, 'enabled' => true,
            'api_key' => self::AI_KEY, 'key_last4' => 'abcd', 'options' => ['model' => 'gpt-4o-mini'],
        ]);
    }

    private function log(): QueryYieldLog
    {
        return app(QueryYieldLog::class);
    }

    private function feedback(): QueryFeedback
    {
        return app(QueryFeedback::class);
    }

    /** Seed a probe measurement so a phrase acquires a verdict. */
    private function measured(string $phrase, int $returned, int $novel, bool $capped = false): void
    {
        $this->log()->recordProbe(self::CATEGORY, self::AREA, $phrase, $returned, $novel, $capped);
    }

    private function modelReturns(array $queries): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(
                ['queries' => $queries, 'selectors' => [], 'placeTypes' => [], 'subAreas' => []]
            )]]],
            'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 25],
        ])]);
    }

    private function ask(): TestResponse
    {
        return $this->postJson(self::STRATEGY, ['category' => self::CATEGORY, 'scope' => self::AREA]);
    }

    /** The decoded untrusted envelope from the last model request. */
    private function sentEnvelope(): array
    {
        $envelope = [];
        Http::assertSent(function (Request $request) use (&$envelope): bool {
            if (str_contains($request->url(), 'openai.com')) {
                $envelope = json_decode($request->data()['messages'][1]['content'], true);
            }

            return true;
        });

        return $envelope;
    }

    // --- verdict generation -----------------------------------------------------------------

    public function test_every_verdict_state_is_generated_correctly(): void
    {
        $this->measured('barren phrase', 0, 0);
        $this->measured('mined phrase', 5, 0);
        $this->measured('working phrase', 5, 3);
        $this->measured('deep phrase', 20, 10, capped: true);

        $f = $this->feedback();

        $this->assertSame(QueryFeedback::UNMEASURED, $f->verdict(self::CATEGORY, self::AREA, 'never probed'));
        $this->assertSame(QueryFeedback::BARREN, $f->verdict(self::CATEGORY, self::AREA, 'barren phrase'));
        $this->assertSame(QueryFeedback::NO_NEW, $f->verdict(self::CATEGORY, self::AREA, 'mined phrase'));
        $this->assertSame(
            QueryFeedback::PRODUCTIVE,
            $f->verdict(self::CATEGORY, self::AREA, 'working phrase'),
        );
        $this->assertSame(QueryFeedback::ABUNDANT, $f->verdict(self::CATEGORY, self::AREA, 'deep phrase'));
    }

    /**
     * THE DISTINCTION THE SLICE RESTS ON. Both have zero novelty; one means the wording is wrong and
     * the other means the wording is right and the ground is mined.
     */
    public function test_barren_and_no_new_are_never_collapsed(): void
    {
        $this->measured('found nothing', 0, 0);
        $this->measured('found only known', 8, 0);

        $f = $this->feedback();
        $barren = $f->verdict(self::CATEGORY, self::AREA, 'found nothing');
        $mined = $f->verdict(self::CATEGORY, self::AREA, 'found only known');

        $this->assertNotSame($barren, $mined);
        $this->assertSame(QueryFeedback::BARREN, $barren);
        $this->assertSame(QueryFeedback::NO_NEW, $mined);
    }

    /** A capped measurement is a FLOOR. It must never be reported as an exact total. */
    public function test_a_capped_measurement_becomes_abundant_and_carries_no_count(): void
    {
        $this->measured('deep phrase', 20, 20, capped: true);

        $report = $this->feedback()->reportFor(self::CATEGORY, self::AREA);

        $this->assertSame([['phrase' => 'deep phrase', 'verdict' => QueryFeedback::ABUNDANT]], $report);
        // No number of any kind travels with a verdict.
        $this->assertStringNotContainsString('20', (string) json_encode($report));
    }

    public function test_the_latest_measurement_decides_not_the_history(): void
    {
        // Productive last time, mined out now: the current state is what a decision needs.
        $this->measured('phrase', 10, 5);
        $this->measured('phrase', 10, 0);

        $this->assertSame(
            QueryFeedback::NO_NEW,
            $this->feedback()->verdict(self::CATEGORY, self::AREA, 'phrase'),
        );
    }

    // --- measured is not covered -------------------------------------------------------------

    /**
     * A PROBE IS NOT A SEARCH. A measured phrase must still be proposable and still executable —
     * only the E2 ledger of phrases that actually RAN may exclude anything.
     */
    public function test_a_measured_phrase_is_not_treated_as_covered(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        $this->measured('botika', 10, 4);
        $this->modelReturns(['botika', 'parmasya']);

        // `botika` survives: it was measured, never executed.
        $this->ask()->assertOk()->assertJsonPath('data.strategy.queries', ['botika', 'parmasya']);

        $envelope = $this->sentEnvelope();
        $this->assertSame([], $envelope['untrusted_target_data']['already_covered']);
        $this->assertSame(
            [['phrase' => 'botika', 'verdict' => QueryFeedback::PRODUCTIVE]],
            $envelope['untrusted_target_data']['measured'],
        );
    }

    public function test_covered_and_measured_travel_as_separate_lists(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        app(ExecutedQueryLedger::class)->record(self::CATEGORY, self::AREA, ['ran phrase'], 1);
        $this->measured('probed phrase', 6, 2);
        $this->modelReturns(['fresh phrase']);

        $this->ask()->assertOk();

        $envelope = $this->sentEnvelope()['untrusted_target_data'];
        $this->assertSame(['ran phrase'], $envelope['already_covered']);
        $this->assertSame(
            [['phrase' => 'probed phrase', 'verdict' => QueryFeedback::PRODUCTIVE]],
            $envelope['measured'],
        );
    }

    // --- what reaches the prompt -------------------------------------------------------------

    public function test_feedback_reaches_the_prompt_inside_the_untrusted_envelope(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        $this->measured('botika', 5, 0);
        $this->modelReturns(['parmasya']);

        $this->ask()->assertOk();

        $body = $this->sentEnvelope();
        // Beside already_covered, NOT as a trusted sibling of requested_dialects/limits.
        $this->assertArrayHasKey('measured', $body['untrusted_target_data']);
        $this->assertArrayNotHasKey('measured', $body);
    }

    public function test_feedback_is_absent_when_the_switch_is_off(): void
    {
        config()->set('discovery.feedback.enabled', false);
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        $this->measured('botika', 5, 0);
        $this->modelReturns(['parmasya']);

        $this->ask()->assertOk();

        // The payload is its pre-feedback shape: an empty list, not a list of verdicts.
        $this->assertSame([], $this->sentEnvelope()['untrusted_target_data']['measured']);
    }

    /** Measurement never carries provider or business data, so none can reach a prompt. */
    public function test_no_raw_provider_or_business_data_reaches_the_prompt(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        $this->measured('botika', 20, 7, capped: true);
        $this->modelReturns(['parmasya']);

        $this->ask()->assertOk();

        $body = (string) json_encode($this->sentEnvelope());
        $leaks = ['places/', 'ChIJ', 'returned', 'novel', 'capped', 'place_id', 'latitude', 'website'];
        foreach ($leaks as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_the_osm_selector_lane_receives_no_feedback(): void
    {
        $this->actingAsAdmin();
        // AI configured, no provider selected: the OpenStreetMap baseline is effective.
        NerbyProviderSetting::query()->create([
            'provider' => 'openai', 'is_active' => false, 'enabled' => true,
            'api_key' => self::AI_KEY, 'key_last4' => 'abcd', 'options' => ['model' => 'gpt-4o-mini'],
        ]);
        $this->measured('botika', 10, 5);
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(
                ['selectors' => ['shop=chemist'], 'queries' => [], 'placeTypes' => [], 'subAreas' => []]
            )]]],
            'usage' => [],
        ])]);

        $this->ask()->assertOk()->assertJsonPath('data.effectiveProvider', 'openstreetmap');

        // Verdicts are about query phrases; an OSM sweep is not asked for queries at all.
        $this->assertSame([], $this->sentEnvelope()['untrusted_target_data']['measured']);
    }

    // --- cache participation -----------------------------------------------------------------

    public function test_changed_feedback_asks_a_fresh_question(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push($this->completion(['first plan']))
            ->push($this->completion(['second plan']))]);

        $this->ask()->assertOk()->assertJsonPath('data.strategy.queries', ['first plan']);

        // A measurement arrives: the question is no longer the same question.
        $this->measured('first plan', 4, 0);

        $this->ask()->assertOk()->assertJsonPath('data.strategy.queries', ['second plan']);

        $this->assertSame(2, $this->modelCalls());
    }

    public function test_unchanged_feedback_still_reuses_the_cached_plan(): void
    {
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        $this->measured('botika', 5, 2);
        $this->modelReturns(['parmasya']);

        $this->ask()->assertOk();
        $this->ask()->assertOk()->assertJsonPath('data.usage.model', 'cache');

        $this->assertSame(1, $this->modelCalls());
    }

    public function test_the_feedback_fingerprint_is_order_insensitive(): void
    {
        $f = $this->feedback();
        $one = [
            ['phrase' => 'a', 'verdict' => QueryFeedback::BARREN],
            ['phrase' => 'b', 'verdict' => QueryFeedback::NO_NEW],
        ];
        $other = [
            ['phrase' => 'b', 'verdict' => QueryFeedback::NO_NEW],
            ['phrase' => 'a', 'verdict' => QueryFeedback::BARREN],
        ];

        // The same state learned in a different order must not buy a second model call.
        $this->assertSame($f->fingerprint($one), $f->fingerprint($other));
        $this->assertSame('none', $f->fingerprint([]));
    }

    // --- exploit / explore -------------------------------------------------------------------

    public function test_the_budget_is_two_exploit_and_one_explore_within_three(): void
    {
        $this->googleProbeReturns('A');
        // Two already rewarding, plus unmeasured candidates further down the ranking.
        $this->measured('rewarding one', 9, 4);
        $this->measured('rewarding two', 9, 5);

        $this->runJob(['rewarding one', 'rewarding two', 'unmeasured one', 'unmeasured two']);

        // Three requests, never more: the existing probe budget is unchanged.
        Http::assertSentCount(3);
        $sent = $this->probedPhrases();
        $this->assertContains('rewarding one', $sent);
        $this->assertContains('rewarding two', $sent);
        // One slot went to exploration rather than to a third exploit.
        $this->assertContains('unmeasured one', $sent);
    }

    /** THE RATCHET GUARD: something unmeasured must always get a chance to be measured. */
    public function test_an_unmeasured_candidate_receives_the_exploration_slot(): void
    {
        $this->googleProbeReturns('A');
        $this->measured('rewarding one', 9, 4);
        $this->measured('rewarding two', 9, 5);
        $this->measured('rewarding three', 9, 6);

        // Three rewarding phrases could have taken every slot; one is still reserved.
        $this->runJob(['rewarding one', 'rewarding two', 'rewarding three', 'newcomer']);

        $this->assertContains('newcomer', $this->probedPhrases());
        Http::assertSentCount(3);
    }

    public function test_a_known_barren_or_mined_phrase_never_takes_the_exploration_slot(): void
    {
        $this->googleProbeReturns('A');
        $this->measured('dead phrase', 0, 0);
        $this->measured('mined phrase', 7, 0);

        $this->runJob(['dead phrase', 'mined phrase', 'newcomer']);

        $sent = $this->probedPhrases();
        // Measurement already answered these two; re-asking buys a known answer.
        $this->assertNotContains('dead phrase', $sent);
        $this->assertNotContains('mined phrase', $sent);
        $this->assertSame(['newcomer'], $sent);
    }

    public function test_nothing_is_spent_when_every_candidate_is_already_answered(): void
    {
        $this->googleProbeReturns('A');
        $this->measured('dead phrase', 0, 0);
        $this->measured('mined phrase', 7, 0);

        $this->runJob(['dead phrase', 'mined phrase']);

        // Declining to spend is the correct answer; an unnecessary request is a real cost.
        Http::assertNothingSent();
    }

    // --- boundaries ---------------------------------------------------------------------------

    public function test_feedback_does_not_leak_between_organizations(): void
    {
        CurrentOrg::runAs(1, fn () => $this->measured('botika', 9, 4));

        $mine = CurrentOrg::runAs(1, fn () => $this->feedback()->reportFor(self::CATEGORY, self::AREA));
        $theirs = CurrentOrg::runAs(2, fn () => $this->feedback()->reportFor(self::CATEGORY, self::AREA));

        $this->assertCount(1, $mine);
        $this->assertSame([], $theirs);
    }

    public function test_ai_off_still_makes_no_model_call(): void
    {
        $this->actingAsAdmin();
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places', 'is_active' => true, 'enabled' => true,
            'api_key' => self::GOOGLE_KEY, 'key_last4' => 'wxyz',
        ]);
        $this->measured('botika', 9, 4);
        Http::fake();

        $this->ask()->assertStatus(409)->assertJsonPath('data.available', false);

        Http::assertNothingSent();
    }

    public function test_feedback_off_but_probing_on_changes_nothing_about_strategy(): void
    {
        config()->set('discovery.feedback.enabled', false);
        config()->set('discovery.probe.enabled', true);
        $this->actingAsAdmin();
        $this->googleAndAiLive();
        $this->measured('botika', 9, 4);
        $this->modelReturns(['parmasya']);

        $this->ask()->assertOk();

        // Measurements exist and are deliberately ignored: the two switches are independent.
        $this->assertSame([], $this->sentEnvelope()['untrusted_target_data']['measured']);
        $this->assertNotEmpty($this->log()->recent(self::CATEGORY, self::AREA));
    }

    // --- helpers ------------------------------------------------------------------------------

    private function completion(array $queries): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode(
                ['queries' => $queries, 'selectors' => [], 'placeTypes' => [], 'subAreas' => []]
            )]]],
            'usage' => [],
        ];
    }

    private function modelCalls(): int
    {
        $calls = 0;
        Http::assertSent(function (Request $request) use (&$calls): bool {
            if (str_contains($request->url(), 'openai.com')) {
                $calls++;
            }

            return true;
        });

        return $calls;
    }

    private function googleProbeReturns(string ...$ids): void
    {
        NerbyProviderSetting::query()->create([
            'provider' => 'google_places', 'is_active' => true, 'enabled' => true,
            'api_key' => self::GOOGLE_KEY, 'key_last4' => 'wxyz',
        ]);
        Http::fake(['places.googleapis.com/*' => Http::response([
            'places' => array_map(fn (string $id): array => ['id' => $id], $ids),
        ])]);
    }

    private function runJob(array $phrases, string $cycle = 'cycle-1'): void
    {
        (new ProbeQueryYieldJob(1, self::CATEGORY, self::AREA, $phrases, $cycle))
            ->handle(app(GooglePlacesIdProbe::class), $this->log());
    }

    /** The phrases actually sent to the provider, recovered from the request bodies. */
    private function probedPhrases(): array
    {
        $phrases = [];
        Http::assertSent(function (Request $request) use (&$phrases): bool {
            if (str_contains($request->url(), 'places.googleapis.com')) {
                $phrases[] = str_replace(' in '.self::AREA, '', (string) $request->data()['textQuery']);
            }

            return true;
        });

        return $phrases;
    }
}
