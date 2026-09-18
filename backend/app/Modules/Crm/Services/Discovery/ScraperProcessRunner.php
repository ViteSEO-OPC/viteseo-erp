<?php

namespace App\Modules\Crm\Services\Discovery;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Invokes the canonical Python discovery engine as a subprocess, once per (area, niche) task.
 *
 * THE ENGINE IS NOT REIMPLEMENTED HERE. Provider selection, AI strategy, Google Places paging,
 * Overpass querying, normalization and the CRM hand-off all live in `scraper/` and stay there. This
 * class only starts the process, hands it the one credential it needs, and reads back what it did.
 *
 * WHY ONE PROCESS PER TASK rather than one `discover-all` for the whole run:
 *
 *   1. `discover-all` is the CURATED path. It reads the version-controlled 9-city targets file and
 *      advances a rotation cursor. Stage 2 must not touch either, so it cannot be the mechanism.
 *   2. The engine emits no structured progress stream — no `--json`, no `--verbose`, no `logging`,
 *      just `print()` across stdout and stderr. Invoking per task makes progress a property of THIS
 *      orchestrator instead of a regex over someone else's log format.
 *   3. Each invocation carries its own `--limit`, so the engine's "divide the limit across cells,
 *      never multiply it" rule is respected rather than worked around.
 *
 * CREDENTIALS. `CRM_TOKEN` is read from config at execution time inside the worker and passed as an
 * environment variable to the child process. It is never part of a queue payload, never logged, and
 * never included in the stderr excerpt this class returns. The Google Places key is not handled here
 * at all — the engine asks this same CRM for places over HTTP, and the key stays server-side in
 * `crm_nerby_provider_settings`.
 */
class ScraperProcessRunner
{
    /**
     * Run one discovery task to completion.
     *
     * @param  string  $provider  canonical provider name, e.g. `google_places` or `openstreetmap`
     * @param  string  $metricsPath  where the engine appends its JSONL telemetry for this run
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $bbox  south, west, north, east
     * @param  string|null  $country  ISO 3166-1 alpha-2 the engine must confine its geocoder to
     * @param  bool  $aiStrategy  this RUN asked for AI search planning; see {@see environment()}
     * @return array{
     *     exitCode: int, executionStatus: ?string, errorReason: ?string,
     *     discovered: int, inserted: int, duplicates: int, rejected: int, stderrTail: string,
     *     strategyApplied: ?bool, strategyStatus: ?string
     * }
     */
    public function run(
        string $area,
        string $category,
        int $limit,
        string $provider,
        string $metricsPath,
        ?array $bbox = null,
        ?string $country = null,
        bool $aiStrategy = false,
    ): array {
        $before = $this->countLines($metricsPath);

        try {
            $result = Process::path((string) config('discovery.path', '/scraper'))
                ->env($this->environment($metricsPath, $aiStrategy))
                ->timeout((int) config('discovery.task_timeout', 180))
                ->run([
                    (string) config('discovery.python', '/opt/scraper-venv/bin/python'),
                    '-m', 'scraper', 'discover',
                    '--area', $area,
                    '--category', $category,
                    '--limit', (string) $limit,
                    '--provider', $provider,
                    // THE BOX TRAVELS ONLY WHEN THERE IS ONE. A scoped run resolves its own area
                    // through the engine's geocoder exactly as it always has; a nationwide cell has
                    // already been computed from the national polygon and clipped to it, so sending
                    // the box is what makes the search cover the cell that was PLANNED rather than
                    // whatever shape Nominatim returns for a nearby place name. Adding the flag
                    // unconditionally would change the scoped path, so it is appended, not inlined.
                    ...($bbox !== null ? ['--bbox', $this->formatBbox($bbox)] : []),
                    // THE COUNTRY FENCE. The engine confines its geocoder to this country, so an
                    // area outside it cannot resolve and therefore cannot become a provider request:
                    // the run reports an unresolved area having spent nothing. That is upstream of
                    // the ingest's national-boundary check, which is the last line rather than the
                    // first — discarding foreign results still means having paid to find them.
                    ...($country !== null && $country !== '' ? ['--country', $country] : []),
                    // Persist. Without this the engine performs a dry run and nothing reaches NERBY.
                    // The engine itself refuses `--commit` against a non-loopback CRM unless an
                    // operator has explicitly opted in — that guard is left switched on.
                    '--commit',
                ]);

            $exitCode = $result->exitCode() ?? 1;
            $stderr = (string) $result->errorOutput();
        } catch (Throwable $e) {
            // A timeout or a failure to even spawn. The class name only: a message could quote the
            // command line, and the command line's environment carried the CRM token.
            return $this->outcome(1, 'process_failed', $e::class, $metricsPath, $before, '');
        }

        $record = $this->latestRecord($metricsPath, $before);

        return [
            'exitCode' => $exitCode,
            'executionStatus' => $this->stringOrNull($record['provider_execution_status'] ?? null),
            'errorReason' => $this->stringOrNull($record['error_reason'] ?? null),
            'discovered' => (int) ($record['discovered'] ?? 0),
            // What actually became CRM prospects, as the existing ingest reported it back.
            'inserted' => (int) ($record['imported'] ?? 0),
            'duplicates' => (int) ($record['duplicates'] ?? 0),
            // THE CRM'S OWN REJECTION COUNT WHEN IT REPORTED ONE, and only otherwise the residual.
            //
            // `total - valid - duplicates` is an inference, and it conflates two different findings:
            // a business in another country that the national boundary refused, and a row that failed
            // ordinary validation. The ingest counts the boundary rejections exactly, so when that
            // number has travelled back through the engine's telemetry it is the one to trust. The
            // residual stays as a fallback for an engine deployed before the field existed — the CRM
            // and the scraper release independently, and a missing key must not zero the count.
            'rejected' => isset($record['rejected'])
                ? max(0, (int) $record['rejected'])
                : max(0, (int) ($record['total'] ?? 0)
                    - (int) ($record['valid'] ?? 0)
                    - (int) ($record['duplicates'] ?? 0)),
            'stderrTail' => $this->tail($stderr),
            // WHAT AI PLANNING DID FOR THIS SEARCH, as the engine reported it. NULL when the engine
            // said nothing — a provider that never consults AI, or an engine older than the field —
            // which is read as "not applied", never guessed as applied.
            'strategyApplied' => isset($record['strategy_applied']) ? (bool) $record['strategy_applied'] : null,
            'strategyStatus' => $this->stringOrNull($record['strategy_status'] ?? null),
        ];
    }

    /**
     * Run the existing Website Intelligence AUDIT over a bounded slice of its queue.
     *
     * THE SAME EXECUTION PATH AS DISCOVERY, deliberately: same interpreter, same working directory,
     * same environment, same timeout, same credential handling. The engine already owns which leads
     * to audit (`GET /website-enrichment/queue`, never-checked first) and what to do with them; this
     * only starts it. Nothing about the audit is reimplemented or duplicated here.
     *
     * `--commit` is what makes the run write its findings back. Without it the engine crawls and
     * reports and the CRM learns nothing, which is exactly the state that left every discovered lead
     * at "Not audited" — the pipeline existed and was simply never asked to run.
     *
     * @return array{exitCode: int, stderrTail: string}
     */
    public function audit(int $limit): array
    {
        return $this->runCommand(['audit', '--limit', (string) $limit, '--commit']);
    }

    /**
     * Run the existing website EVIDENCE search over a bounded slice of its queue.
     *
     * Its queue is the complement of the audit's: leads with NO website URL
     * (`GET /website-evidence/queue`). Together the two cover every discovered lead — one checks the
     * URL a provider supplied, the other looks for one that was never supplied.
     *
     * @return array{exitCode: int, stderrTail: string}
     */
    public function findWebsites(int $limit): array
    {
        return $this->runCommand(['find-websites', '--limit', (string) $limit, '--commit']);
    }

    /**
     * Start the engine with a sub-command, and report only how it ended.
     *
     * Shares {@see environment()} with the discovery path so there is ONE definition of where the
     * CRM is, how to authenticate to it, and which interpreter runs — the second execution path this
     * codebase must not grow. These sub-commands report their own outcome through the CRM's own
     * endpoints, so nothing is parsed out of their output; the stderr tail is a diagnostic only, and
     * is bounded and credential-free exactly as it is for discovery.
     *
     * @param  list<string>  $arguments
     * @return array{exitCode: int, stderrTail: string}
     */
    private function runCommand(array $arguments): array
    {
        try {
            $result = Process::path((string) config('discovery.path', '/scraper'))
                ->env($this->environment($this->metricsPath()))
                ->timeout((int) config('discovery.enrichment_timeout', 900))
                ->run([
                    (string) config('discovery.python', '/opt/scraper-venv/bin/python'),
                    '-m', 'scraper',
                    ...$arguments,
                ]);

            return [
                'exitCode' => $result->exitCode() ?? 1,
                'stderrTail' => $this->tail((string) $result->errorOutput()),
            ];
        } catch (Throwable $e) {
            // The class name only: a message could quote the command line, whose environment carried
            // the CRM token.
            return ['exitCode' => 1, 'stderrTail' => $e::class];
        }
    }

    /**
     * Where the enrichment commands append their telemetry.
     *
     * Deliberately NOT one file per discovery run: these are scheduled, continuous, and have no run
     * id to name a file after. `/scraper` is mounted read-only, so it lives under storage like the
     * discovery metrics do.
     */
    private function metricsPath(): string
    {
        $dir = storage_path('app/discovery');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir.'/enrichment.jsonl';
    }

    /**
     * `south,west,north,east`, latitude first — the order the engine parses and every provider
     * adapter already uses. Six decimals is roughly a tenth of a metre: far finer than a search
     * cell, and short enough that the command line stays readable in a process list.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox
     */
    private function formatBbox(array $bbox): string
    {
        return implode(',', array_map(fn (float $value): string => number_format($value, 6, '.', ''), $bbox));
    }

    /**
     * The child's environment.
     *
     * Deliberately minimal: where the CRM is, how to authenticate to it, where to write telemetry,
     * and which optional capabilities this deployment has switched on. No provider key, no area
     * list, no category list.
     *
     * @return array<string, string>
     */
    private function environment(string $metricsPath, bool $aiStrategy = false): array
    {
        $env = [
            'CRM_BASE_URL' => (string) config('discovery.crm_base_url'),
            'CRM_TOKEN' => (string) config('discovery.crm_token'),
            // `/scraper` is mounted READ-ONLY, so telemetry cannot live beside the engine. Each run
            // gets its own file under storage, which also keeps a Stage 2 run from ever appending to
            // the curated path's shared `.discovery-metrics.jsonl`.
            'DISCOVERY_METRICS_FILE' => $metricsPath,
        ];

        if (config('discovery.allow_remote_commit')) {
            $env['CRM_ALLOW_REMOTE_COMMIT'] = '1';
        }

        // THE AI STRATEGY CHANNEL, OPENED ONLY ON PURPOSE.
        //
        // The engine reads DISCOVERY_AI_STRATEGY and treats anything but an explicit truthy value as
        // off, so the variable is SET ONLY WHEN ENABLED rather than always set to "0". Both spellings
        // behave identically today; omitting it keeps the child's environment a statement of what is
        // switched on, and means a future change to the engine's parsing cannot accidentally read a
        // present-but-false variable as consent.
        //
        // Passing this does not make the model a source of businesses. It only lets the run ask HOW
        // to search; the factual provider still supplies every record. See config/discovery.php.
        //
        // OR A RUN THAT ASKED FOR IT. The deployment-wide flag keeps its exact meaning; a run whose
        // user explicitly chose AI Discovery opens the same channel for its own searches only. The
        // enrichment commands never pass it, so they are unaffected either way.
        if (config('discovery.ai_strategy') || $aiStrategy) {
            $env['DISCOVERY_AI_STRATEGY'] = '1';
        }

        return $env;
    }

    /**
     * The telemetry record this invocation appended, if it appended one.
     *
     * Read by position rather than by matching on area/category: the engine writes exactly one record
     * per `run_discovery`, including on its early-return paths, so anything beyond the line count we
     * started with belongs to this task.
     *
     * @return array<string, mixed>
     */
    private function latestRecord(string $path, int $before): array
    {
        if (! is_file($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) <= $before) {
            return [];
        }

        $decoded = json_decode((string) end($lines), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function countLines(string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }

        return count(@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    /**
     * @return array{
     *     exitCode: int, executionStatus: ?string, errorReason: ?string,
     *     discovered: int, inserted: int, duplicates: int, rejected: int, stderrTail: string,
     *     strategyApplied: ?bool, strategyStatus: ?string
     * }
     */
    private function outcome(
        int $exitCode,
        ?string $status,
        ?string $reason,
        string $metricsPath,
        int $before,
        string $stderr,
    ): array {
        return [
            'exitCode' => $exitCode,
            'executionStatus' => $status,
            'errorReason' => $reason,
            'discovered' => 0,
            'inserted' => 0,
            'duplicates' => 0,
            'rejected' => 0,
            'stderrTail' => $this->tail($stderr),
            'strategyApplied' => null,
            'strategyStatus' => null,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 200) : null;
    }

    /** The last few lines of stderr, bounded — a diagnostic, never a transcript. */
    private function tail(string $stderr): string
    {
        $stderr = trim($stderr);
        if ($stderr === '') {
            return '';
        }

        $lines = preg_split('/\R/', $stderr) ?: [];

        return mb_substr(implode("\n", array_slice($lines, -5)), 0, 1000);
    }
}
