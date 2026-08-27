<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /dashboard/copilot-summary` is gone, and must stay gone.
 *
 * It returned three hardcoded constants — 18 leads re-scored, 4 moved to High, and a fixed
 * `generatedAt` two months in the past — for an AI scoring run that has never run. The frontend
 * deliberately never called it, but that convention protected nobody else: the route was registered,
 * authenticated and permission-gated exactly like its working neighbours, and the API reference
 * listed it as "AI copilot summary" with nothing marking the payload as invented.
 *
 * This test exists because deleting an endpoint is easy to undo by accident — a revert, a merge, or
 * someone reading the old docs and "restoring" it. Asserting 404 for a caller who holds
 * `crm.dashboard.view` proves the route is absent rather than merely forbidden, so a reinstated
 * fabrication fails here first.
 *
 * The real dashboard endpoints are asserted alongside it: this must not be read as evidence that the
 * dashboard is broken, only that this one capability is absent.
 */
class RemovedCopilotSummaryEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');

        return $user;
    }

    public function test_the_fabricated_copilot_summary_endpoint_no_longer_exists(): void
    {
        Sanctum::actingAs($this->admin());

        // 404, not 403 — the route is gone, not merely gated. A permission failure would mean the
        // endpoint still existed and still returned invented figures to whoever could reach it.
        $this->getJson('/api/v1/crm/dashboard/copilot-summary')->assertNotFound();
    }

    public function test_the_real_dashboard_endpoints_are_untouched(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/crm/dashboard/kpis')->assertOk();
        $this->getJson('/api/v1/crm/dashboard/recent-leads')->assertOk();
        $this->getJson('/api/v1/crm/dashboard/priority-buckets')->assertOk();
        $this->getJson('/api/v1/crm/dashboard/bd-performance')->assertOk();
        $this->getJson('/api/v1/crm/dashboard/reports-analytics')->assertOk();
    }
}
