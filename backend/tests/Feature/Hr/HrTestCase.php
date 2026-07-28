<?php

namespace Tests\Feature\Hr;

use Illuminate\Contracts\Console\Kernel;
use RuntimeException;
use Tests\TestCase;

/**
 * Base class for HR feature tests — runs them against a real MySQL database instead of
 * the shared phpunit.xml sqlite connection.
 *
 * WHY (2026-07-28 audit, Slice 0). The suite-wide connection is `sqlite::memory:`, which
 * does NOT store dates the way production does. `work_date` is a `date` column with a
 * `'date'` cast, and Laravel serialises that as 'Y-m-d H:i:s'. MySQL's DATE column
 * truncates it on insert; sqlite keeps the string verbatim. So
 * `whereBetween('work_date', ['2026-07-20', '2026-07-21'])` silently drops the last day
 * of every range under sqlite — which broke unpaidDaysDetailFor() (a phantom unpaid day)
 * and ViolationService::sync() (updateOrCreate misses the row, then hits the unique index).
 * Payroll is date-range arithmetic end to end, so the test DB has to match production.
 *
 * SCOPE. This overrides the connection for HR tests ONLY. `backend/phpunit.xml` is shared
 * with the CRM and Projects pods and is deliberately left untouched — their suites keep
 * using sqlite. The connection is defined here at runtime rather than in `config/database.php`
 * for the same reason: no shared file changes.
 *
 * SAFETY. The target database name must end in `_test` or the run aborts. RefreshDatabase
 * drops every table, so this guard is the thing standing between a test run and the dev
 * database (which the frozen viteseo-platform-hris clone also shares).
 *
 * SETUP (once per machine):
 *   sudo mysql -e "CREATE DATABASE IF NOT EXISTS viteseo_platform_test
 *     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 *     GRANT ALL PRIVILEGES ON viteseo_platform_test.* TO 'viteseo'@'localhost';
 *     FLUSH PRIVILEGES;"
 *
 * Override the database with HR_TEST_DB_DATABASE if you want a different name.
 */
abstract class HrTestCase extends TestCase
{
    /** The connection these tests run on — registered in createApplication(). */
    protected const CONNECTION = 'hr_testing';

    public function createApplication()
    {
        $app = require __DIR__ . '/../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $database = env('HR_TEST_DB_DATABASE', 'viteseo_platform_test');

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

        return $app;
    }
}
