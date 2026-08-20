<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
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
}
