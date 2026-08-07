<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Services\AuditService;
use App\Modules\Hr\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * FIN-040 and FIN-041 — the audit trail lied about itself in two different ways.
 *
 * FIN-040: five verbs were written and absent from `AuditService::ACTIONS` — `ran`, `requested`,
 * `authorized`, `cancelled`, `rejected`. That list is what the report's action filter offers, so
 * those entries were invisible to anyone filtering by action. They are also the most consequential
 * entries in the system: `ran` is a payroll run, `authorized` is an employee consenting to a wage
 * deduction under Art. 113. A filter that silently omits those is worse than no filter, because it
 * looks authoritative.
 *
 * FIN-041: the report took 300 rows and said nothing about it. An export presented as complete was
 * not — with 500 entries in range, 200 were absent and the file looked finished. For the report whose
 * whole purpose is "who did what", silent truncation is the failure that matters, because the missing
 * rows are undetectable from the output.
 *
 * THE FIRST TEST IS THE ENFORCEMENT. It scans the module for every `->log('verb'` and asserts the
 * verb is listed — so the next one added cannot go missing the same way. The runtime guard in
 * `AuditService` catches only what a scan cannot see, a verb built at runtime.
 */
class AuditVocabularyAndPagingTest extends HrTestCase
{
    use RefreshDatabase;

    // --- FIN-040: the vocabulary ------------------------------------------------------------

    public function test_every_verb_the_module_writes_is_in_the_allowed_list(): void
    {
        $written = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app/Modules/Hr')),
        ) as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            // php_strip_whitespace() removes comments, which matters: this file's own docblock
            // describes the pattern it looks for, and a naive read of the raw source finds the word
            // inside the prose. A scan that can be fooled by documentation is not a scan.
            preg_match_all("/->log\(\s*'([a-z_]+)'/", php_strip_whitespace($file->getPathname()), $m);

            foreach ($m[1] as $verb) {
                $written[$verb] = ($written[$verb] ?? []) + [basename($file->getPathname()) => true];
            }
        }

        $this->assertNotEmpty($written, 'the scan found no audit calls at all — the pattern is wrong');

        $unlisted = array_diff(array_keys($written), AuditService::ACTIONS);

        $this->assertSame(
            [],
            array_values($unlisted),
            'these verbs are written but not in AuditService::ACTIONS, so the audit report cannot '
                . 'filter for them: ' . implode(', ', $unlisted),
        );
    }

    /** The five that were missing, named explicitly so a careless trim of the list fails here. */
    public function test_the_previously_missing_verbs_are_listed(): void
    {
        foreach (['ran', 'requested', 'authorized', 'cancelled', 'rejected'] as $verb) {
            $this->assertContains($verb, AuditService::ACTIONS, "'{$verb}' went missing again");
        }
    }

    /** An unknown verb is a bug worth stopping for in development. */
    public function test_an_unknown_verb_is_refused_in_development(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AuditService::class)->log('teleported', 'Payroll', 'should not be possible');
    }

    /**
     * But it must NOT cost the operation in production. `log()` runs inside the same transaction as
     * the thing it records, so throwing would roll back a legitimate disbursement over a spelling
     * mistake in its own audit line.
     */
    public function test_an_unknown_verb_still_records_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        app(AuditService::class)->log('teleported', 'Payroll', 'recorded anyway');

        $this->assertSame(1, AuditLog::where('action', 'teleported')->count());
    }

    // --- FIN-041: paging instead of silent truncation ----------------------------------------

    private function seedEntries(int $count): void
    {
        foreach (range(1, $count) as $i) {
            AuditLog::create([
                'action' => 'updated', 'module' => 'Payroll',
                'description' => "Entry {$i}", 'ip_address' => '127.0.0.1',
            ]);
        }
    }

    public function test_the_report_states_the_total_rather_than_truncating_silently(): void
    {
        $this->seedEntries(420);

        $report = app(ReportService::class)->get('audit', ['per_page' => 100]);

        $this->assertSame(420, $report['pagination']['total'], 'the total must be stated');
        $this->assertSame(100, $report['pagination']['returned']);
        $this->assertSame(5, $report['pagination']['pages']);
        $this->assertCount(100, $report['entries']);
    }

    public function test_later_pages_return_different_entries(): void
    {
        $this->seedEntries(150);

        $first = app(ReportService::class)->get('audit', ['per_page' => 50, 'page' => 1]);
        $second = app(ReportService::class)->get('audit', ['per_page' => 50, 'page' => 2]);

        $this->assertNotSame(
            $first['entries'][0]['details'],
            $second['entries'][0]['details'],
            'page 2 returned the same rows as page 1',
        );
        $this->assertCount(50, $second['entries']);
    }

    /** THE ONE THAT MATTERS: an export must carry everything, not page one. */
    public function test_the_export_carries_every_row_in_range(): void
    {
        $this->seedEntries(420);

        $csv = app(ReportService::class)->download('audit')->getContent();

        // 420 entries + one header row.
        $this->assertSame(421, count(array_filter(explode("\n", trim($csv)))));
        $this->assertStringContainsString('Entry 1', $csv);
        $this->assertStringContainsString('Entry 420', $csv);
    }

    /** Nothing above 500 per page, so the screen cannot be asked to render 20,000 rows. */
    public function test_the_page_size_is_capped_for_the_screen(): void
    {
        $this->seedEntries(20);

        $report = app(ReportService::class)->get('audit', ['per_page' => 99999]);

        $this->assertSame(500, $report['pagination']['per_page']);
    }
}
