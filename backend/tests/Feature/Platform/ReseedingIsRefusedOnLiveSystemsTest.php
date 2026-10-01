<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A RELEASE MUST NEVER RESET ACCESS. RolesAndPermissionsSeeder `syncPermissions` every built-in role
 * (undoing hand grants), DatabaseSeeder also resets every staff password to `password`, and
 * `platform:assign-roles` `syncRoles` people to a stale matrix. Outside local/testing each refuses
 * unless the operator names the exact database in an opt-in variable (App\Support\ReseedGuard).
 *
 * Runs on sqlite :memory: only (no HrTestCase): it switches the APP environment, never the database.
 */
class ReseedingIsRefusedOnLiveSystemsTest extends TestCase
{
    use RefreshDatabase;

    private const FLAGS = ['ALLOW_ROLE_RESEED', 'ALLOW_DEMO_SEED', 'RESEED_PROTECTED_DATABASES'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class); // allowed: APP_ENV=testing
        $this->clearFlags();
    }

    protected function tearDown(): void
    {
        $this->clearFlags();
        DB::prohibitDestructiveCommands(false); // static: never leak into the next test
        parent::tearDown();
    }

    private function clearFlags(): void
    {
        foreach (self::FLAGS as $flag) {
            unset($_ENV[$flag], $_SERVER[$flag]);
            putenv($flag);
        }
    }

    private function setFlag(string $flag, string $value): void
    {
        $_ENV[$flag] = $_SERVER[$flag] = $value;
        putenv($flag.'='.$value);
    }

    /**
     * Run a seeder the way `db:seed --force` would once its confirmation is answered: the artisan
     * prompt is a separate, weaker guard (`--force` skips it), so this exercises ReseedGuard itself.
     */
    private function runSeeder(string $class): void
    {
        $seeder = $this->app->make($class);
        $seeder->setContainer($this->app);
        $seeder->__invoke();
    }

    private function asProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue($this->app->environment('production'), 'precondition: running as production');
    }

    /** A grant made by hand on the live system that a reseed would silently undo. */
    private function handGrant(): void
    {
        Permission::findOrCreate('crm.leads.delete', 'web');
        Role::findByName('crm_bd', 'web')->givePermissionTo('crm.leads.delete');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function handGrantSurvives(): bool
    {
        return Role::findByName('crm_bd', 'web')->fresh()->hasPermissionTo('crm.leads.delete');
    }

    public function test_the_role_seeder_refuses_in_production_and_leaves_hand_grants_alone(): void
    {
        $this->handGrant();
        $this->asProduction();

        foreach ([RolesAndPermissionsSeeder::class, ProductionSeeder::class] as $seeder) {
            try {
                $this->runSeeder($seeder);
                $this->fail("{$seeder} ran in production");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('ALLOW_ROLE_RESEED', $e->getMessage());
            }
        }

        $this->assertTrue($this->handGrantSurvives(), 'a refused reseed must not have touched role permissions');
    }

    public function test_migrate_with_seed_cannot_slip_a_seeder_into_a_release(): void
    {
        $this->asProduction();
        $before = User::query()->pluck('password', 'id')->all();

        try {
            $this->artisan('migrate', ['--seed' => true, '--force' => true])->run();
            $this->fail('migrate --seed ran the seeder in production');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DatabaseSeeder refused', $e->getMessage());
        }

        $this->assertSame($before, User::query()->pluck('password', 'id')->all(), 'no password may be reset');
    }

    public function test_the_full_seeder_refuses_in_any_non_development_environment(): void
    {
        $staff = User::factory()->create(['email' => 'someone@viteseo.ph', 'password' => Hash::make('a-real-password')]);

        foreach (['production', 'staging', 'demo'] as $environment) {
            $this->app->detectEnvironment(fn () => $environment);
            try {
                $this->runSeeder(DatabaseSeeder::class);
                $this->fail("DatabaseSeeder ran in {$environment}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('ALLOW_DEMO_SEED', $e->getMessage());
            }
        }

        $this->assertTrue(Hash::check('a-real-password', $staff->fresh()->password), 'a staff password was reset');
    }

    public function test_an_opt_in_must_name_this_exact_database(): void
    {
        $this->handGrant();
        $this->asProduction();
        $database = (string) DB::connection()->getDatabaseName();

        // `true`, or another database's name, is not consent for this one.
        foreach (['true', '1', 'teamora', $database.'_other'] as $value) {
            $this->setFlag('ALLOW_ROLE_RESEED', $value);
            try {
                $this->runSeeder(RolesAndPermissionsSeeder::class);
                $this->fail("reseed authorized by ALLOW_ROLE_RESEED={$value}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('RolesAndPermissionsSeeder refused', $e->getMessage());
            }
        }
        $this->assertTrue($this->handGrantSurvives());

        // Naming it is the deliberate, one-command escape hatch — and it does what the warning says.
        $this->setFlag('ALLOW_ROLE_RESEED', $database);
        $this->runSeeder(RolesAndPermissionsSeeder::class);
        $this->assertFalse($this->handGrantSurvives(), 'an authorized reseed resets the role (which is why it is guarded)');
    }

    public function test_assign_roles_writes_nothing_in_production_but_still_previews(): void
    {
        $this->asProduction();
        $person = User::factory()->create(['email' => 'shandy.pacio@viteseo.ph']);
        $person->syncRoles(['pm_apm', 'hr_officer']);

        $this->artisan('platform:assign-roles')->assertFailed();
        $this->assertEqualsCanonicalizing(['pm_apm', 'hr_officer'], $person->fresh()->getRoleNames()->all());

        $this->artisan('platform:assign-roles', ['--dry-run' => true])->assertSuccessful();
        $this->assertEqualsCanonicalizing(['pm_apm', 'hr_officer'], $person->fresh()->getRoleNames()->all());
    }

    public function test_a_live_database_is_protected_even_when_the_checkout_claims_to_be_local(): void
    {
        // A clone whose .env says local/testing but whose DB_DATABASE is a real one (teamora by
        // default; here the test db, named protected through the extension variable).
        $this->assertTrue($this->app->environment('testing'));
        $this->handGrant();
        $this->setFlag('RESEED_PROTECTED_DATABASES', (string) DB::connection()->getDatabaseName());

        try {
            $this->runSeeder(RolesAndPermissionsSeeder::class);
            $this->fail('a protected database was reseeded because the environment said testing');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('RolesAndPermissionsSeeder refused', $e->getMessage());
        }
        $this->assertTrue($this->handGrantSurvives());
    }

    public function test_wiping_commands_are_refused_on_a_live_system(): void
    {
        $user = User::factory()->create();
        $roles = Role::query()->count();
        $this->assertGreaterThan(0, $roles);

        // Re-run the provider's boot as production, as a live host boots (its listeners register twice
        // inside this one test; harmless here).
        $this->asProduction();
        $this->app->getProvider(\App\Providers\AppServiceProvider::class)->boot();

        foreach (['db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback'] as $command) {
            $this->artisan($command, ['--force' => true])->assertFailed();
        }
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('roles'), 'a wiping command dropped tables');
        $this->assertSame($roles, Role::query()->count(), 'a wiping command ran');
        $this->assertNotNull($user->fresh(), 'a wiping command ran');
    }
}
