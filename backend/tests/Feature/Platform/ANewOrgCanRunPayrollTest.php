<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Org;
use App\Modules\Hr\Services\ContributionService;
use App\Modules\Hr\Services\TaxService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A new tenant's payroll must not quietly deduct nothing.
 *
 * This is the worst failure mode multi-tenancy introduced, and it is worse than a
 * crash because it does not look like one. Payroll resolves everything from dated,
 * ORG-SCOPED rows -- the BIR withholding table, the SSS/PhilHealth/Pag-IBIG
 * schedules, the payroll settings, the work schedules. The provider org got those
 * from migrations and seeders that ran once, before tenancy, and were adopted by
 * the org_id backfill.
 *
 * An org created AFTER that inherits none of them. No tax table is not an error --
 * TaxService::bandsAsOf() returns an empty array and withholds PHP 0. No
 * contribution version is not an error either, because assertRatesInForce() only
 * guards the types that EXIST. No work schedule means no scheduled workdays. The
 * run finishes, the payslip prints, every statutory line reads zero, and the first
 * person to notice is BIR.
 *
 * So these tests do not check that provisioning "ran". They check the three things
 * whose absence is silent.
 */
class ANewOrgCanRunPayrollTest extends TestCase
{
    use RefreshDatabase;

    private function newHrOrg(string $email): Org
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return app(OrgOnboardingService::class)->createOrg(
            'Client '.$email, 'Owner', $email, 'password-123', ['hr' => 'basic'],
        )['org'];
    }

    public function test_a_new_org_has_a_withholding_table(): void
    {
        $org = $this->newHrOrg('tax@client.ph');

        $bands = CurrentOrg::runAs($org->id, fn () => app(TaxService::class)->bandsAsOf('2026-01-01'));

        $this->assertNotEmpty($bands, 'No BIR table: every payslip would withhold PHP 0 and say nothing.');
    }

    public function test_a_new_org_withholds_a_real_amount_on_a_real_salary(): void
    {
        $org = $this->newHrOrg('withhold@client.ph');

        // A salary comfortably inside the taxable bands. The point is not the exact
        // figure -- it is that the figure is not zero.
        $tax = CurrentOrg::runAs($org->id, fn () => app(TaxService::class)->annualTax(1_200_000, '2026-01-01'));

        $this->assertGreaterThan(0, $tax, 'A PHP 1.2M salary withheld nothing.');
    }

    public function test_a_new_org_has_all_three_contribution_schedules_in_force(): void
    {
        $org = $this->newHrOrg('contrib@client.ph');

        CurrentOrg::runAs($org->id, function (): void {
            // Throws if a type is registered active but has no version covering the
            // date. It cannot speak for a type that was never registered at all --
            // which is why the count below is asserted separately.
            app(ContributionService::class)->assertRatesInForce('2026-01-31');

            $types = \App\Modules\Hr\Models\ContributionSetting::query()
                ->where('is_active', true)->pluck('type')->sort()->values()->all();

            $this->assertSame(['pagibig', 'philhealth', 'sss'], $types);
        });
    }

    public function test_a_new_org_has_a_default_work_schedule(): void
    {
        $org = $this->newHrOrg('sched@client.ph');

        $count = CurrentOrg::runAs(
            $org->id,
            fn () => \App\Modules\Hr\Models\WorkSchedule::query()->where('is_default', true)->count(),
        );

        $this->assertSame(1, $count, 'No default schedule: nobody has a workday to be absent from.');
    }

    public function test_one_orgs_baseline_is_not_another_orgs(): void
    {
        $a = $this->newHrOrg('a@client.ph');
        $b = $this->newHrOrg('b@client.ph');

        $countFor = fn (int $id): int => CurrentOrg::runAs(
            $id,
            fn () => \App\Modules\Hr\Models\TaxBracket::query()->count(),
        );

        // Each tenant owns its own copy, provisioned from the canonical PH defaults
        // -- never a view onto another tenant's rows, which is what would make one
        // client's edit change another client's payroll.
        $this->assertGreaterThan(0, $countFor($a->id));
        $this->assertSame($countFor($a->id), $countFor($b->id));

        CurrentOrg::runAs($a->id, function (): void {
            \App\Modules\Hr\Models\TaxBracket::query()->delete();
        });

        $this->assertSame(0, $countFor($a->id));
        $this->assertGreaterThan(0, $countFor($b->id), "Deleting A's brackets emptied B's.");
    }
}
