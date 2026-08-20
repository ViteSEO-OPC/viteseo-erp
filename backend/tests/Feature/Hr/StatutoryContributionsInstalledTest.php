<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\ContributionBracket;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Services\ContributionService;
use App\Modules\Hr\Support\StatutoryContributions;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * SSS / PhilHealth / Pag-IBIG must exist in a database that has only been MIGRATED.
 *
 * The guard for a real deployment fault: the three used to arrive only through
 * ContributionSettingSeeder, alongside demo employees and fake payslips, so an environment that
 * ran `migrate` without `db:seed` opened Company → Benefits to an empty "Government mandated"
 * section with no action to add them. The silent half was worse — no rows means nothing is
 * `is_active`, so assertRatesInForce() had nothing to complain about and payroll would have paid
 * every payslip gross.
 *
 * NOTHING IS SEEDED IN THIS CLASS ON PURPOSE. Adding a seed call would defeat it entirely.
 */
class StatutoryContributionsInstalledTest extends HrTestCase
{
    use RefreshDatabase;

    public function test_the_three_contributions_exist_and_are_active_without_seeding(): void
    {
        $this->assertSame(
            ['pagibig', 'philhealth', 'sss'],
            ContributionSetting::orderBy('type')->pluck('type')->all(),
            'a migrated database has no government contributions — Company → Benefits renders an empty section',
        );

        $this->assertTrue(
            ContributionSetting::where('is_active', false)->doesntExist(),
            'a statutory contribution is installed switched off, so it would not be deducted',
        );
    }

    public function test_every_contribution_has_a_rate_in_force_today(): void
    {
        $inForce = app(ContributionService::class)
            ->versionsAsOf(now(config('app.timezone_local'))->toDateString());

        foreach (ContributionVersion::TYPES as $type) {
            $this->assertTrue($inForce->has($type), "no {$type} rate is in force, so payroll would deduct nothing for it");
            $this->assertGreaterThan(0, (float) $inForce->get($type)->employee_rate, "{$type} is installed at a zero employee rate");
            $this->assertGreaterThan(0, (float) $inForce->get($type)->employer_rate, "{$type} is installed at a zero employer rate");
        }
    }

    /** SSS prices off an MSC table, so the version being present is not enough on its own. */
    public function test_the_sss_bracket_table_is_installed(): void
    {
        $sss = app(ContributionService::class)
            ->versionsAsOf(now(config('app.timezone_local'))->toDateString())
            ->get('sss');

        $this->assertSame('bracket', $sss->basis);
        $this->assertSame(61, $sss->brackets()->count(), 'the SSS schedule is installed with the wrong number of bands');
        $this->assertSame('35000.00', $sss->brackets()->max('msc'), 'the top MSC band is missing');
    }

    /**
     * `db:seed` still runs ContributionSettingSeeder on top of an already-migrated database, and
     * a deploy may re-run either. Both must be no-ops rather than duplicate-key failures — which
     * is exactly what the version lookup did until it stopped keying on a raw date string.
     */
    public function test_installing_again_changes_nothing(): void
    {
        $before = [ContributionSetting::count(), ContributionVersion::count(), ContributionBracket::count()];

        StatutoryContributions::install();
        $this->seed(\App\Modules\Hr\Database\Seeders\ContributionSettingSeeder::class);

        $this->assertSame(
            $before,
            [ContributionSetting::count(), ContributionVersion::count(), ContributionBracket::count()],
            're-installing the statutory contributions duplicated rows',
        );
    }
}
