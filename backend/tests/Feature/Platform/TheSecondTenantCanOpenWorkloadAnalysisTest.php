<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Projects\Models\WorkloadSetting;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `pm_workload_settings` is a singleton — one row of capacity bands per org.
 *
 * It was written when there was one tenant, and it still says so out loud:
 *
 *     firstOrCreate(['id' => 1], [...])
 *
 * The row is looked up by its PRIMARY KEY. Under tenancy the BelongsToOrg scope
 * also adds `org_id = <yours>`, so the lookup means "the row that is both mine
 * AND id 1" — and only ONE org in the whole installation can ever satisfy that.
 * Whoever got id 1 is fine forever; everybody else misses their own row on every
 * single call.
 *
 * The failure is delayed by exactly one request, which is why nobody caught it:
 *
 *   1st call — no row of mine yet, so the insert succeeds and hands back a row
 *              whose id is 2, 3, 4 … anything but 1.
 *   2nd call — my row exists, but the lookup still asks for id 1, misses it, and
 *              runs the insert again — straight into the `unique(org_id)` index
 *              the tenancy migrations added. SQLSTATE 23000.
 *
 * Every other singleton in this codebase — hr_payroll_settings, hr_leave_settings,
 * hr_attendance_policies — already uses `firstOrCreate([], ...)` and lets the org
 * scope BE the key. This one was missed.
 *
 * What the user sees is not a 500. The Workload Analysis view reads
 * `data?.members ?? []`, so the failed request renders as four zeroed KPI cards
 * and the sentence "No active work is assigned right now." — a broken page
 * wearing an empty state's clothes.
 */
class TheSecondTenantCanOpenWorkloadAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function orgOnProjects(string $email): int
    {
        return (int) app(OrgOnboardingService::class)->createOrg(
            'Org '.$email, 'Owner '.$email, $email, 'password-123', ['pms' => 'basic'],
        )['org']->id;
    }

    public function test_a_tenant_who_did_not_get_id_one_can_ask_twice(): void
    {
        $first = $this->orgOnProjects('alpha@client.ph');
        $second = $this->orgOnProjects('beta@client.ph');

        CurrentOrg::set($first);
        WorkloadSetting::current();

        CurrentOrg::set($second);
        // The row this hands back cannot be id 1 — the first org already took it.
        $mine = WorkloadSetting::current();
        $this->assertSame($second, (int) $mine->org_id);

        // And this is the call that breaks in production: same org, same row,
        // second look. Nothing about the state changed except that the row the
        // lookup is meant to find now exists.
        $again = WorkloadSetting::current();

        $this->assertSame(
            (int) $mine->getKey(),
            (int) $again->getKey(),
            'the second look handed back a different row than the first',
        );
    }

    public function test_every_tenant_keeps_exactly_one_capacity_row(): void
    {
        $orgs = [
            $this->orgOnProjects('gamma@client.ph'),
            $this->orgOnProjects('delta@client.ph'),
            $this->orgOnProjects('epsilon@client.ph'),
        ];

        // Twice round, so the second pass is asking for a row that already exists.
        foreach ([...$orgs, ...$orgs] as $orgId) {
            CurrentOrg::set($orgId);
            WorkloadSetting::current();
        }

        $perOrg = DB::table('pm_workload_settings')
            ->select('org_id', DB::raw('count(*) as c'))
            ->groupBy('org_id')
            ->pluck('c', 'org_id');

        $this->assertCount(3, $perOrg, 'each tenant should have its own capacity row');
        foreach ($orgs as $orgId) {
            $this->assertSame(1, (int) ($perOrg[$orgId] ?? 0), "org {$orgId} does not have exactly one settings row");
        }
    }

    public function test_one_tenant_cannot_read_anothers_capacity_bands(): void
    {
        $first = $this->orgOnProjects('zeta@client.ph');
        $second = $this->orgOnProjects('eta@client.ph');

        CurrentOrg::set($first);
        WorkloadSetting::current()->update(['max_capacity' => 55, 'light_max' => 5, 'moderate_max' => 15]);

        CurrentOrg::set($second);
        $theirs = WorkloadSetting::current();

        $this->assertNotSame(55, (int) $theirs->max_capacity, "the second org is reading the first org's capacity row");
        $this->assertSame($second, (int) $theirs->org_id);
    }
}
