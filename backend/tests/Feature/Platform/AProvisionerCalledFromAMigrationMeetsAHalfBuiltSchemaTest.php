<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Org;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\ChartOfAccountsProvisioner;
use App\Support\CurrentOrg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `2026_09_06_010000_the_provider_avails_every_system_it_sells` calls
 * `ChartOfAccountsProvisioner::seedFor()`. A migration that calls application
 * code is pinned to the schema AS IT STANDS AT ITS OWN TIMESTAMP -- but the
 * service it calls is always today's version, and today's version writes
 * whatever columns exist today.
 *
 * `is_bank` was added to the provisioner on 2026-09-07 by a migration timestamped
 * `2026_09_07_000003`, which sorts AFTER `2026_09_06_010000`. On a live database
 * the provider org already exists, so the 09-06 migration does NOT return at its
 * guard -- it reaches the provisioner and dies:
 *
 *     SQLSTATE[42S22]: Unknown column 'is_bank' in 'field list'
 *
 * Reproduced against MySQL 9.7 before this fix. It kills the deploy ~49
 * migrations in, on a schema already stamped and half-converted, with an empty
 * down() and a re-run that fails at the identical line.
 *
 * The whole suite stayed green because a fresh test database has no users, so
 * the adopt migration creates no org, so `$providerId` is null and 09-06 returns
 * before it can reach the provisioner. **The failing path exists only on an
 * installation that has data** -- which is to say, only in production.
 *
 * So the provisioner writes what the schema can hold. This is the general rule
 * for any service a migration calls, not a patch for one column.
 */
class AProvisionerCalledFromAMigrationMeetsAHalfBuiltSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chart_of_accounts_provisions_against_a_schema_without_is_bank(): void
    {
        // The schema as it stood when 2026_09_06_010000 was written.
        Schema::table('acc_accounts', fn ($table) => $table->dropColumn('is_bank'));
        $this->assertFalse(Schema::hasColumn('acc_accounts', 'is_bank'));

        $org = Org::create(['name' => 'Vite SEO Digital Advertising OPC', 'is_active' => true]);

        app(ChartOfAccountsProvisioner::class)->seedFor($org);

        $codes = CurrentOrg::runAs($org->id, fn () => Account::query()->pluck('code')->all());
        $this->assertContains('1000', $codes, 'the books are still provisioned');
        $this->assertGreaterThanOrEqual(20, count($codes), 'the whole chart, not a partial one');
    }

    public function test_it_still_marks_the_bank_account_when_the_column_is_there(): void
    {
        $org = Org::create(['name' => 'Bituin Digital Corp', 'is_active' => true]);

        app(ChartOfAccountsProvisioner::class)->seedFor($org);

        $cash = CurrentOrg::runAs($org->id, fn () => Account::query()->where('code', '1000')->firstOrFail());
        $this->assertTrue((bool) $cash->is_bank, 'the guard must not cost the feature');
    }
}
