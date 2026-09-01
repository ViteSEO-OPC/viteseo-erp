<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The scraper's credential: repeatable to issue, and narrow enough that issuing it grants nothing else.
 *
 * The command exists because Sanctum tokens are per-database and a `migrate:fresh` truncates them — the
 * scraper's token silently stopped matching any row while its id was reassigned to an unrelated login
 * session, so the id resolved, the hash did not, and the only symptom was a 401. These pin both halves
 * of the fix: that re-running the command restores access, and that the account it creates cannot reach
 * anything beyond the deterministic pipeline.
 */
class ScraperTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function serviceAccount(): ?User
    {
        return User::query()->where('email', config('scraper.service_account.email'))->first();
    }

    // --- provisioning ----------------------------------------------------------

    public function test_it_creates_the_service_account_and_prints_a_token(): void
    {
        $this->assertNull($this->serviceAccount());

        $this->artisan('crm:scraper-token')
            ->expectsOutputToContain(config('scraper.service_account.email'))
            ->expectsOutputToContain('crm.leads.import')
            ->assertSuccessful();

        $user = $this->serviceAccount();
        $this->assertNotNull($user);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('scraper', $user->tokens()->first()->name);
    }

    public function test_re_running_reuses_the_account_rather_than_creating_another(): void
    {
        // The recovery path after a reseed is "run it again"; a second account would silently split
        // the credential from its permissions.
        $this->artisan('crm:scraper-token')->assertSuccessful();
        $this->artisan('crm:scraper-token')->assertSuccessful();

        $this->assertSame(
            1,
            User::query()->where('email', config('scraper.service_account.email'))->count(),
        );
    }

    public function test_without_rotate_earlier_tokens_survive(): void
    {
        $this->artisan('crm:scraper-token')->assertSuccessful();
        $this->artisan('crm:scraper-token')->assertSuccessful();

        $this->assertSame(2, $this->serviceAccount()->tokens()->count());
    }

    // --- rotation --------------------------------------------------------------

    public function test_rotate_revokes_the_accounts_prior_tokens(): void
    {
        $this->artisan('crm:scraper-token')->assertSuccessful();
        $this->artisan('crm:scraper-token')->assertSuccessful();
        $this->assertSame(2, $this->serviceAccount()->tokens()->count());

        $this->artisan('crm:scraper-token', ['--rotate' => true])->assertSuccessful();

        // Exactly the replacement remains.
        $this->assertSame(1, $this->serviceAccount()->tokens()->count());
    }

    public function test_rotate_never_touches_anybody_elses_tokens(): void
    {
        // The service account is dedicated, so `$user->tokens()` IS the scraper's token set — but a
        // regression that widened the delete would revoke live human sessions, which is worth pinning.
        $human = User::factory()->create(['status' => 'active']);
        $human->createToken('web');

        $this->artisan('crm:scraper-token')->assertSuccessful();
        $this->artisan('crm:scraper-token', ['--rotate' => true])->assertSuccessful();

        $this->assertSame(1, $human->tokens()->count());
    }

    // --- least privilege -------------------------------------------------------

    public function test_the_account_holds_the_import_permission_and_no_role(): void
    {
        $this->artisan('crm:scraper-token')->assertSuccessful();
        $user = $this->serviceAccount();

        $this->assertTrue($user->can('crm.leads.import'));
        // No role at all: a role would hand it the AI discovery endpoints, which gate on
        // `role:super_admin|crm_admin` precisely because they spend money.
        $this->assertCount(0, $user->getRoleNames());
    }

    public function test_the_token_authenticates_the_deterministic_evidence_pipeline(): void
    {
        $this->artisan('crm:scraper-token')->assertSuccessful();
        Sanctum::actingAs($this->serviceAccount());

        $this->getJson('/api/v1/crm/website-evidence/queue')->assertOk();
    }

    public function test_the_account_cannot_reach_the_paid_ai_discovery_endpoints(): void
    {
        // The absence that matters: `/discovery/proposals` spends money, so it carries a role gate on
        // top of the permission. A roleless service account must be refused there.
        $this->artisan('crm:scraper-token')->assertSuccessful();
        Sanctum::actingAs($this->serviceAccount());

        $this->postJson('/api/v1/crm/discovery/proposals', [
            'area' => 'Vigan, Ilocos Sur', 'category' => 'pharmacy', 'count' => 5,
        ])->assertForbidden();

        $this->postJson('/api/v1/crm/discovery/tag-expansions', [
            'category' => 'pharmacy',
        ])->assertForbidden();
    }

    public function test_the_account_cannot_confirm_a_website_absence(): void
    {
        // `no-website` stays a human decision. The scraper may propose and verify candidates; it may
        // never conclude, and issuing its credential must not quietly grant that.
        $this->artisan('crm:scraper-token')->assertSuccessful();
        $lead = Lead::factory()->create([
            'website' => null,
            'website_status' => 'website-unknown',
            'source' => 'openstreetmap',
            'qualified_at' => null,
        ]);

        Sanctum::actingAs($this->serviceAccount());

        $this->postJson("/api/v1/crm/leads/{$lead->id}/website-absence")->assertForbidden();
        $this->assertSame('website-unknown', $lead->refresh()->website_status);
    }
}
