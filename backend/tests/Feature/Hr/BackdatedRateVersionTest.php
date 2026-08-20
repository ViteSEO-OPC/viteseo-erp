<?php

namespace Tests\Feature\Hr;

use App\Models\User;
use App\Modules\Hr\Database\Seeders\ContributionSettingSeeder;
use App\Modules\Hr\Models\ContributionVersion;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Services\ContributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

/** A backdated rate version could silently reprice a completed payroll run. */
class BackdatedRateVersionTest extends HrTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ContributionSettingSeeder::class);
    }

    private function completedRun(string $start, string $end, ?string $lockedAt = null): PayrollRun
    {
        return PayrollRun::create([
            'cycle_start' => $start, 'cycle_end' => $end, 'frequency' => 'semi_monthly',
            'type' => 'regular', 'status' => 'completed', 'run_at' => now(),
            'locked_at' => $lockedAt,
        ]);
    }

    private function save(string $effectiveFrom, bool $acknowledge = false): ContributionVersion
    {
        return app(ContributionService::class)->saveVersion([
            'type' => 'sss',
            'effective_from' => $effectiveFrom,
            'basis' => 'percentage',
            'employee_rate' => 0.05,
            'employer_rate' => 0.10,
            'acknowledge_repricing' => $acknowledge,
        ], User::factory()->create());
    }

    /** A forward-dated version is ordinary business and must not be obstructed. */
    public function test_a_future_dated_version_saves_without_confirmation(): void
    {
        $this->completedRun('2026-03-01', '2026-03-15');

        $version = $this->save('2026-06-01');

        $this->assertSame('2026-06-01', $version->effective_from->format('Y-m-d'));
    }

    public function test_a_version_backdated_over_a_completed_run_is_refused(): void
    {
        $this->completedRun('2026-03-01', '2026-03-15');

        try {
            $this->save('2026-03-01');
            $this->fail('a backdated rate version was accepted with no warning');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'Mar 1–Mar 15, 2026',
                implode(' ', $e->validator->errors()->all()),
                'the refusal must name the run it would reprice',
            );
        }
    }

    /** Being told is the point — once told, HR can proceed. */
    public function test_an_acknowledged_backdated_version_saves(): void
    {
        $this->completedRun('2026-03-01', '2026-03-15');

        $version = $this->save('2026-03-01', acknowledge: true);

        $this->assertSame('2026-03-01', $version->effective_from->format('Y-m-d'));
    }

    /** A locked run cannot be repriced at all, so it is not a reason to stop. */
    public function test_a_locked_run_does_not_trigger_the_warning(): void
    {
        $this->completedRun('2026-03-01', '2026-03-15', lockedAt: now()->toDateTimeString());

        $version = $this->save('2026-03-01');

        $this->assertSame('2026-03-01', $version->effective_from->format('Y-m-d'));
    }

    /** 13th-month runs carry no statutory contributions, so they are not affected either. */
    public function test_a_thirteenth_month_run_does_not_trigger_the_warning(): void
    {
        PayrollRun::create([
            'cycle_start' => '2026-12-01', 'cycle_end' => '2026-12-31',
            'frequency' => 'thirteenth_month', 'type' => 'thirteenth_month',
            'status' => 'completed', 'run_at' => now(),
        ]);

        $version = $this->save('2026-12-01');

        $this->assertSame('2026-12-01', $version->effective_from->format('Y-m-d'));
    }
}
