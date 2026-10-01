<?php

namespace Tests\Feature\Crm;

use App\Modules\Crm\Console\Commands\EnrichDiscoveredWebsitesCommand;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\EnablesDiscoveryRuntime;
use Tests\TestCase;

/**
 * THE TRIGGER THAT WAS MISSING.
 *
 * Both website pipelines were built, both worked, and neither was ever scheduled — the deployment
 * runbook says so outright. The measurable consequence: every Google-discovered lead in the database
 * sat at `website-unknown`, shown as "Not audited", because nothing had ever asked. What these tests
 * pin is the trigger and its safety, not the pipelines — those have their own suites and are
 * deliberately untouched.
 */
class WebsiteEnrichmentScheduleTest extends TestCase
{
    use EnablesDiscoveryRuntime;
    use RefreshDatabase;

    private function scheduledEvent(): ?Event
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains($event->command ?? '', 'crm:enrich-websites')) {
                return $event;
            }
        }

        return null;
    }

    public function test_the_lifecycle_is_actually_scheduled(): void
    {
        // Without this registration the pipelines are exactly as unreachable as they were before —
        // present, working, and never invoked.
        $this->assertNotNull($this->scheduledEvent(), 'crm:enrich-websites must be on the schedule');
    }

    public function test_it_runs_every_half_hour_and_cannot_overlap_itself(): void
    {
        $event = $this->scheduledEvent();

        // Half-hourly, not hourly: the hourly rate was set before there was a backlog, and against
        // 1,600+ discovered leads it needed about two days to reach the end of the queue — long
        // enough that "Not audited" was the board's resting state rather than a passing one.
        $this->assertSame('*/30 * * * *', $event->expression);
        // Discovery keeps adding leads, so a tick that runs long must not stack a second crawler on
        // the same queue.
        $this->assertTrue($event->withoutOverlapping, 'overlapping ticks would double the outbound traffic');
        // A BOUNDED lock. The default lives 24 hours, so a tick killed mid-run (a deploy, an OOM)
        // would silence enrichment for a day with nothing to show why.
        $this->assertSame(45, $event->expiresAt);
    }

    public function test_one_tick_stays_inside_its_own_timeout_at_the_measured_rate(): void
    {
        // THE LIMITS ARE A MEASUREMENT, NOT A GUESS. Measured on the live pool: an audit costs up to
        // 8.2s per lead and a search up to 12.7s. This pins the relationship the numbers were chosen
        // for — if someone raises a limit without re-measuring, the arithmetic here fails before a
        // production tick starts being cut off mid-crawl.
        $worstCaseSeconds = (config('discovery.enrichment.audit_limit') * 8.2)
            + (config('discovery.enrichment.search_limit') * 12.7);

        $this->assertLessThan(
            config('discovery.enrichment_timeout'),
            $worstCaseSeconds,
            'a full tick at the worst measured rate must finish inside the sub-command timeout',
        );
        // And inside the schedule interval, so ticks do not routinely collide with the overlap lock.
        $this->assertLessThan(30 * 60, $worstCaseSeconds);
    }

    public function test_it_invokes_the_existing_engine_commands_with_a_bounded_limit_and_commit(): void
    {
        Process::fake();

        $this->artisan(EnrichDiscoveredWebsitesCommand::class, ['--audit' => 7, '--search' => 4])
            ->assertSuccessful();

        $commands = [];
        Process::assertRan(function ($process) use (&$commands): bool {
            $commands[] = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return true;
        });
        $all = implode("\n", $commands);

        // THE EXISTING COMMANDS, not a reimplementation of them.
        $this->assertStringContainsString('-m scraper audit --limit 7 --commit', $all);
        $this->assertStringContainsString('-m scraper find-websites --limit 4 --commit', $all);
    }

    public function test_the_limit_is_always_present_so_one_tick_cannot_drain_the_whole_backlog(): void
    {
        Process::fake();
        config()->set('discovery.enrichment.audit_limit', 25);
        config()->set('discovery.enrichment.search_limit', 25);

        $this->artisan(EnrichDiscoveredWebsitesCommand::class)->assertSuccessful();

        Process::assertRan(fn ($process): bool => in_array('--limit', (array) $process->command, true));
    }

    public function test_a_step_can_be_switched_off_without_touching_the_other(): void
    {
        Process::fake();

        $this->artisan(EnrichDiscoveredWebsitesCommand::class, ['--audit' => 0, '--search' => 3])
            ->assertSuccessful();

        $commands = [];
        Process::assertRan(function ($process) use (&$commands): bool {
            $commands[] = implode(' ', (array) $process->command);

            return true;
        });
        $all = implode("\n", $commands);

        $this->assertStringNotContainsString('scraper audit', $all);
        $this->assertStringContainsString('scraper find-websites', $all);
    }

    public function test_a_failing_step_is_reported_rather_than_retried_in_place(): void
    {
        // The queues are ordered never-checked-first, so the next tick resumes where this one
        // stopped. Retrying here would only re-crawl the same sites sooner.
        Process::fake(['*' => Process::result(output: '', errorOutput: 'boom', exitCode: 3)]);

        $this->artisan(EnrichDiscoveredWebsitesCommand::class, ['--audit' => 1, '--search' => 0])
            ->assertFailed();
    }

    public function test_the_crm_token_never_reaches_the_command_line(): void
    {
        // The token is no longer configuration: each org's pass mints its own short-lived,
        // org-bound service token. It travels in the child's ENVIRONMENT only, and is revoked when
        // the pass ends.
        Process::fake();

        $this->artisan(EnrichDiscoveredWebsitesCommand::class, ['--audit' => 1, '--search' => 0])
            ->assertSuccessful();

        $seen = [];
        Process::assertRan(function ($process) use (&$seen): bool {
            $seen[] = [(string) ($process->environment['CRM_TOKEN'] ?? ''), implode(' ', (array) $process->command)];

            return true;
        });

        $this->assertNotEmpty($seen);
        foreach ($seen as [$token, $command]) {
            $this->assertNotSame('', $token, 'the engine was started without a credential');
            $this->assertStringNotContainsString($token, $command);
        }
        $this->assertSame(0, PersonalAccessToken::query()->where('name', 'nerby:enrichment')->count(), 'the pass token must be revoked');
    }

    public function test_a_second_tick_is_refused_while_one_is_running(): void
    {
        // THE GUARD HAS TO TRAVEL WITH THE COMMAND. `withoutOverlapping()` protects the schedule
        // ENTRY, and this stack cannot use Laravel's scheduler to reach this command: `schedule:run`
        // fires every due entry, and the outreach ones would mail drafts scheduled for 2026-07-19.
        // The trigger therefore invokes the command directly — and a direct invocation inherits no
        // protection from a schedule entry it never went through.
        Process::fake();

        $held = Cache::lock('crm:enrich-websites', 60);
        $this->assertTrue($held->get(), 'precondition: the lock is free');

        try {
            $this->artisan(EnrichDiscoveredWebsitesCommand::class)
                // SUCCESS, not failure: an ordinary overlap is not an error, and the queues are
                // ordered never-checked-first so the running tick already covers this one's work.
                ->assertSuccessful()
                ->expectsOutputToContain('already running');

            // And it really did nothing — no engine was started.
            Process::assertNothingRan();
        } finally {
            $held->release();
        }
    }

    public function test_the_lock_is_released_so_the_next_tick_can_run(): void
    {
        // A lock that outlived its tick would silence enrichment until it expired, which is the
        // failure mode the bounded expiry exists for — but the ordinary path must not rely on it.
        Process::fake();

        $this->artisan(EnrichDiscoveredWebsitesCommand::class)->assertSuccessful();

        $after = Cache::lock('crm:enrich-websites', 60);
        $this->assertTrue($after->get(), 'the lock must be free once a tick finishes');
        $after->release();
    }
}
