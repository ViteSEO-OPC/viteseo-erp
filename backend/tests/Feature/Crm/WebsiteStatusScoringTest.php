<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scoring half of the website-state correction.
 *
 * `website-unknown` and `website-unreachable` must move the Personal Lead Score by exactly ZERO. They
 * are neutral BY CONSTRUCTION — they match none of the three website conditions in the calculator, so
 * no signal fires. That design is load-bearing: the point values are DB-backed (ScoringConfigProvider
 * validates a published Business Rules version against the file's `points` keys), so introducing a
 * weight for a new state would invalidate the active version and drop the engine to the file baseline.
 * Scoring by absence keeps the frozen rule set completely untouched.
 */
class WebsiteStatusScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['status' => 'active']);
    }

    /**
     * ONE deterministic active lead, mutated only on `website_status`. Every other scoring input is
     * pinned: LeadFactory randomizes contact/status/outreach columns, so two separately-created leads
     * differ on signals that have nothing to do with the website and make a delta meaningless.
     */
    private function lead(): Lead
    {
        return Lead::factory()->create([
            'business_name' => 'Fixture Co',
            'contact_name' => 'Fixture Co',   // the import fallback — NOT a real contact (no +5)
            'status' => 'new',
            'cold_calling' => 'not-called',
            'email_outreach' => 'none',
            'region' => null, 'province' => null, 'city' => null,
            'qualified_at' => now(),
            'source' => null,
            'last_activity' => null,
        ]);
    }

    /**
     * The website contribution in isolation: the signed sum of the three website signals.
     *
     * Deliberately not the total score — that is capped (fitMax / scoreMax), and a cap would mask the
     * very difference under test by compressing a +25 swing into whatever headroom happened to remain.
     */
    private function websitePoints(string $websiteStatus): int
    {
        $lead = $this->lead();
        $lead->update(['website_status' => $websiteStatus]);

        return collect(app(LeadScorer::class)->calculate($lead->fresh())->signals)
            ->whereIn('key', ['website-no-website', 'website-needs-redesign', 'has-website'])
            ->sum('points');
    }

    public function test_unknown_and_unreachable_are_worth_the_same_as_each_other(): void
    {
        $this->assertSame(0, $this->websitePoints('website-unknown'));
        $this->assertSame(0, $this->websitePoints('website-unreachable'));
    }

    /** The exact delta against the frozen weights: no-website is +25, unknown is 0. */
    public function test_unknown_forfeits_the_no_website_fit_signal(): void
    {
        $this->assertSame(25, $this->websitePoints('no-website'));
        $this->assertSame(
            25,
            $this->websitePoints('no-website') - $this->websitePoints('website-unknown'),
            'no_website is +25 in config/scoring.php; unknown must claim none of it',
        );
    }

    /** …and does not inherit the has-website penalty either. Neutral means neutral. */
    public function test_unknown_avoids_the_has_website_penalty(): void
    {
        $this->assertSame(-15, $this->websitePoints('has-website'));
        $this->assertSame(
            15,
            $this->websitePoints('website-unknown') - $this->websitePoints('has-website'),
            'has_website is -15; unknown must sit above it, not with it',
        );
    }

    /** The middle state is untouched by this change. */
    public function test_needs_redesign_is_unchanged(): void
    {
        $this->assertSame(10, $this->websitePoints('needs-redesign'));
    }

    public function test_no_website_signal_fires_for_an_unknown_lead(): void
    {
        $lead = $this->lead();
        $lead->update(['website_status' => 'website-unknown']);
        $result = app(LeadScorer::class)->calculate($lead->fresh());

        foreach (['website-no-website', 'website-needs-redesign', 'has-website'] as $key) {
            $signal = collect($result->signals)->firstWhere('key', $key);
            $this->assertNotNull($signal, "signal {$key} must still be reported");
            $this->assertFalse($signal->applied, "signal {$key} must not apply to an unverified lead");
            $this->assertSame(0, $signal->points);
        }
    }

    /**
     * The frozen weights themselves are untouched — this change adds no point key, so the published
     * Business Rules version stays valid and no republish is required.
     */
    public function test_the_frozen_website_weights_are_unchanged(): void
    {
        $this->assertSame(25, config('scoring.points.no_website'));
        $this->assertSame(10, config('scoring.points.needs_redesign'));
        $this->assertSame(15, config('scoring.points.has_website'));
        $this->assertArrayNotHasKey('website_unknown', config('scoring.points'));
        $this->assertArrayNotHasKey('website_unreachable', config('scoring.points'));
    }
}
