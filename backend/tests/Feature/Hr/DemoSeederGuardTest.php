<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Database\Seeders\HolidayDemoSeeder;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\Holiday;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Demo seeders must not fabricate payroll outside local/testing. */
class DemoSeederGuardTest extends HrTestCase
{
    use RefreshDatabase;

    /** Every HR demo seeder, so a newly added one that forgets the guard fails this. */
    private function hrDemoSeeders(): array
    {
        return array_map(
            fn (string $f) => 'App\\Modules\\Hr\\Database\\Seeders\\' . basename($f, '.php'),
            glob(base_path('app/Modules/Hr/Database/Seeders/*DemoSeeder.php')),
        );
    }

    public function test_hr_demo_seeders_create_nothing_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue($this->app->environment('production'), 'precondition: running as production');

        // Invoked directly rather than through $this->seed(), which routes via the `db:seed`
        // command — and that command's own production guard would prompt for confirmation and
        // hang the test on a mocked console. __invoke() still resolves run()'s dependencies.
        foreach ($this->hrDemoSeeders() as $seeder) {
            $this->app->make($seeder)->setContainer($this->app)->__invoke();
        }

        $this->assertSame(0, Employee::withTrashed()->count(), 'demo employees were created in production');
        $this->assertSame(0, PayrollRun::count(), 'fabricated payroll runs were created in production');
        $this->assertSame(0, Payslip::count(), 'fabricated payslips were created in production');
    }

    /** The guard must not break local development, which is what these seeders are for. */
    public function test_hr_demo_seeders_still_populate_in_local(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        // HolidayDemoSeeder is used because it has no dependency on other seeders — the point
        // here is that the GUARD lets it through in local, not that the wider demo chain works.
        $this->app->make(HolidayDemoSeeder::class)->setContainer($this->app)->__invoke();

        $this->assertGreaterThan(0, Holiday::count(), 'demo data no longer seeds in local');
    }

    /** Named explicitly so a new seeder without the trait is an obvious failure, not a silent one. */
    public function test_every_hr_demo_seeder_uses_the_guard(): void
    {
        foreach ($this->hrDemoSeeders() as $seeder) {
            $this->assertContains(
                'App\\Modules\\Hr\\Database\\Seeders\\RefusesOutsideDemoEnvironments',
                class_uses_recursive($seeder),
                "{$seeder} can fabricate payroll data in production",
            );
        }
    }
}
