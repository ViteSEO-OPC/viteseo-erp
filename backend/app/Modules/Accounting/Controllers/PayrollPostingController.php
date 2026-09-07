<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Resources\JournalEntryResource;
use App\Modules\Accounting\Services\PayrollPostingService;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollPostingController extends Controller
{
    public function __construct(
        private readonly PayrollPostingService $posting,
    ) {}

    /**
     * HR payroll runs with their TRUE employer cost (gross + employer
     * contributions) and whether each has been posted to the ledger.
     */
    public function runs(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can('accounting.payroll.post') || $request->user()->can('accounting.journal.view'),
            403,
        );

        // Raw query — the BelongsToOrg scope does not apply, so fence the tenant
        // here: only the CURRENT org's payroll runs are listed for posting.
        $runs = DB::table('hr_payroll_runs')
            ->where('org_id', CurrentOrg::id())
            ->whereNull('deleted_at')
            ->orderByDesc('cycle_end')
            ->get();

        $data = $runs->map(function ($run): array {
            $t = $this->posting->totalsForRun((int) $run->id);

            return [
                'id' => (int) $run->id,
                'cycle_start' => $run->cycle_start,
                'cycle_end' => $run->cycle_end,
                'status' => $run->status,
                'employees' => $t['employees'],
                'gross' => $t['gross'],
                'employer_contributions' => round($t['er_sss'] + $t['er_ph'] + $t['er_pg'], 2),
                'total_cost' => $this->posting->employerCost($t),
                'posted' => $this->posting->isPosted((int) $run->id),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /** Post a payroll run to the ledger (idempotent — a posted run is rejected). */
    public function post(Request $request, int $run): JournalEntryResource
    {
        abort_unless($request->user()->can('accounting.payroll.post'), 403);

        $entry = $this->posting->postRun($run, $request->user());
        $entry->load('lines.account');

        return JournalEntryResource::make($entry);
    }

    /**
     * Import payroll from a CSV (for standalone / external-HRIS clients) and post
     * it to the ledger. Columns are flexible; unknown/blank ones default to 0.
     */
    public function import(Request $request): JournalEntryResource
    {
        abort_unless($request->user()->can('accounting.payroll.post'), 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'entry_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        $totals = $this->parseCsv($request->file('file'));
        if ($totals['rows'] === 0) {
            throw ValidationException::withMessages(['file' => 'No data rows found in the CSV.']);
        }

        $entry = $this->posting->importTotals(
            $totals,
            $data['entry_date'],
            $data['memo'] ?? 'Imported payroll',
            $request->user(),
        );
        $entry->load('lines.account');

        return JournalEntryResource::make($entry);
    }

    /**
     * Sum a payroll CSV into totals. Header names are matched case-insensitively
     * with common aliases; missing columns contribute 0 (flexibility per company).
     *
     * @return array<string, float|int>
     */
    private function parseCsv(UploadedFile $file): array
    {
        $t = ['gross' => 0.0, 'ee_sss' => 0.0, 'ee_ph' => 0.0, 'ee_pg' => 0.0, 'er_sss' => 0.0, 'er_ph' => 0.0, 'er_pg' => 0.0, 'wht' => 0.0, 'net' => 0.0, 'rows' => 0, 'employees' => 0];

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return $t;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return $t;
        }

        $map = [];
        foreach ($header as $i => $h) {
            $map[$this->normalizeHeader((string) $h)] = $i;
        }

        $get = function (array $row, array $keys) use ($map): float {
            foreach ($keys as $key) {
                // The keys below are written readably with underscores; the map
                // is squashed, so squash the key the same way before looking.
                $k = $this->normalizeHeader($key);
                if (isset($map[$k]) && isset($row[$map[$k]]) && trim((string) $row[$map[$k]]) !== '') {
                    return (float) str_replace([',', ' '], '', (string) $row[$map[$k]]);
                }
            }
            return 0.0;
        };

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $t['gross'] += $get($row, ['gross', 'gross_pay']);
            $t['ee_sss'] += $get($row, ['sss_employee', 'sss_ee', 'ee_sss', 'sss']);
            $t['ee_ph'] += $get($row, ['philhealth_employee', 'philhealth_ee', 'ee_philhealth', 'philhealth']);
            $t['ee_pg'] += $get($row, ['pagibig_employee', 'pagibig_ee', 'ee_pagibig', 'pagibig']);
            $t['er_sss'] += $get($row, ['sss_employer', 'sss_er', 'er_sss', 'employer_sss']);
            $t['er_ph'] += $get($row, ['philhealth_employer', 'philhealth_er', 'er_philhealth', 'employer_philhealth']);
            $t['er_pg'] += $get($row, ['pagibig_employer', 'pagibig_er', 'er_pagibig', 'employer_pagibig']);
            $t['wht'] += $get($row, ['withholding_tax', 'withholding', 'wht', 'tax']);
            $t['net'] += $get($row, ['net_pay', 'net', 'take_home']);
            $t['rows']++;
            $t['employees']++;
        }
        fclose($handle);

        foreach ($t as $k => $v) {
            if ($k !== 'rows' && $k !== 'employees') {
                $t[$k] = round((float) $v, 2);
            }
        }

        return $t;
    }

    /**
     * Reduce a CSV header to letters and digits only, so separators cannot
     * decide whether a column is found.
     *
     * The fund is called "Pag-IBIG". Replacing each run of non-alphanumerics
     * with an underscore made that `pag_ibig_employee`, which matched none of
     * the keys this looks for -- so a correctly spelled column imported as zero.
     * Squashing instead makes `pagibigemployee` on both sides, and everything
     * that matched before still matches: `sss_employee` and "SSS Employee" both
     * reduce to `sssemployee`.
     */
    private function normalizeHeader(string $h): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $h) ?? '');
    }
}
