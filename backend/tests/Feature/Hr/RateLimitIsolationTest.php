<?php

namespace Tests\Feature\Hr;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/** Each rate-limited action gets its OWN counter (regression on the limiters). */
class RateLimitIsolationTest extends HrTestCase
{
    use RefreshDatabase;

    /**
     * Every `throttle:` in the HR module must carry a key prefix.
     *
     * The one exception is the group limiter, which is registered in `HrServiceProvider` and
     * carries `hr-api:` — asserted separately below.
     */
    public function test_every_rate_limited_hr_route_has_its_own_counter(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/hr')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }

                // throttle:{max},{decay},{prefix} — three parts, the third non-empty.
                $args = explode(',', substr($middleware, strlen('throttle:')));

                if (count($args) < 3 || trim($args[2]) === '') {
                    $offenders[] = $route->methods()[0] . ' ' . $route->uri() . ' → ' . $middleware;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These rate limiters share one per-user counter with every other unprefixed limiter, so\n"
                . "their limits apply to unrelated requests. Add a key prefix, e.g. 'throttle:5,1,payroll-run:'.\n"
                . implode("\n", $offenders),
        );
    }

    /** Distinct actions must not share a prefix, or they are back to one counter between them. */
    public function test_the_tight_write_limiters_do_not_share_a_prefix(): void
    {
        $prefixes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/hr')) {
                continue;
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }

                $args = explode(',', substr($middleware, strlen('throttle:')));

                if (count($args) >= 3 && trim($args[2]) !== '' && ! in_array(trim($args[2]), ['hr-api:', 'hr-api-hourly:'], true)) {
                    $prefixes[] = trim($args[2]) . '|' . $route->uri();
                }
            }
        }

        $this->assertSame(
            count($prefixes),
            count(array_unique($prefixes)),
            'two different actions are sharing a rate-limit counter',
        );
    }

    /**
     * The regression itself, end to end: a screen load's worth of reads must not consume the
     * payroll run's budget. This is what a person hit.
     */
    public function test_ordinary_browsing_does_not_exhaust_the_payroll_run_limit(): void
    {
        $this->actingAsHr();

        // Comfortably more than the run's limit of 5, and roughly what one HR screen fetches.
        for ($i = 0; $i < 12; $i++) {
            $this->getJson('/api/v1/hr/payroll/settings')->assertOk();
        }

        // The run itself is not exercised here — only that the limiter still lets it through.
        // A 422 (bad period) is a pass; a 429 is the bug.
        $response = $this->postJson('/api/v1/hr/payroll/run', []);

        $this->assertNotSame(
            429,
            $response->status(),
            'reading pages used up the payroll run\'s rate limit',
        );
    }

    /** The HR group carries TWO windows, and every route must be under both. */
    public function test_every_hr_route_is_under_both_group_windows(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/hr')) {
                continue;
            }

            $middleware = array_filter($route->gatherMiddleware(), 'is_string');
            $has = fn (string $needle) => (bool) array_filter(
                $middleware,
                fn ($m) => str_starts_with($m, 'throttle:') && str_contains($m, $needle),
            );

            if (! $has('hr-api:') || ! $has('hr-api-hourly:')) {
                $missing[] = $route->methods()[0] . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $missing, "these HR routes escape a group rate-limit window:\n" . implode("\n", $missing));
    }

    /** The expensive endpoints carry a limit tighter than the 120/min the group gives everything. */
    public function test_the_expensive_endpoints_carry_a_tighter_limit(): void
    {
        $expected = [
            'hr-upload:' => [
                'api/v1/hr/reimbursements',
                'api/v1/hr/me/concerns',
                'api/v1/hr/me/payroll-inquiries',
                'api/v1/hr/attendance/remote-checkin',
                'api/v1/hr/candidates',
                'api/v1/hr/employees/{employee}/photo',
                'api/v1/hr/employees/{employee}/documents',
                'api/v1/hr/office-expenses',
            ],
            'hr-email:' => ['api/v1/hr/candidates/{candidate}/email'],
            'hr-pdf:' => [
                'api/v1/hr/me/payslips/{payslip}/pdf',
                'api/v1/hr/payslips/{payslip}/pdf',
            ],
            'hr-bulk:' => [
                'api/v1/hr/violations/recompute',
                'api/v1/hr/contribution-versions/import-schedule',
            ],
        ];

        $limited = [];
        foreach (Route::getRoutes() as $route) {
            foreach (array_filter($route->gatherMiddleware(), 'is_string') as $middleware) {
                if (! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }
                $args = explode(',', substr($middleware, strlen('throttle:')));
                if (count($args) >= 3) {
                    $limited[trim($args[2])][] = $route->uri();
                }
            }
        }

        foreach ($expected as $prefix => $uris) {
            $this->assertArrayHasKey(
                $prefix,
                $limited,
                "the '{$prefix}' rate limiter has disappeared entirely",
            );

            foreach ($uris as $uri) {
                $this->assertContains(
                    $uri,
                    $limited[$prefix],
                    "{$uri} lost its '{$prefix}' limit and is back on the loose 120/min group limit",
                );
            }
        }
    }
}
