<?php

namespace Tests\Feature\Crm;

use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * AI-assisted capacity mode, at the process boundary: the engine flags travel ONLY through
 * `runWithEngineFlags()`, appended to an otherwise identical command line; `run()` is untouched.
 * `Process::fake()` spawns nothing — these assert what WOULD be executed.
 */
class ScraperProcessRunnerEngineFlagsTest extends TestCase
{
    private const CELL = [10.25, 123.75, 10.5, 124.0];

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function path(): string
    {
        $path = storage_path('app/discovery/engine-flags-test-'.uniqid().'.jsonl');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, '');

        return $this->paths[] = $path;
    }

    /** @return list<string> every command line that WOULD have been executed */
    private function commands(): array
    {
        $commands = [];
        Process::assertRan(function ($process) use (&$commands): bool {
            $commands[] = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return true;
        });

        return $commands;
    }

    public function test_run_keeps_its_exact_command_line_and_the_flags_are_only_appended(): void
    {
        Process::fake();
        $runner = $this->app->make(ScraperProcessRunner::class);
        $path = $this->path();

        $runner->run('Davao', 'bakery', 20, 'openai', $path, self::CELL, 'PH');
        $runner->runWithEngineFlags(['--ai-total-limit', '100', '--skip-ai-lanes'], 'Davao', 'bakery', 20, 'openai', $path, self::CELL, 'PH');

        [$plain, $flagged] = $this->commands();
        $this->assertStringNotContainsString('--ai-total-limit', $plain);
        $this->assertStringNotContainsString('--skip-ai-lanes', $plain);
        $this->assertSame($plain.' --ai-total-limit 100 --skip-ai-lanes', $flagged, 'the same command, with the flags at the end');
        $this->assertStringContainsString('--limit 20', $flagged, 'the per-cell limit is unchanged');
    }

    public function test_the_sweeps_own_count_is_read_back_when_the_engine_reports_it(): void
    {
        $path = $this->path();
        Process::fake(function () use ($path) {
            file_put_contents($path, json_encode(['discovered' => 64, 'baseline_discovered' => 20, 'duplicates' => 60])."\n", FILE_APPEND);

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        $result = $this->app->make(ScraperProcessRunner::class)
            ->runWithEngineFlags(['--ai-total-limit', '100'], 'Davao', 'bakery', 20, 'openai', $path, self::CELL);

        $this->assertSame(64, $result['discovered']);
        $this->assertSame(20, $result['baselineDiscovered']);
    }

    public function test_the_sweeps_count_is_null_when_the_engine_does_not_report_one(): void
    {
        $path = $this->path();
        Process::fake(function () use ($path) {
            file_put_contents($path, json_encode(['discovered' => 7])."\n", FILE_APPEND);

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        $result = $this->app->make(ScraperProcessRunner::class)->run('Davao', 'bakery', 20, 'openstreetmap', $path, self::CELL);

        $this->assertNull($result['baselineDiscovered']);
    }

    public function test_only_a_completed_ai_attempt_for_the_same_area_and_niche_counts(): void
    {
        $runner = $this->app->make(ScraperProcessRunner::class);
        $path = $this->path();
        $this->assertFalse($runner->aiLanesRanFor($path, 'Davao', 'bakery'), 'nothing has run yet');
        $this->assertFalse($runner->aiLanesRanFor($path.'.missing', 'Davao', 'bakery'));

        foreach ([
            ['area' => 'Davao', 'category' => 'bakery', 'ai_execution_status' => 'provider_errored'],
            ['area' => 'Davao', 'category' => 'bakery', 'ai_execution_status' => 'baseline_satisfied_limit'],
            ['area' => 'Davao', 'category' => 'bakery', 'ai_execution_status' => 'ai_lanes_already_ran_for_area'],
            ['area' => 'Davao', 'category' => 'cafe', 'ai_execution_status' => 'ok'],
            ['area' => 'Cebu', 'category' => 'bakery', 'ai_execution_status' => 'ok'],
        ] as $record) {
            file_put_contents($path, json_encode($record)."\n", FILE_APPEND);
        }
        file_put_contents($path, "not json\n", FILE_APPEND);

        $this->assertFalse($runner->aiLanesRanFor($path, 'Davao', 'bakery'), 'failed / skipped attempts are retried');
        $this->assertTrue($runner->aiLanesRanFor($path, 'Davao', 'cafe'));
        $this->assertTrue($runner->aiLanesRanFor($path, 'Cebu', 'bakery'));

        file_put_contents($path, json_encode(['area' => 'Davao', 'category' => 'bakery', 'ai_execution_status' => 'ok'])."\n", FILE_APPEND);
        $this->assertTrue($runner->aiLanesRanFor($path, 'Davao', 'bakery'));
    }
}
