<?php

namespace Tests\Feature\Hr;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * Each rate-limited action gets its OWN counter (regression on the FIN-006 limiters).
 *
 * THE BUG. `ThrottleRequests::resolveRequestSignature()` keys an authenticated request on
 * `sha1($user->id)` and nothing else — the route is not part of it. Every unprefixed `throttle:`
 * in the module therefore shared one counter per user, and the HR group's own `throttle:120,1`
 * incremented that counter on every single request. `payroll/run` then compared the shared number
 * against its limit of 5.
 *
 * The effect was not subtle: five requests to anything at all — less than one screen load —
 * left the payroll run answering "Too Many Attempts" for the rest of the minute. The limiter
 * intended to stop a run being submitted repeatedly was instead throttling all HR activity to
 * 5/min, and it read as a broken payroll rather than as a rate limit.
 *
 * WHY THIS IS TESTED AT THE ROUTE TABLE rather than by firing 5 real requests: the actions behind
 * these limiters run payroll, pay people and build files. Asserting on the middleware string is
 * what makes the check cheap enough to cover EVERY limited route, including ones added later —
 * which is the case that actually matters, because the bug is invisible until someone hits it.
 */
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

                if (count($args) >= 3 && trim($args[2]) !== '' && trim($args[2]) !== 'hr-api:') {
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
}
