<?php

namespace Tests\Feature\Crm;

use App\Modules\Crm\Jobs\RunDiscoveryJob;
use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-task wall clock, and the arithmetic that makes it safe.
 *
 * A task timeout is not a free dial. The nationwide slice deadline is checked BETWEEN searches and
 * never inside one, so a task that starts one second before the deadline still runs to completion:
 * the worst-case job costs `nationwide.slice_seconds` PLUS one whole task timeout. Let that sum pass
 * RunDiscoveryJob's own timeout and the queue kills the job mid-task — losing the slice instead of
 * losing one task, which is strictly worse than the timeout it was meant to relax.
 *
 * These tests exist because that relationship lives in three separate files and is invisible from
 * any one of them.
 */
class DiscoveryTaskTimeoutTest extends TestCase
{
    use RefreshDatabase;

    /** Every configured timeout must leave the job able to outlive a task that started at the deadline. */
    public function test_no_configured_task_timeout_can_outlive_the_job_that_runs_it(): void
    {
        $slice = (int) config('discovery.nationwide.slice_seconds');
        $job = (new RunDiscoveryJob('00000000-0000-0000-0000-000000000000', 1))->timeout;

        $timeouts = array_merge(
            ['default' => (int) config('discovery.task_timeout')],
            (array) config('discovery.task_timeout_by_provider'),
        );

        foreach ($timeouts as $provider => $timeout) {
            $this->assertLessThanOrEqual(
                $job,
                $slice + (int) $timeout,
                "A '{$provider}' task starting at the slice deadline would outlive the job "
                ."({$slice}s slice + {$timeout}s task > {$job}s job), so the queue would kill the "
                .'job mid-task and lose the whole slice.',
            );
        }
    }

    /** The margin is deliberate, not incidental: 360 would be exactly zero and 420 would overrun. */
    public function test_the_ai_lane_keeps_headroom_rather_than_using_every_last_second(): void
    {
        $slice = (int) config('discovery.nationwide.slice_seconds');
        $job = (new RunDiscoveryJob('00000000-0000-0000-0000-000000000000', 1))->timeout;
        $openai = (int) config('discovery.task_timeout_by_provider.openai');

        $this->assertGreaterThan(
            0,
            $job - ($slice + $openai),
            'the AI lane must leave headroom for the checkpoint write that follows the last search',
        );
    }

    /** A provider nobody listed keeps the global ceiling — this can only lengthen what it names. */
    public function test_an_unlisted_provider_keeps_the_global_timeout(): void
    {
        $runner = $this->app->make(ScraperProcessRunner::class);
        $timeoutFor = function (string $provider) use ($runner): int {
            $method = new \ReflectionMethod($runner, 'timeoutFor');

            return $method->invoke($runner, $provider);
        };

        $default = (int) config('discovery.task_timeout');

        // The paid lane and the deterministic baseline are both unlisted, and must stay that way:
        // Google's worst recorded task is 47s, so a longer ceiling would buy it nothing and would
        // only lengthen how long a hung one holds the worker.
        $this->assertSame($default, $timeoutFor('google_places'));
        $this->assertSame($default, $timeoutFor('openstreetmap'));
        $this->assertSame($default, $timeoutFor('a_provider_added_next_year'));
    }

    /** The lane the measurements were about actually gets the longer ceiling. */
    public function test_the_ai_lane_gets_its_own_ceiling(): void
    {
        $runner = $this->app->make(ScraperProcessRunner::class);
        $method = new \ReflectionMethod($runner, 'timeoutFor');

        $this->assertSame(
            (int) config('discovery.task_timeout_by_provider.openai'),
            $method->invoke($runner, 'openai'),
        );
        $this->assertGreaterThan((int) config('discovery.task_timeout'), $method->invoke($runner, 'openai'));
    }
}
