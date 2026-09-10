<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Services\AttendancePolicyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PDO;
use PDOException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Base class for HR feature tests — runs them against a real MySQL database instead of
 * the shared phpunit.xml sqlite connection.
 */
abstract class HrTestCase extends TestCase
{
    /** The connection these tests run on — registered in createApplication(). */
    protected const CONNECTION = 'hr_testing';

    /**
     * RefreshDatabase's "already migrated" flag, kept per connection.
     *
     * The trait guards its one migration per process behind a single global flag,
     * RefreshDatabaseState::$migrated, which records only THAT something was migrated —
     * never WHICH connection. This suite runs on two: sqlite ':memory:' for the rest of
     * the platform and this MySQL database for HR. One flag cannot speak for both, and
     * every symptom below is that flag answering for the wrong one:
     *
     *  - tests/Feature/Crm sorts before tests/Feature/Hr, so the flag is already true when
     *    the first HR test starts. RefreshDatabase skips migrating, and the HR tests run
     *    against whatever schema this database was left in by some previous run — which is
     *    how it goes stale, and why a column added today ('org_id') is simply absent.
     *    Running one HR file alone hides the bug completely: the flag is then still false.
     *
     *  - the reverse, once the first problem is fixed: a sqlite test that breaks its
     *    transaction resets the flag to false, and the next HR test answers by dropping and
     *    re-migrating the MySQL database mid-run. That re-migration races the connection
     *    still open on it and dies (1213 deadlock / 1412 table definition has changed),
     *    leaving the schema half-wiped and every later HR test erroring on a missing table.
     *    That cost 1,115 of the 1,130 failures in the run before this fix.
     *
     * So each connection carries its own flag, and HR's is swapped in for the duration of
     * an HR test. Not overridden in refreshTestDatabase(), because each test class pulls in
     * the RefreshDatabase trait itself and a trait method in the subclass beats an inherited
     * one — an override on this base class would never be called.
     */
    private static bool $hrMigrated = false;

    /** The flag as the non-HR (sqlite) tests left it, restored in tearDown(). */
    private static bool $otherMigrated = false;

    public function createApplication()
    {
        $app = require __DIR__ . '/../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        /*
         * ONE DATABASE PER RUN, not one per machine.
         *
         * This used to be a single shared name, and RefreshDatabase is allowed to
         * WIPE it. Two concurrent runs on the same box therefore destroyed each
         * other: one run's `migrate:fresh` dropped the schema under the other
         * mid-suite, which surfaces as hundreds of phantom failures in tables that
         * were fine a second earlier. That happened twice while this work was going
         * on, and cost more time diagnosing than the fix takes.
         *
         * TEST_TOKEN is what `--parallel` sets, so parallel workers get one database
         * each for free. Falling back to the PID gives concurrent *separate* runs the
         * same isolation. An explicit HR_TEST_DB_DATABASE still wins, for CI or for
         * pinning a database you want to inspect after a failure.
         */
        $database = env('HR_TEST_DB_DATABASE');

        if ($database === null) {
            $token = env('TEST_TOKEN') ?? getmypid();
            $database = "viteseo_platform_{$token}_test";
        }

        // Never let a test run drop the real database. RefreshDatabase is destructive and
        // the dev DB is shared with another clone — this guard is not optional.
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run HR tests against '{$database}': the test database name must end in '_test'.",
            );
        }

        // Borrow host/credentials from the app's mysql connection so there is one place to
        // configure them (.env), and point only the database name at the test schema.
        $base = config('database.connections.mysql', []);

        config([
            'database.connections.' . self::CONNECTION => array_merge($base, [
                'database' => $database,
                'host' => env('HR_TEST_DB_HOST', $base['host'] ?? '127.0.0.1'),
                'port' => env('HR_TEST_DB_PORT', $base['port'] ?? '3306'),
                'username' => env('HR_TEST_DB_USERNAME', $base['username'] ?? 'viteseo'),
                'password' => env('HR_TEST_DB_PASSWORD', $base['password'] ?? ''),
            ]),
            // Set BEFORE RefreshDatabase runs (TestCase::setUp calls createApplication first,
            // then setUpTraits), so the trait migrates and rolls back on this connection.
            'database.default' => self::CONNECTION,
        ]);

        $this->ensureTestDatabaseExists($database);

        // Hand RefreshDatabase this connection's own migration state. @see $hrMigrated
        self::$otherMigrated = RefreshDatabaseState::$migrated;
        RefreshDatabaseState::$migrated = self::$hrMigrated;

        return $app;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Keep whatever the trait decided about THIS connection (it clears the flag when a
        // test leaves the database dirty, so the next HR test re-migrates), and give the
        // sqlite tests their own state back.
        self::$hrMigrated = RefreshDatabaseState::$migrated;
        RefreshDatabaseState::$migrated = self::$otherMigrated;
    }

    /**
     * Create the test schema if it is missing, so a clean checkout can run these tests.
     *
     * Without it the first HR test dies on "Unknown database 'viteseo_platform_test'" and
     * the only fix is a mysql command nobody has written down. Creating it is safe for the
     * same reason RefreshDatabase is allowed to wipe it: the name must end in '_test'.
     */
    private function ensureTestDatabaseExists(string $database): void
    {
        if (self::$hrMigrated || ! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            return;
        }

        $config = config('database.connections.' . self::CONNECTION);

        try {
            $pdo = new PDO(
                "mysql:host={$config['host']};port={$config['port']}",
                $config['username'],
                $config['password'],
            );

            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}`");

            /*
             * Sweep the databases of runs that are no longer running.
             *
             * One database per run is what stops two suites wiping each other, but a
             * name keyed on a PID accumulates one husk per run forever. Dropping them
             * on the way OUT is not reliable -- a killed or crashed run never gets
             * there, which is exactly the run that leaves a mess. So each run tidies
             * up after the dead on the way in, and only for a pid that is provably
             * gone; a live sibling suite is left strictly alone.
             */
            foreach ($pdo->query("SHOW DATABASES LIKE 'viteseo\_platform\_%\_test'")->fetchAll(\PDO::FETCH_COLUMN) as $stale) {
                if ($stale === $database || ! preg_match('/^viteseo_platform_(\d+)_test$/', (string) $stale, $m)) {
                    continue;
                }

                // posix_kill with signal 0 asks "does this process exist" without touching it.
                if (function_exists('posix_kill') && posix_kill((int) $m[1], 0)) {
                    continue;
                }

                $pdo->exec("DROP DATABASE IF EXISTS `{$stale}`");
            }
        } catch (PDOException) {
            // Not reachable, or not permitted to create it — let the connection itself
            // report the real problem rather than masking it with a second one.
        }
    }

    /**
     * Authenticate as an HR officer and POST JSON. Seeds the roles table on first use —
     * the HR routes sit behind `role:hr_officer|super_admin`, so without it every request
     * would 403 and the assertion under test would never be reached.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function hrPost(string $uri, array $payload): TestResponse
    {
        return $this->actingAsHr()->postJson($uri, $payload);
    }

    /** The HR-officer user these tests act as (created + role-assigned once per test). */
    protected function actingAsHr(): static
    {
        Sanctum::actingAs($this->hrUser());

        return $this;
    }

    /**
     * An HR-officer user, without authenticating as them. For the cases that need to hand a
     * viewer to something directly (a role-gated Resource, say) rather than call an endpoint.
     */
    protected function hrUser(): User
    {
        if (Role::query()->where('name', 'hr_officer')->doesntExist()) {
            $this->seed(RolesAndPermissionsSeeder::class);
        }

        $user = User::factory()->create();
        $user->assignRole('hr_officer');

        return $user;
    }

    /**
     * Set attendance rules as the ones that have ALWAYS been in force.
     *
     * A payroll run prices at the rule version in force on the cycle's LAST DAY, so a rule set
     * today never reaches a fixture cycle in the past. That is the point rather than a wrinkle to
     * work around — it is what stops an edit now from silently repricing an old payslip. A test
     * that writes the policy row directly is not describing HR changing its mind mid-history, it
     * is describing a company that has always had this rule, so the rule belongs in the OPENING
     * version. Writing the row raw (`AttendancePolicy::query()->update()`) records no version at
     * all and only ever worked because `asOf()` used to fall back to the live row.
     *
     * @param  array<string, mixed>  $rules  any subset of AttendancePolicyService::DEFAULTS
     */
    protected function policyAlways(array $rules): AttendancePolicy
    {
        return app(AttendancePolicyService::class)->update(
            $rules + ['effective_from' => AttendancePolicyService::OPENING_DATE],
        );
    }
}
