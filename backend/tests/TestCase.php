<?php

namespace Tests;

use App\Models\Org;
use App\Models\Subscription;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use App\Support\FeatureRegistry;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * The tenant every test runs inside.
     *
     * Named after the provider org DatabaseSeeder creates, on purpose: a test that
     * seeds the demo data then finds THIS org through `Org::firstOrCreate` instead
     * of minting a second one and splitting the fixture across two tenants.
     */
    protected const TENANT = 'Vite SEO Digital Advertising OPC';

    /**
     * Tables carrying rows that migrations wrote before any org existed, per
     * connection. Computed once per process — see tenant().
     *
     * @var array<string, list<string>>
     */
    private static array $orphanTables = [];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * CurrentOrg's pinned org is a static, and nothing clears it: the
         * SetCurrentOrg middleware sets it on every authenticated request and
         * leaves it set. Within one connection that is invisible, because ids
         * restart at 1 when RefreshDatabase rolls the test back. Across the two
         * connections this suite uses it is not: MySQL does not roll back an
         * AUTO_INCREMENT, so the HR tests walk the org id up, and the next sqlite
         * test then stamps its rows with an org id that exists in neither
         * database -- surfacing as a bare "FOREIGN KEY constraint failed" in
         * Projects, hundreds of tests away from the HR test that caused it.
         */
        CurrentOrg::clear();

        // Unit tests that never touch a database have no schema to seed into.
        if (Schema::hasTable('orgs')) {
            /*
             * PINNED, not merely created.
             *
             * A real request is pinned by the SetCurrentOrg middleware before a line
             * of application code runs. A test body is not: it builds fixtures
             * directly, outside any request, and CurrentOrg::id() now FAILS CLOSED
             * with no authenticated user rather than falling back to the first org --
             * because a background path that guesses a tenant is the bug that change
             * exists to stop.
             *
             * So the test says which tenant it is in, the same way the middleware
             * would. An HTTP request still re-pins from its own user, and a test
             * that needs another org wraps that part in CurrentOrg::runAs(), which
             * saves and restores around this.
             */
            CurrentOrg::set($this->tenant()->id);
        }
    }

    /**
     * Make the requests that follow look like they come from the app itself.
     *
     * Sanctum treats a request as the first-party browser -- and starts a session
     * for it -- only when its Origin (or Referer) is one of the stateful domains.
     * A browser always sends one; a test sends none unless asked. The app's own
     * URL is used because production serves the frontend and the API from it.
     */
    protected function fromTheApp(): static
    {
        return $this->withHeader('Origin', rtrim((string) config('app.url'), '/'));
    }

    /**
     * The org this test runs as — created on demand, rolled back with the test.
     *
     * Production has had exactly one org since before multi-tenancy: the provider,
     * which already existed when the org_id migrations ran, so their backfill
     * claimed every pre-tenancy row for it and the subsequent NOT NULL change stuck.
     *
     * A fresh test database has none of that. `migrate:fresh` creates NO org, so:
     *
     *   - `CurrentOrg::id()` resolves to null and every insert into a NOT NULL
     *     org_id column dies. That single fact accounted for 2,266 of the 2,648
     *     failures in this suite.
     *   - the backfill had nothing to fill, so the rows migrations DO install
     *     (the BIR table, the SSS/PhilHealth/Pag-IBIG schedules, the opening
     *     policy versions, the CRM business rules) are stranded on org_id NULL,
     *     invisible to any org. Payroll would price them at zero and say nothing.
     *   - there is no subscription, so `subscription-active` answers 402 on every
     *     HR/CRM/Projects route, and no entitlements, so `feature:<module>` 403s.
     *
     * So this reproduces the state production was actually in when tenancy shipped,
     * rather than leaving tests in a state production has never been in.
     */
    protected function tenant(): Org
    {
        $org = Org::firstOrCreate(['name' => self::TENANT], ['is_active' => true]);

        if (! $org->wasRecentlyCreated) {
            return $org;
        }

        $this->adoptPreTenancyRows($org->id);

        // The provider avails everything; a test should fail on the behaviour it is
        // describing, not on a module the fixture forgot to buy.
        Entitlements::enable($org->id, FeatureRegistry::keys());

        Subscription::firstOrCreate(['org_id' => $org->id], [
            'plan' => 'full',
            'monthly_price' => 0,
            // Well clear of the seat checks; a seat-limit test sets its own.
            'seat_limit' => 500,
            'status' => Subscription::ACTIVE,
            'current_period_end' => now()->addYears(5),
        ]);

        return $org;
    }

    /**
     * Hand this org the rows the migrations installed before it existed — exactly
     * what step 2 of each add_org_id_to_*_tables migration did in production.
     *
     * The table list is discovered rather than hard-coded (three modules keep their
     * own migrations, and the set drifts), and cached for the process: every test
     * rolls back, so the same tables carry the same orphans each time.
     */
    private function adoptPreTenancyRows(int $orgId): void
    {
        $connection = DB::getDefaultConnection();

        if (! array_key_exists($connection, self::$orphanTables)) {
            self::$orphanTables[$connection] = array_values(array_filter(
                array_unique(Schema::getTableListing(schemaQualified: false)),
                fn (string $table): bool => Schema::hasColumn($table, 'org_id')
                    && DB::table($table)->whereNull('org_id')->exists(),
            ));
        }

        foreach (self::$orphanTables[$connection] as $table) {
            DB::table($table)->whereNull('org_id')->update(['org_id' => $orgId]);
        }
    }
}
