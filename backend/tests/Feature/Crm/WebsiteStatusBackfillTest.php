<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The data-correction migration's SCOPE — what it rewrites and, more importantly, what it must not.
 *
 * The migration itself has already run by the time these execute (RefreshDatabase), so the rows are
 * built here and the migration's own query is replayed against them. That keeps the test about the
 * RULE rather than about migration plumbing, and the rule is the part that is dangerous to get wrong:
 * too wide and it erases human judgement and audited results; too narrow and the +25 scoring defect
 * survives in production.
 */
class WebsiteStatusBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        User::factory()->create(['status' => 'active']);
    }

    /** Replays the migration's UPDATE verbatim. */
    private function runBackfill(): int
    {
        return DB::table('crm_leads')
            ->whereNotNull('source')
            ->whereIn('website_status', ['no-website', 'has-website'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('crm_lead_website_enrichments as e')
                ->whereColumn('e.lead_id', 'crm_leads.id'))
            ->update(['website_status' => 'website-unknown']);
    }

    private function lead(array $attrs): Lead
    {
        return Lead::factory()->create($attrs);
    }

    private function statusOf(Lead $lead): string
    {
        return $lead->fresh()->website_status;
    }

    // --- What it corrects ----------------------------------------------------

    public function test_a_discovered_lead_with_no_url_loses_its_no_website_guess(): void
    {
        $lead = $this->lead(['source' => 'openstreetmap', 'website' => null, 'website_status' => 'no-website']);

        $this->runBackfill();

        $this->assertSame('website-unknown', $this->statusOf($lead));
    }

    /** The other half of the same guess: a tag was present, so discovery asserted `has-website`. */
    public function test_a_discovered_lead_with_a_url_loses_its_has_website_guess(): void
    {
        $lead = $this->lead(['source' => 'openstreetmap', 'website' => 'https://x.example', 'website_status' => 'has-website']);

        $this->runBackfill();

        $this->assertSame('website-unknown', $this->statusOf($lead));
        $this->assertSame('https://x.example', $lead->fresh()->website, 'the URL survives — it is the audit input');
    }

    // --- What it must NOT touch ----------------------------------------------

    public function test_a_manually_created_lead_keeps_its_human_set_status(): void
    {
        $lead = $this->lead(['source' => null, 'website' => null, 'website_status' => 'no-website']);

        $this->runBackfill();

        $this->assertSame('no-website', $this->statusOf($lead), 'a person checked; that is not ours to overwrite');
    }

    /** Discovery cannot produce `needs-redesign`, so a discovered lead carrying it was edited by a person. */
    public function test_a_discovered_lead_marked_needs_redesign_is_left_alone(): void
    {
        $lead = $this->lead(['source' => 'openstreetmap', 'website' => 'https://x.example', 'website_status' => 'needs-redesign']);

        $this->runBackfill();

        $this->assertSame('needs-redesign', $this->statusOf($lead));
    }

    public function test_an_audited_lead_keeps_its_derived_status(): void
    {
        $lead = $this->lead(['source' => 'openstreetmap', 'website' => 'https://x.example', 'website_status' => 'has-website']);
        $lead->websiteEnrichment()->create(['website_reachable' => true, 'provider' => 'scrapy-website-intelligence']);

        $this->runBackfill();

        $this->assertSame('has-website', $this->statusOf($lead), 'this one was genuinely fetched');
    }

    // --- Reversibility -------------------------------------------------------

    /**
     * `down()` is an exact inverse, not an approximation: the corrected rows are precisely those
     * discovery decided by URL presence, so replaying that rule restores each original value.
     */
    public function test_the_migration_is_exactly_reversible(): void
    {
        $withUrl = $this->lead(['source' => 'openstreetmap', 'website' => 'https://x.example', 'website_status' => 'has-website']);
        $withoutUrl = $this->lead(['source' => 'openstreetmap', 'website' => null, 'website_status' => 'no-website']);

        $this->runBackfill();

        DB::table('crm_leads')
            ->whereNotNull('source')
            ->where('website_status', 'website-unknown')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('crm_lead_website_enrichments as e')
                ->whereColumn('e.lead_id', 'crm_leads.id'))
            ->update(['website_status' => DB::raw("CASE WHEN website IS NULL THEN 'no-website' ELSE 'has-website' END")]);

        $this->assertSame('has-website', $this->statusOf($withUrl));
        $this->assertSame('no-website', $this->statusOf($withoutUrl));
    }

    // --- Consequence ---------------------------------------------------------

    /**
     * Why any of this matters. Statuses are corrected by raw UPDATE, which bypasses the model events
     * that keep `leads.score` in step — so `leads:recompute-scores` is a REQUIRED deployment step, not
     * an optional tidy-up. Without it a corrected lead keeps a score that still contains the +25 the
     * migration just removed the basis for.
     */
    public function test_a_corrected_lead_needs_a_rescore_to_shed_the_phantom_points(): void
    {
        $lead = $this->lead([
            'source' => 'openstreetmap', 'website' => null, 'website_status' => 'no-website',
            'qualified_at' => now(), 'last_activity' => null,
        ]);
        $scoreBefore = $lead->fresh()->score;

        $this->runBackfill();

        $this->assertSame($scoreBefore, $lead->fresh()->score, 'a raw UPDATE fires no observer');

        $this->artisan('leads:recompute-scores')->assertSuccessful();

        $this->assertSame($scoreBefore - 25, $lead->fresh()->score, 'the +25 is only shed by the recompute');
    }
}
