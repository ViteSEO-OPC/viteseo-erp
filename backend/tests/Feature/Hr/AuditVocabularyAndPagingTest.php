<?php

namespace Tests\Feature\Hr;

use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Services\AuditService;
use App\Modules\Hr\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** And — the audit trail lied about itself in two different ways. */
class AuditVocabularyAndPagingTest extends HrTestCase
{
    use RefreshDatabase;

    // ---: the vocabulary ------------------------------------------------------------

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

    // ---: paging instead of silent truncation ----------------------------------------

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

    /**
     * The data rows of the exported workbook — everything under the letterhead and the header.
     *
     * The export is an XLSX now rather than a CSV, so counting newlines says nothing. The file
     * is read where it was written: `download()` hands back a BinaryFileResponse over a temp
     * file, which is only cleaned up once it has actually been sent.
     *
     * @param  array<string, mixed>  $filters
     * @return list<list<string>>
     */
    private function exportedRows(array $filters = []): array
    {
        $response = app(ReportService::class)->download('audit', $filters);
        $this->assertInstanceOf(BinaryFileResponse::class, $response);

        $reader = new Reader();
        $reader->open($response->getFile()->getPathname());

        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($c) => (string) $c, $row->toArray());
            }
            break; // one sheet, and it is the only one that carries the entries
        }

        $reader->close();

        // Company name · title · address · blank · column headers, then the entries.
        $header = 0;
        foreach ($rows as $i => $row) {
            if ('Timestamp' === ($row[0] ?? null)) {
                $header = $i;
                break;
            }
        }

        return array_values(array_filter(
            array_slice($rows, $header + 1),
            fn (array $row) => '' !== trim(implode('', $row)),
        ));
    }

    /** THE ONE THAT MATTERS: an export must carry everything, not page one. */
    public function test_the_export_carries_every_row_in_range(): void
    {
        $this->seedEntries(420);

        $rows = $this->exportedRows();

        $this->assertCount(420, $rows);
        $flat = implode('|', array_map(fn (array $r) => implode('|', $r), $rows));
        $this->assertStringContainsString('Entry 1', $flat);
        $this->assertStringContainsString('Entry 420', $flat);
    }

    /** Nothing above 500 per page, so the screen cannot be asked to render 20,000 rows. */
    public function test_the_page_size_is_capped_for_the_screen(): void
    {
        $this->seedEntries(20);

        $report = app(ReportService::class)->get('audit', ['per_page' => 99999]);

        $this->assertSame(500, $report['pagination']['per_page']);
    }

    // --- the headcount-scaled report tables page too ---------------------------------

    /**
     * The payroll register carries a row per PAYSLIP, so it is as long as the run. What must not
     * move when it is paged are the figures ABOVE it: those are summed from the payslips, never
     * from the table's rows, and a register whose headline total described ten of them would be
     * invisible.
     */
    public function test_the_payroll_register_pages_without_moving_its_totals(): void
    {
        $run = \App\Modules\Hr\Models\PayrollRun::create([
            'cycle_start' => '2026-08-01', 'cycle_end' => '2026-08-15',
            'frequency' => 'semi_monthly', 'type' => 'regular', 'status' => 'completed',
            'total_gross' => 0, 'total_deductions' => 0, 'total_net' => 0, 'employee_count' => 0,
        ]);
        $dept = \App\Modules\Hr\Models\Department::create(['name' => 'Ops', 'code' => 'OPS']);

        foreach (range(1, 30) as $i) {
            $employee = \App\Modules\Hr\Models\Employee::create([
                'user_id' => \App\Models\User::factory()->create(['name' => "Person {$i}"])->id,
                'department_id' => $dept->id, 'employee_no' => sprintf('E-%03d', $i),
                'job_title' => 'Staff', 'hire_date' => '2020-01-01', 'employment_status' => 'regular',
                'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
            ]);
            \App\Modules\Hr\Models\Payslip::create([
                'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
                'base_salary' => 30000, 'gross' => 1000, 'net_pay' => 900, 'status' => 'disbursed',
            ]);
        }

        $svc = app(ReportService::class);

        $whole = $svc->get('payroll_register', ['per_page' => 200]);
        $paged = $svc->get('payroll_register', ['per_page' => 10]);

        // 30 payslips plus the department's subtotal row.
        $this->assertCount(31, $whole['table']['rows']);
        $this->assertSame(31, $paged['table']['meta']['total'], 'the meta must state the whole run');

        // One department, so one indivisible block — a subtotal must never be separated from the
        // rows it summarises, even when that overruns the requested page size.
        $this->assertCount(31, $paged['table']['rows'], 'the department block must not be split');
        $this->assertSame(1, $paged['table']['meta']['last_page']);

        // THE POINT: the cards above the table are identical whatever the page size.
        $this->assertEquals(
            $whole['summary'],
            $paged['summary'],
            'the register totals must describe the run, not the page',
        );
    }

    /** Page two is a different ten, and every row is reachable. */
    public function test_walking_the_register_returns_each_row_exactly_once(): void
    {
        $run = \App\Modules\Hr\Models\PayrollRun::create([
            'cycle_start' => '2026-08-01', 'cycle_end' => '2026-08-15',
            'frequency' => 'semi_monthly', 'type' => 'regular', 'status' => 'completed',
            'total_gross' => 0, 'total_deductions' => 0, 'total_net' => 0, 'employee_count' => 0,
        ]);
        $dept = \App\Modules\Hr\Models\Department::create(['name' => 'Ops', 'code' => 'OPS']);

        foreach (range(1, 25) as $i) {
            $employee = \App\Modules\Hr\Models\Employee::create([
                'user_id' => \App\Models\User::factory()->create(['name' => sprintf('Person %02d', $i)])->id,
                'department_id' => $dept->id, 'employee_no' => sprintf('E-%03d', $i),
                'job_title' => 'Staff', 'hire_date' => '2020-01-01', 'employment_status' => 'regular',
                'base_salary' => 30000, 'pay_frequency' => 'semi_monthly', 'is_management' => false,
            ]);
            \App\Modules\Hr\Models\Payslip::create([
                'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
                'base_salary' => 30000, 'gross' => 1000, 'net_pay' => 900, 'status' => 'disbursed',
            ]);
        }

        $svc = app(ReportService::class);
        $meta = $svc->get('payroll_register', ['per_page' => 10])['table']['meta'];

        $seen = [];
        foreach (range(1, $meta['last_page']) as $page) {
            foreach ($svc->get('payroll_register', ['per_page' => 10, 'page' => $page])['table']['rows'] as $row) {
                $seen[] = $row[0]; // the employee name column
            }
        }

        // 25 people plus one subtotal row, each appearing exactly once across the pages.
        $this->assertCount(26, $seen, 'a row was dropped or duplicated across the pages');
        $this->assertCount(26, array_unique($seen), 'the same row came back on two pages');
    }

    // --- item 17: the search runs on the SERVER --------------------------------------

    /**
     * The screen holds one page of a log that only ever grows, so a search done in the browser
     * searches that page and nothing else — and answers "no matches" for a record that exists
     * on page 40. The filter has to reach the query, and the TOTAL has to follow it, or the
     * pager offers pages the result no longer has.
     */
    public function test_the_search_narrows_the_whole_result_not_just_the_page(): void
    {
        $this->seedEntries(300);

        // Deliberately the OLDEST row: the report is newest-first, so this one sits on the last
        // page. A browser-side search over page 1 could never reach it.
        $needle = AuditLog::create([
            'action' => 'approved', 'module' => 'Leave',
            'description' => 'Approved a needle in the haystack', 'ip_address' => '127.0.0.1',
        ]);
        AuditLog::whereKey($needle->id)->update(['created_at' => now()->subMonths(2)]);

        // Page one of the unfiltered log cannot contain the needle — 300 newer rows sit on top.
        $unfiltered = app(ReportService::class)->get('audit', ['per_page' => 10, 'page' => 1]);
        $this->assertStringNotContainsString(
            'needle',
            json_encode($unfiltered['entries']),
            'precondition: the row being searched for is not on the page already loaded',
        );

        $found = app(ReportService::class)->get('audit', ['per_page' => 10, 'search' => 'needle']);

        $this->assertSame(1, $found['pagination']['total'], 'the total must follow the search');
        $this->assertSame(1, $found['pagination']['pages']);
        $this->assertCount(1, $found['entries']);
        $this->assertSame('Approved a needle in the haystack', $found['entries'][0]['details']);
    }

    /** The same box finds an action, a module, or the person who did it — not just the text. */
    public function test_the_search_also_matches_the_action_module_and_user(): void
    {
        $this->seedEntries(3);
        $actor = $this->hrUser();
        AuditLog::create([
            'user_id' => $actor->id, 'action' => 'disbursed', 'module' => 'Recruitment',
            'description' => 'Nothing distinctive here', 'ip_address' => '127.0.0.1',
        ]);

        $svc = app(ReportService::class);

        $this->assertSame(1, $svc->get('audit', ['search' => 'disbursed'])['pagination']['total']);
        $this->assertSame(1, $svc->get('audit', ['search' => 'Recruitment'])['pagination']['total']);
        $this->assertSame(1, $svc->get('audit', ['search' => $actor->name])['pagination']['total']);
    }

    /**
     * A search honoured on screen and dropped on export hands the reader a file that disagrees
     * with the table they downloaded it from — the same trap the shared FILTERS list guards.
     */
    public function test_the_export_carries_the_search_it_was_downloaded_under(): void
    {
        $this->seedEntries(50);
        AuditLog::create([
            'action' => 'updated', 'module' => 'Payroll',
            'description' => 'Distinctive marker row', 'ip_address' => '127.0.0.1',
        ]);

        $rows = $this->exportedRows(['search' => 'Distinctive']);

        $this->assertCount(1, $rows, 'only the matching row');
        $flat = implode('|', $rows[0]);
        $this->assertStringContainsString('Distinctive marker row', $flat);
        $this->assertStringNotContainsString('Entry 1', $flat);
    }

    /** An empty or whitespace-only box is not a filter — it must not narrow anything. */
    public function test_an_empty_search_leaves_the_result_untouched(): void
    {
        $this->seedEntries(30);

        $svc = app(ReportService::class);

        $this->assertSame(30, $svc->get('audit', ['search' => ''])['pagination']['total']);
        $this->assertSame(30, $svc->get('audit', ['search' => '   '])['pagination']['total']);
    }
}
