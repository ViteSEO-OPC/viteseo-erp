<?php

namespace Tests\Feature\Crm;

use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Models\ProviderSpendMeter;
use App\Modules\Crm\Services\Discovery\GooglePlacesIdProbe;
use App\Modules\Crm\Services\Discovery\GooglePlacesSpendGate;
use App\Modules\Crm\Services\Discovery\GooglePlacesTextSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Google Places spend guardrail, pinned from the directions it would actually fail.
 *
 * THE CENTRAL CLAIM UNDER TEST IS THAT NO AMOUNT IS HARDCODED. Every budget in this file is written
 * into `crm_nerby_provider_settings.options` and then enforced, including one that CHANGES mid-period
 * and takes effect on the very next request. $10 appears here as a fixture, never as an expectation
 * about the application.
 *
 * The rest is the failure catalogue a spend guard has to survive:
 *
 *  - **Unlimited must stay today's behaviour** — an unconfigured deployment is unchanged, and the
 *    meter still counts so an operator can see what a budget WOULD have cost before setting one.
 *  - **Zero must stop everything**, including the requests the provider gives away. "Spend nothing"
 *    is not "spend only what is free".
 *  - **The limit must bind exactly** — the last affordable request is allowed and the next is not.
 *    Off by one here is money.
 *  - **A free field mask must cost nothing**, or a connection test would consume the budget it is
 *    meant to verify.
 *  - **The environment ceiling must bind on READ**, not only on write, or a row saved before the
 *    ceiling was lowered escapes it forever.
 *  - **Unreadable configuration must refuse.** A budget nobody can parse is not permission to spend.
 *  - **The meter may never exceed the budget**, asserted as an invariant over many reservations
 *    rather than as a single comparison.
 */
class GooglePlacesSpendGateTest extends TestCase
{
    use RefreshDatabase;

    private function gate(): GooglePlacesSpendGate
    {
        return app(GooglePlacesSpendGate::class);
    }

    /**
     * Write a budget the way the Settings screen does: into the provider's options bag.
     *
     * @param  array<string, mixed>  $options
     */
    private function configure(array $options): void
    {
        NerbyProviderSetting::query()->updateOrCreate(
            ['provider' => GooglePlacesSpendGate::PROVIDER],
            ['options' => $options, 'enabled' => true],
        );
    }

    /** A pricing model with no free tier and no safety margin, so allowances are exact integers. */
    private function exactPricing(float $limitUsd, float $per1000 = 35.0): array
    {
        return [
            'spend_limit_usd' => (string) $limitUsd,
            'price_per_1000_usd' => (string) $per1000,
            'free_tier_requests' => '0',
            'safety_factor' => '1',
        ];
    }

    private function meter(): ?ProviderSpendMeter
    {
        return ProviderSpendMeter::query()->where('provider', GooglePlacesSpendGate::PROVIDER)->first();
    }

    // ── The amount is configuration, never code ──────────────────────────────────────────────

    /**
     * The published allowance formula, reproduced from a configured budget.
     *
     * $10 at $35.00/1,000 with 1,000 free events and no safety margin is 1,285 requests — the figure
     * management was shown. If this number ever moves without the configuration moving, the model
     * and the promise have diverged.
     */
    public function test_allowance_is_derived_from_the_configured_budget(): void
    {
        $this->configure([
            'spend_limit_usd' => '10',
            'price_per_1000_usd' => '35.00',
            'free_tier_requests' => '1000',
            'safety_factor' => '1',
        ]);

        $state = $this->gate()->state();

        $this->assertSame(10.0, $state['limitUsd']);
        $this->assertSame(1285, $state['requestsAllowed']);
        $this->assertFalse($state['unlimited']);
    }

    /** A different configured budget yields a different allowance. No amount is baked in. */
    public function test_a_different_budget_yields_a_different_allowance(): void
    {
        foreach ([['10', 1285], ['50', 2428], ['100', 3857]] as [$limit, $expected]) {
            $this->configure([
                'spend_limit_usd' => $limit,
                'price_per_1000_usd' => '35.00',
                'free_tier_requests' => '1000',
                'safety_factor' => '1',
            ]);

            $this->assertSame(
                $expected,
                $this->gate()->state()['requestsAllowed'],
                "A \${$limit} budget must buy {$expected} requests.",
            );
        }
    }

    /**
     * THE DYNAMIC-CONFIGURATION REQUIREMENT. Raising the budget takes effect on the NEXT request —
     * not next period, not after a deploy, not after a cache warm-up.
     */
    public function test_raising_the_budget_takes_effect_on_the_very_next_request(): void
    {
        // 105,000 micros / 35,000 per request = exactly 3 requests.
        $this->configure($this->exactPricing(0.105));

        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame(
                GooglePlacesSpendGate::ALLOWED,
                $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
                "Request {$i} is inside the budget.",
            );
        }

        $this->assertSame(
            GooglePlacesSpendGate::REFUSED_LIMIT,
            $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
            'The fourth request is beyond the budget.',
        );

        // Management doubles the budget in Settings. Nothing else changes.
        $this->configure($this->exactPricing(0.210));

        $this->assertSame(
            GooglePlacesSpendGate::ALLOWED,
            $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
            'The same request must now be allowed, with no restart and no new period.',
        );
    }

    /** And lowering it binds immediately too — a budget already exceeded stops the next request. */
    public function test_lowering_the_budget_below_what_is_spent_stops_the_next_request(): void
    {
        $this->configure($this->exactPricing(0.350));
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(GooglePlacesSpendGate::ALLOWED, $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK));
        }

        $this->configure($this->exactPricing(0.035));

        $this->assertSame(
            GooglePlacesSpendGate::REFUSED_LIMIT,
            $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
        );
    }

    // ── The four configured states ───────────────────────────────────────────────────────────

    /** No budget is today's behaviour: everything allowed — but still counted. */
    public function test_an_unconfigured_budget_is_unlimited_and_still_counts(): void
    {
        $this->configure(['price_per_1000_usd' => '35.00']);

        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(GooglePlacesSpendGate::ALLOWED, $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK));
        }

        $this->assertTrue($this->gate()->state()['unlimited']);
        $this->assertSame(20, $this->meter()->requests_used);
        $this->assertNull($this->meter()->micros_allowed, 'An unlimited meter records no ceiling.');
    }

    /** An empty options bag behaves identically — a provider nobody has configured is unchanged. */
    public function test_a_provider_with_no_options_at_all_is_unlimited(): void
    {
        $this->configure([]);

        $this->assertSame(GooglePlacesSpendGate::ALLOWED, $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK));
        $this->assertTrue($this->gate()->state()['unlimited']);
    }

    /** Zero is the kill switch, and it stops the free requests too. */
    public function test_a_zero_budget_stops_every_request_including_free_ones(): void
    {
        $this->configure($this->exactPricing(0));

        $this->assertSame(
            GooglePlacesSpendGate::REFUSED_LIMIT,
            $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
        );
        $this->assertSame(
            GooglePlacesSpendGate::REFUSED_LIMIT,
            $this->gate()->reserve(GooglePlacesTextSearch::PROBE_FIELD_MASK),
            'Zero means issue no Google Places requests, not "issue only the free ones".',
        );
        $this->assertNull($this->meter(), 'A refusal reserves nothing, so no meter row is opened.');
    }

    /** A stored budget that is not an amount is not authorisation to spend. */
    public function test_an_unreadable_budget_refuses_as_unavailable(): void
    {
        $this->configure(['spend_limit_usd' => 'ten dollars', 'price_per_1000_usd' => '35.00']);

        $this->assertSame(
            GooglePlacesSpendGate::REFUSED_UNAVAILABLE,
            $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
        );
    }

    // ── Pricing ──────────────────────────────────────────────────────────────────────────────

    /** The ids-only masks both probes use are free, so a connection test never spends a budget. */
    public function test_ids_only_masks_are_free_and_do_not_consume_the_budget(): void
    {
        $this->configure($this->exactPricing(0.035)); // exactly one billable request

        foreach ([GooglePlacesTextSearch::PROBE_FIELD_MASK, GooglePlacesIdProbe::FIELD_MASK] as $mask) {
            for ($i = 0; $i < 10; $i++) {
                $this->assertSame(GooglePlacesSpendGate::ALLOWED, $this->gate()->reserve($mask));
            }
        }

        $this->assertSame(0, $this->meter()->micros_used, 'Free masks cost nothing.');
        $this->assertSame(20, $this->meter()->requests_used, 'But they are still counted.');

        $this->assertSame(
            GooglePlacesSpendGate::ALLOWED,
            $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
            'The one billable request the budget bought is still available.',
        );
    }

    /** An unrecognised mask is assumed billable — the conservative direction. */
    public function test_an_unrecognised_mask_is_treated_as_billable(): void
    {
        $this->configure($this->exactPricing(0.035));

        $this->assertSame(GooglePlacesSpendGate::ALLOWED, $this->gate()->reserve('places.somethingNew'));
        $this->assertSame(
            GooglePlacesSpendGate::REFUSED_LIMIT,
            $this->gate()->reserve('places.somethingNew'),
            'The unknown mask consumed the budget rather than being assumed free.',
        );
    }

    /** A price of zero would make any budget infinite, so it refuses while a budget is set. */
    public function test_an_unusable_price_refuses_when_a_budget_is_set(): void
    {
        $this->configure(['spend_limit_usd' => '10', 'price_per_1000_usd' => '0']);

        $this->assertSame(
            GooglePlacesSpendGate::REFUSED_UNAVAILABLE,
            $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
        );
    }

    /** …but with no budget there is nothing to protect, so it allows. */
    public function test_an_unusable_price_allows_when_no_budget_is_set(): void
    {
        $this->configure(['price_per_1000_usd' => '0']);

        $this->assertSame(
            GooglePlacesSpendGate::ALLOWED,
            $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
        );
    }

    // ── The environment ceiling ──────────────────────────────────────────────────────────────

    /**
     * A budget above the environment's ceiling is clamped when it is SPENT, not merely when it is
     * typed. This row bypasses validation exactly as a hand-edited or pre-existing row would.
     */
    public function test_the_environment_ceiling_binds_on_read(): void
    {
        config(['nerby.spend.limit_max_usd' => 0.10]);
        $this->configure($this->exactPricing(500)); // far above the ceiling

        $this->assertSame(0.10, $this->gate()->state()['limitUsd']);

        // 100,000 micros / 35,000 per request = 2 whole requests.
        for ($i = 0; $i < 2; $i++) {
            $this->assertSame(GooglePlacesSpendGate::ALLOWED, $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK));
        }

        $this->assertSame(
            GooglePlacesSpendGate::REFUSED_LIMIT,
            $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
            'The ceiling bound the spend, not just the input.',
        );
    }

    // ── Refunds ──────────────────────────────────────────────────────────────────────────────

    /** A refund returns exactly one request's reservation. */
    public function test_a_refund_returns_the_reservation(): void
    {
        $this->configure($this->exactPricing(0.070)); // two requests

        $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK);
        $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK);
        $this->assertSame(GooglePlacesSpendGate::REFUSED_LIMIT, $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK));

        $this->gate()->refund(GooglePlacesTextSearch::FIELD_MASK);

        $this->assertSame(35_000, $this->meter()->micros_used);
        $this->assertSame(1, $this->meter()->requests_used);
        $this->assertSame(
            GooglePlacesSpendGate::ALLOWED,
            $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
            'The refunded budget is spendable again.',
        );
    }

    /** A refund can never drive the meter negative. */
    public function test_a_refund_cannot_underflow_the_meter(): void
    {
        $this->configure($this->exactPricing(1));

        $this->gate()->refund(GooglePlacesTextSearch::FIELD_MASK);
        $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK);
        $this->gate()->refund(GooglePlacesTextSearch::FIELD_MASK);
        $this->gate()->refund(GooglePlacesTextSearch::FIELD_MASK);

        $this->assertSame(0, $this->meter()->micros_used);
        $this->assertSame(0, $this->meter()->requests_used);
    }

    // ── The invariant ────────────────────────────────────────────────────────────────────────

    /**
     * Over many reservations the meter never exceeds the budget, and the number allowed is exactly
     * the derived allowance. This is the property the single-statement reservation exists to hold.
     */
    public function test_the_meter_never_exceeds_the_configured_budget(): void
    {
        $this->configure($this->exactPricing(0.350)); // 350,000 / 35,000 = 10 requests

        $allowed = 0;
        for ($i = 0; $i < 40; $i++) {
            if ($this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK) === GooglePlacesSpendGate::ALLOWED) {
                $allowed++;
            }
            $this->assertLessThanOrEqual(
                350_000,
                $this->meter()->micros_used,
                'The meter must never pass the budget, on any iteration.',
            );
        }

        $this->assertSame(10, $allowed);
    }

    /**
     * The reservation is genuinely conditional in SQL, not in PHP.
     *
     * Driving the meter to the brink by direct UPDATE — bypassing the gate entirely — must still
     * produce a refusal, which is only true if the budget test lives in the WHERE clause.
     */
    public function test_the_budget_test_is_enforced_in_the_statement_itself(): void
    {
        $this->configure($this->exactPricing(0.350));
        $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK);

        DB::table('crm_provider_spend_meter')
            ->where('provider', GooglePlacesSpendGate::PROVIDER)
            ->update(['micros_used' => 330_000]);

        $this->assertSame(
            GooglePlacesSpendGate::REFUSED_LIMIT,
            $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK),
            '330,000 + 35,000 exceeds 350,000, so the UPDATE must match no rows.',
        );
        $this->assertSame(330_000, $this->meter()->micros_used, 'A refused reservation changes nothing.');
    }

    // ── The freeze guarantee ─────────────────────────────────────────────────────────────────

    /**
     * The gate is Google-Places-only. Its meter is keyed to that provider and nothing else writes
     * one, so no other provider can be metered or refused by it.
     */
    public function test_the_meter_is_google_places_only(): void
    {
        $this->configure($this->exactPricing(0.035));
        $this->gate()->reserve(GooglePlacesTextSearch::FIELD_MASK);

        $this->assertSame(
            ['google_places'],
            ProviderSpendMeter::query()->pluck('provider')->unique()->values()->all(),
        );
    }
}
