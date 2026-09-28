<?php

namespace Tests\Feature\Crm;

use App\Modules\Crm\Services\Discovery\ScraperProcessRunner;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * THE COMMAND LINE, as it is actually built — the seam between the CRM and the Python engine.
 *
 * WHY THIS IS WORTH A TEST OF ITS OWN. Nationwide correctness depends on a cell computed and clipped
 * inside the CRM arriving at the provider as the same four numbers, and that hand-off happens through
 * `argv`. Every other link in the chain is a function call the compiler can check; this one is a
 * string. A dropped flag, a transposed pair or a locale-dependent decimal separator would all produce
 * a run that searches real places, ingests real businesses, and covers the wrong ground — with nothing
 * anywhere in the system able to notice.
 *
 * `Process::fake()` means no subprocess is spawned: this asserts what WOULD be executed.
 */
class ScraperProcessRunnerBboxTest extends TestCase
{
    /** A cell as the planner clips them: south, west, north, east. */
    private const CELL = [10.25, 123.75, 10.5, 124.0];

    protected function setUp(): void
    {
        parent::setUp();
        Process::fake();
    }

    private function invoke(?array $bbox, ?string $country = null, ?string $crmToken = null): void
    {
        $this->app->make(ScraperProcessRunner::class)->run(
            'Cebu',
            'bakery',
            20,
            'google_places',
            storage_path('app/discovery/test.jsonl'),
            $bbox,
            $country,
            crmToken: $crmToken,
        );
    }

    /** The environment the child process WOULD be given. `Process::fake()` spawns nothing. */
    private function childEnvironment(): array
    {
        $env = [];
        Process::assertRan(function ($process) use (&$env): bool {
            $env = $process->environment;

            return true;
        });

        return $env;
    }

    /** The recorded command line, as one string — how a person would read it in a process list. */
    private function commandLine(): string
    {
        $command = '';
        Process::assertRan(function ($process) use (&$command): bool {
            $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return true;
        });

        return $command;
    }

    public function test_a_bounded_task_passes_the_box_in_south_west_north_east_order(): void
    {
        $this->invoke(self::CELL);

        // Latitude first, and the exact numbers — the engine parses this and refuses a transposed box,
        // so an order regression here would fail the run rather than search the ocean. Pinned anyway:
        // a failed nationwide run is a bad day, and this is the assertion that prevents it.
        $this->assertStringContainsString('--bbox 10.250000,123.750000,10.500000,124.000000', $this->commandLine());
    }

    public function test_an_unbounded_task_passes_no_box_at_all(): void
    {
        $this->invoke(null);

        // The scoped path must be byte-for-byte what it was: it names a place and lets the engine's
        // geocoder resolve it. An always-present flag would change every existing search.
        $this->assertStringNotContainsString('--bbox', $this->commandLine());
    }

    public function test_the_box_survives_a_locale_that_writes_decimals_with_commas(): void
    {
        // A comma decimal separator would turn one box into eight malformed arguments. `number_format`
        // is explicit about its separators precisely so the command line cannot depend on the host's
        // locale, and this is the test that keeps it that way.
        $previous = setlocale(LC_NUMERIC, '0');
        setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German');

        try {
            $this->invoke(self::CELL);
            $this->assertStringContainsString('--bbox 10.250000,123.750000,10.500000,124.000000', $this->commandLine());
        } finally {
            setlocale(LC_NUMERIC, $previous === false ? 'C' : $previous);
        }
    }

    public function test_the_engine_is_still_told_to_persist_and_which_provider_to_use(): void
    {
        $this->invoke(self::CELL);
        $command = $this->commandLine();

        // Adding the flag must not have displaced anything that was already load-bearing.
        $this->assertStringContainsString('--commit', $command);
        $this->assertStringContainsString('--provider google_places', $command);
        $this->assertStringContainsString('--limit 20', $command);
        $this->assertStringContainsString('--area Cebu', $command);
        $this->assertStringContainsString('--category bakery', $command);
    }

    /**
     * The boundary's own count is preferred over the residual — the number that makes
     * "nothing outside the Philippines is stored" a thing the product can SHOW rather than assert.
     */
    public function test_the_rejection_count_comes_from_the_ingest_when_it_reported_one(): void
    {
        $path = $this->metricsWith([
            'provider_execution_status' => 'ok',
            'discovered' => 20, 'imported' => 14, 'duplicates' => 0,
            'total' => 20, 'valid' => 14, 'rejected' => 6,
        ]);

        $result = $this->app->make(ScraperProcessRunner::class)
            ->run('Puerto Princesa', 'bakery', 20, 'google_places', $path, self::CELL);

        $this->assertSame(6, $result['rejected']);
    }

    public function test_an_engine_that_cannot_report_rejections_falls_back_to_the_residual(): void
    {
        // An older deployed engine: the CRM and the scraper release independently, and a missing key
        // must not silently zero the count.
        $path = $this->metricsWith([
            'provider_execution_status' => 'ok',
            'discovered' => 20, 'imported' => 14, 'duplicates' => 2,
            'total' => 20, 'valid' => 14,
        ]);

        $result = $this->app->make(ScraperProcessRunner::class)
            ->run('Puerto Princesa', 'bakery', 20, 'google_places', $path, self::CELL);

        $this->assertSame(4, $result['rejected']);
    }

    /**
     * A fake engine that APPENDS its telemetry line, the way the real one does.
     *
     * Appending during the call rather than writing the file up front is what makes this a real test
     * of the reader: the runner counts the file's lines before it starts and only trusts what appears
     * beyond that count, precisely so a run cannot read another run's record. A pre-written line
     * would be invisible to it — correctly.
     */
    private function metricsWith(array $record): string
    {
        $path = storage_path('app/discovery/runner-test-'.uniqid().'.jsonl');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, '');

        Process::fake(function () use ($path, $record) {
            file_put_contents($path, json_encode($record)."\n", FILE_APPEND);

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        return $path;
    }

    /**
     * THE COUNTRY FENCE TRAVELS ON THE COMMAND LINE, for scoped searches as much as nationwide ones.
     *
     * It is what confines the engine's geocoder, so an area outside the country cannot resolve and
     * therefore cannot become a provider request. A dropped flag here would silently restore the
     * behaviour that put twenty Californian businesses into a Philippine pool.
     */
    public function test_a_search_carries_the_country_it_is_restricted_to(): void
    {
        $this->invoke(null, 'PH');

        $this->assertStringContainsString('--country PH', $this->commandLine());
    }

    public function test_a_bounded_search_carries_both_the_box_and_the_country(): void
    {
        $this->invoke(self::CELL, 'PH');
        $command = $this->commandLine();

        $this->assertStringContainsString('--bbox 10.250000,123.750000,10.500000,124.000000', $command);
        $this->assertStringContainsString('--country PH', $command);
    }

    public function test_no_country_means_no_flag_so_an_older_caller_is_unchanged(): void
    {
        $this->invoke(null, null);

        $this->assertStringNotContainsString('--country', $this->commandLine());
    }

    /*
     * ── THE AI STRATEGY CHANNEL ────────────────────────────────────────────────────────────────
     *
     * The engine has carried a fully built AI strategist for some time, gated on DISCOVERY_AI_STRATEGY
     * in its own environment. The CRM never passed that variable, so every run the CRM started had the
     * channel closed no matter how the provider settings were configured — the capability existed and
     * was unreachable. These tests pin both halves of the fix: it opens when enabled, and it stays
     * shut, by absence, when not.
     */

    public function test_an_enabled_deployment_opens_the_ai_strategy_channel(): void
    {
        config()->set('discovery.ai_strategy', true);
        $this->invoke(self::CELL);

        $this->assertSame('1', $this->childEnvironment()['DISCOVERY_AI_STRATEGY'] ?? null);
    }

    public function test_a_disabled_deployment_passes_no_ai_variable_at_all(): void
    {
        config()->set('discovery.ai_strategy', false);
        $this->invoke(self::CELL);

        // ABSENT, not "0". The engine treats only an explicit truthy value as consent, so omitting
        // the variable is the fail-closed spelling: a future change to how the engine parses its
        // environment cannot turn a present-but-false value into permission.
        $this->assertArrayNotHasKey('DISCOVERY_AI_STRATEGY', $this->childEnvironment());
    }

    /**
     * FAIL-CLOSED BY DEFAULT — the property that keeps staging and production unchanged until someone
     * decides otherwise. Asserted against the real config file rather than a value set by this test,
     * because the default is the whole safety claim.
     */
    public function test_ai_strategy_is_off_unless_an_environment_turns_it_on(): void
    {
        $this->refreshApplication();
        Process::fake();

        $this->assertFalse((bool) config('discovery.ai_strategy'));
    }

    /**
     * AI OFF IS TODAY'S RUN, BYTE FOR BYTE. The slice must be invisible to every existing deployment,
     * so with the flag down the child's environment is exactly the set it has always been given —
     * asserted as an exact key set, which is what catches an accidental addition.
     */
    public function test_with_ai_off_the_child_environment_is_exactly_what_it_has_always_been(): void
    {
        config()->set('discovery.ai_strategy', false);
        config()->set('discovery.allow_remote_commit', false);
        $this->invoke(self::CELL);

        $this->assertSame(
            ['CRM_BASE_URL', 'CRM_TOKEN', 'DISCOVERY_METRICS_FILE'],
            array_keys($this->childEnvironment()),
        );
    }

    /**
     * Turning AI on changes HOW the engine may search, never WHAT the CRM asked it to search. The
     * command line — area, category, limit, provider, box, country, commit — is the factual half of
     * the run, and this slice must not have moved any of it.
     */
    public function test_enabling_ai_strategy_does_not_alter_the_command_line(): void
    {
        config()->set('discovery.ai_strategy', false);
        $this->invoke(self::CELL, 'PH');
        $withoutAi = $this->commandLine();

        Process::fake();
        config()->set('discovery.ai_strategy', true);
        $this->invoke(self::CELL, 'PH');

        $this->assertSame($withoutAi, $this->commandLine());
    }

    /** The Google Places provider still executes the search, flag or no flag. */
    public function test_the_factual_provider_is_unchanged_when_ai_strategy_is_on(): void
    {
        config()->set('discovery.ai_strategy', true);
        $this->invoke(self::CELL);

        $this->assertStringContainsString('--provider google_places', $this->commandLine());
    }

    public function test_the_crm_token_is_never_part_of_the_command_line(): void
    {
        // A REAL token has to be in play for this to assert anything. The removed
        // `discovery.crm_token` config key is read by nothing, so setting it — as this test used to —
        // left the runner with no credential at all and the assertion below could not fail.
        $this->invoke(self::CELL, crmToken: 'super-secret-token-value');

        // It travels in the child's ENVIRONMENT, not its arguments: a process list is readable by
        // anything on the host.
        $this->assertStringNotContainsString('super-secret-token-value', $this->commandLine());

        // And it must genuinely REACH the child that way — otherwise the assertion above would pass
        // just as well for a runner that silently dropped the credential.
        $this->assertSame('super-secret-token-value', $this->childEnvironment()['CRM_TOKEN'] ?? null);
    }
}
