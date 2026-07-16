<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\AuditLog;
use App\Modules\Hr\Models\Candidate;
use App\Modules\Hr\Models\Concern;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\EmployeeBenefit;
use App\Modules\Hr\Models\LeaveBalance;
use App\Modules\Hr\Models\LeaveRequest;
use App\Modules\Hr\Models\ContributionSetting;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\ReportExport;
use App\Modules\Hr\Models\Requisition;
use App\Modules\Hr\Services\RecruitmentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Read-only analytics for the Reports hub. Real aggregates where the data
 * exists; the diversity report + the attrition RATE are clearly flagged mock
 * (no termination / demographic data in the MVP schema).
 *
 * Money is pre-formatted server-side here (display-only reports).
 */
class ReportService
{
    public const KEYS = [
        'headcount', 'attrition', 'leave', 'compensation', 'payroll',
        'recruitment', 'diversity', 'health_safety', 'audit',
    ];

    public function __construct(private readonly AuditService $audit) {}

    /** @param array<string, mixed> $filters */
    public function get(string $key, array $filters = []): ?array
    {
        $report = match ($key) {
            'headcount' => $this->headcount($filters),
            'attrition' => $this->attrition($filters),
            'leave' => $this->leave($filters),
            'compensation' => $this->compensation($filters),
            'payroll' => $this->payroll(),
            'recruitment' => $this->recruitment($filters),
            'diversity' => $this->diversity($filters),
            'health_safety' => $this->healthSafety($filters),
            'audit' => $this->audit($filters),
            default => null,
        };

        if ($report === null) {
            return null;
        }

        // Every report carries its export history.
        $report['exports'] = $this->exportsFor($key);

        return $report;
    }

    public function logExport(string $key, ?User $user): void
    {
        ReportExport::create(['report_key' => $key, 'generated_by' => $user?->id]);
        $this->audit->log('exported', 'Reports', 'Exported the ' . $this->reportTitle($key) . ' report');
    }

    /** Table-style reports (Audit Trail) export as CSV; chart reports export as PDF. */
    public function exportFormat(string $key): string
    {
        return $key === 'audit' ? 'csv' : 'pdf';
    }

    /**
     * Build the downloadable file for a report: a CSV of the rows for table reports,
     * or a rendered PDF (dompdf) of the summary + chart data for chart reports.
     */
    public function download(string $key): HttpResponse
    {
        $report = $this->get($key);
        $slug = Str::slug($report['title']);
        $date = now(config('app.timezone_local'))->format('Y-m-d');

        if ($this->exportFormat($key) === 'csv') {
            return response($this->buildCsv($report), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$slug}-{$date}.csv\"",
            ]);
        }

        return Pdf::loadView('hr::report', [
            'r' => $report,
            'generatedAt' => now(config('app.timezone_local'))->format('M j, Y g:i A'),
        ])->download("{$slug}-{$date}.pdf");
    }

    /**
     * CSV from whatever tabular data the report carries — audit entries first, then a
     * generic table, falling back to the summary metrics. Prefixed with a UTF-8 BOM so
     * Excel renders ₱ and accents correctly.
     *
     * @param  array<string, mixed>  $report
     */
    private function buildCsv(array $report): string
    {
        $rows = [];
        if (! empty($report['entries'])) {
            $rows[] = ['Timestamp', 'User', 'Module', 'Action', 'Details'];
            foreach ($report['entries'] as $e) {
                $rows[] = [$e['timestamp'], $e['user'], $e['module'], $e['action'], $e['details']];
            }
        } elseif (! empty($report['table'])) {
            $rows[] = $report['table']['columns'];
            foreach ($report['table']['rows'] as $r) {
                $rows[] = $r;
            }
        } else {
            $rows[] = ['Metric', 'Value'];
            foreach ($report['summary'] as $s) {
                $rows[] = [$s['label'], $s['value']];
            }
        }

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    private function reportTitle(string $key): string
    {
        return match ($key) {
            'headcount' => 'Headcount & Movement',
            'recruitment' => 'Recruitment Funnel',
            'leave' => 'Absences & Leaves',
            'compensation' => 'Compensation & Benefits',
            'attrition' => 'Attrition & Tenure',
            'diversity' => 'Diversity',
            'health_safety' => 'Health & Safety',
            'audit' => 'Audit Trail',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    }

    /** @return array<int, array{when:?string, by:string}> */
    private function exportsFor(string $key): array
    {
        return ReportExport::with('generatedBy')->where('report_key', $key)->latest()->take(5)->get()
            ->map(fn ($e) => ['when' => $e->created_at?->toDateTimeString(), 'by' => $e->generatedBy?->name ?? 'System'])
            ->all();
    }

    private function year(): int
    {
        return now(config('app.timezone_local'))->year;
    }

    private function peso(float $n): string
    {
        return '₱' . number_format($n, 0);
    }

    /** @param array<string, mixed> $filters */
    private function headcount(array $filters = []): array
    {
        $total = Employee::count();
        $deptId = $filters['department'] ?? null;

        $byDept = DB::table('hr_employees')
            ->join('hr_departments', 'hr_employees.department_id', '=', 'hr_departments.id')
            ->whereNull('hr_employees.deleted_at')
            ->selectRaw('hr_departments.name, count(*) as count')
            ->groupBy('hr_departments.name')
            ->orderByDesc('count')
            ->get();

        // Movement per month, last 12 months (real: joiners from hire_date, leavers
        // from the soft-delete date). Optionally scoped to one department.
        $cursor = now(config('app.timezone_local'))->startOfMonth()->subMonths(11);
        $movement = [];
        for ($i = 0; $i < 12; $i++) {
            // withTrashed so someone who joined this month still counts even if
            // they later left (were soft-deleted).
            $joinQ = Employee::withTrashed()->whereYear('hire_date', $cursor->year)->whereMonth('hire_date', $cursor->month);
            $leaveQ = Employee::onlyTrashed()->whereYear('deleted_at', $cursor->year)->whereMonth('deleted_at', $cursor->month);
            if ($deptId) {
                $joinQ->where('department_id', $deptId);
                $leaveQ->where('department_id', $deptId);
            }
            $joiners = $joinQ->count();
            $leavers = $leaveQ->count();
            $movement[] = [
                'label' => $cursor->format('M'),
                'joiners' => $joiners,
                'leavers' => $leavers,
                'net' => $joiners - $leavers,
            ];
            $cursor->addMonth();
        }

        $departments = DB::table('hr_departments')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();

        return [
            'title' => 'Headcount & Movement',
            'description' => 'Joiners, leavers, transfers; net change by department and month; filterable by department, location, date range.',
            'mock' => false,
            'summary' => [
                ['label' => 'Total headcount', 'value' => (string) $total],
                ['label' => 'Departments', 'value' => (string) $byDept->count()],
                ['label' => 'Hired this year', 'value' => (string) Employee::whereYear('hire_date', $this->year())->count()],
            ],
            'bars' => [
                'title' => 'Headcount by department',
                'items' => $byDept->map(fn ($d) => ['label' => $d->name, 'value' => (int) $d->count, 'display' => (string) $d->count])->all(),
            ],
            'table' => null,
            'movement' => $movement,
            'departments' => $departments,
            'note' => null,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function attrition(array $filters = []): array
    {
        $deptId = $filters['department'] ?? null;
        $now = now(config('app.timezone_local'));

        // Departures per quarter (last 4 quarters), split voluntary vs involuntary.
        $points = [];
        for ($i = 4; $i >= 1; $i--) {
            $qStart = $now->copy()->firstOfQuarter()->subQuarters($i);
            $qEnd = $qStart->copy()->endOfQuarter();
            $base = Employee::onlyTrashed()
                ->whereNotNull('separation_type')
                ->whereBetween('deleted_at', [$qStart, $qEnd])
                ->when($deptId, fn ($q) => $q->where('department_id', $deptId));
            $points[] = [
                'label' => 'Q' . $qStart->quarter . ' ' . $qStart->format('y'),
                'values' => [
                    (clone $base)->where('separation_type', 'voluntary')->count(),
                    (clone $base)->where('separation_type', 'involuntary')->count(),
                ],
            ];
        }

        // Tenure curve — % of current employees who have stayed at least N years.
        $active = Employee::whereNotNull('hire_date')
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->get();
        $total = max(1, $active->count());
        $tenure = [];
        foreach ([0, 1, 2, 3, 4, 5] as $y) {
            $cnt = $active->filter(fn ($e) => $e->hire_date->diffInYears($now) >= $y)->count();
            $tenure[] = [
                'label' => $y === 0 ? 'Start' : $y . ($y === 1 ? ' yr' : ' yrs'),
                'value' => (int) round($cnt / $total * 100),
            ];
        }

        $avg = $active->count() > 0
            ? round($active->sum(fn ($e) => $e->hire_date->diffInYears($now)) / $active->count(), 1)
            : 0;

        $departments = DB::table('hr_departments')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();

        return [
            'title' => 'Attrition & Tenure',
            'description' => 'Voluntary vs. involuntary departures, regretted attrition, tenure curves — how long employees stay.',
            'mock' => false,
            'summary' => [
                ['label' => 'Avg tenure', 'value' => "{$avg} yrs"],
                ['label' => 'Departures (last 4 qtrs)', 'value' => (string) array_sum(array_map(fn ($p) => array_sum($p['values']), $points))],
                ['label' => 'Headcount', 'value' => (string) $active->count()],
            ],
            'bars' => null,
            'table' => null,
            'departures' => [
                'series' => [['name' => 'Voluntary', 'color' => null], ['name' => 'Involuntary', 'color' => null]],
                'points' => $points,
            ],
            'tenure' => $tenure,
            'departments' => $departments,
            'note' => null,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function leave(array $filters = []): array
    {
        $deptId = $filters['department'] ?? null;
        $balances = LeaveBalance::where('year', $this->year())->get();

        // Approved leave days per month, stacked by leave type, last 12 months.
        $since = now(config('app.timezone_local'))->startOfMonth()->subMonths(11);
        $agg = LeaveRequest::query()
            ->join('hr_leave_types', 'hr_leave_requests.leave_type_id', '=', 'hr_leave_types.id')
            ->join('hr_employees', 'hr_leave_requests.employee_id', '=', 'hr_employees.id')
            ->where('hr_leave_requests.status', 'approved')
            ->where('hr_leave_requests.start_date', '>=', $since->toDateString())
            ->when($deptId, fn ($q) => $q->where('hr_employees.department_id', $deptId))
            ->selectRaw("DATE_FORMAT(hr_leave_requests.start_date, '%Y-%m') ym, hr_leave_types.id tid, hr_leave_types.name tname, hr_leave_types.color tcolor, SUM(hr_leave_requests.days) d")
            ->groupBy('ym', 'tid', 'tname', 'tcolor')
            ->get();

        // Series = the leave types that actually have data (stable order by id).
        $series = $agg->sortBy('tid')->unique('tid')
            ->map(fn ($r) => ['name' => $r->tname, 'color' => $r->tcolor])
            ->values();
        $seriesNames = $series->pluck('name')->all();

        $cursor = $since->copy();
        $points = [];
        for ($i = 0; $i < 12; $i++) {
            $ym = $cursor->format('Y-m');
            $values = array_fill(0, count($seriesNames), 0.0);
            foreach ($agg->where('ym', $ym) as $r) {
                $idx = array_search($r->tname, $seriesNames, true);
                if ($idx !== false) {
                    $values[$idx] = round((float) $r->d, 1);
                }
            }
            $points[] = ['label' => $cursor->format('M'), 'values' => $values];
            $cursor->addMonth();
        }

        $departments = DB::table('hr_departments')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();

        return [
            'title' => 'Absences & Leaves',
            'description' => 'Leave balances by employee and type, utilization, absence patterns, and liability accrual.',
            'mock' => false,
            'summary' => [
                ['label' => 'Days used (all types)', 'value' => number_format((float) $balances->sum('used_days'), 1)],
                ['label' => 'Pending requests', 'value' => (string) LeaveRequest::where('status', 'pending')->count()],
                ['label' => 'Approved (all-time)', 'value' => (string) LeaveRequest::where('status', 'approved')->count()],
            ],
            'bars' => null,
            'table' => null,
            'stacked' => ['series' => $series->all(), 'points' => $points],
            'departments' => $departments,
            'note' => null,
        ];
    }

    private function payroll(): array
    {
        $runs = PayrollRun::where('status', 'completed')->orderByDesc('cycle_start')->get();
        $netYtd = (float) $runs->filter(fn ($r) => (int) $r->cycle_start->format('Y') === $this->year())->sum('total_net');
        $latest = $runs->first();
        $costPerHead = $latest && $latest->employee_count > 0
            ? (float) $latest->total_net / $latest->employee_count
            : 0;

        // Government remittance (YTD): employee vs employer contribution per agency,
        // summed over this year's completed runs' payslips (P2-3).
        $agg = Payslip::query()
            ->join('hr_payroll_runs', 'hr_payslips.payroll_run_id', '=', 'hr_payroll_runs.id')
            ->where('hr_payroll_runs.status', 'completed')
            ->whereYear('hr_payroll_runs.cycle_start', $this->year())
            ->selectRaw('
                COALESCE(SUM(sss),0) ee_sss, COALESCE(SUM(philhealth),0) ee_ph, COALESCE(SUM(pagibig),0) ee_pg,
                COALESCE(SUM(withholding_tax),0) wht,
                COALESCE(SUM(employer_sss),0) er_sss, COALESCE(SUM(employer_philhealth),0) er_ph, COALESCE(SUM(employer_pagibig),0) er_pg
            ')
            ->first();

        $remitRow = function (string $agency, float $ee, float $er) {
            return [$agency, $this->peso($ee), $this->peso($er), $this->peso($ee + $er)];
        };
        $employerYtd = (float) $agg->er_sss + (float) $agg->er_ph + (float) $agg->er_pg;

        return [
            'title' => 'Payroll & Cost',
            'description' => 'Run history, gross-to-net, cost per head and government remittance.',
            'mock' => false,
            'summary' => [
                ['label' => 'Net paid (YTD)', 'value' => $this->peso($netYtd)],
                ['label' => 'Employer share (YTD)', 'value' => $this->peso($employerYtd)],
                ['label' => 'Avg cost / head', 'value' => $this->peso($costPerHead)],
                ['label' => 'Completed runs', 'value' => (string) $runs->count()],
            ],
            'remittance' => [
                'title' => 'Government Remittance (YTD)',
                'columns' => ['Agency', 'Employee', 'Employer', 'Total'],
                'rows' => [
                    $remitRow('SSS', (float) $agg->ee_sss, (float) $agg->er_sss),
                    $remitRow('PhilHealth', (float) $agg->ee_ph, (float) $agg->er_ph),
                    $remitRow('Pag-IBIG', (float) $agg->ee_pg, (float) $agg->er_pg),
                    ['Withholding Tax (BIR)', $this->peso((float) $agg->wht), '—', $this->peso((float) $agg->wht)],
                ],
            ],
            'bars' => [
                'title' => 'Net payout per run',
                'items' => $runs->take(9)->reverse()->values()->map(fn ($r) => [
                    'label' => $r->cycle_start->format('M j'),
                    'value' => (int) $r->total_net,
                    'display' => $this->peso((float) $r->total_net),
                ])->all(),
            ],
            'table' => [
                'columns' => ['Cycle', 'Gross', 'Deductions', 'Net'],
                'rows' => $runs->map(fn ($r) => [
                    $r->cycle_start->format('M j') . ' – ' . $r->cycle_end->format('M j'),
                    $this->peso((float) $r->total_gross),
                    $this->peso((float) $r->total_deductions),
                    $this->peso((float) $r->total_net),
                ])->all(),
            ],
            'note' => null,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function recruitment(array $filters = []): array
    {
        $deptId = $filters['department'] ?? null;
        $months = (int) ($filters['months'] ?? 9);
        $since = now(config('app.timezone_local'))->startOfMonth()->subMonths(max(0, $months - 1));

        // Funnel steps = the standard pipeline flow, minus the terminal "rejected"
        // (aligns with RecruitmentService::STAGE_FLOWS). A candidate at stage N has
        // reached every earlier stage, so counts decrease down the funnel.
        $steps = array_values(array_filter(
            RecruitmentService::stagesFor('standard'),
            fn ($s) => $s['key'] !== 'rejected',
        ));
        $stepKeys = array_column($steps, 'key');

        $stages = Candidate::query()
            ->join('hr_requisitions', 'hr_candidates.requisition_id', '=', 'hr_requisitions.id')
            ->where('hr_requisitions.pipeline', 'standard')
            ->where('hr_candidates.created_at', '>=', $since)
            ->when($deptId, fn ($q) => $q->where('hr_requisitions.department_id', $deptId))
            ->pluck('hr_candidates.stage');

        $funnel = [];
        foreach ($stepKeys as $i => $key) {
            $count = $stages->filter(function ($stage) use ($stepKeys, $i) {
                $idx = array_search($stage, $stepKeys, true);
                return $idx !== false && $idx >= $i; // reached this step or beyond
            })->count();
            $funnel[] = ['label' => $steps[$i]['label'], 'value' => $count, 'display' => (string) $count];
        }

        $departments = DB::table('hr_departments')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();

        return [
            'title' => 'Recruitment Funnel',
            'description' => 'Time-to-hire, source effectiveness, offer acceptance rate; funnel matches the pipeline stages.',
            'mock' => false,
            'summary' => [
                ['label' => 'Open requisitions', 'value' => (string) Requisition::where('stage', 'active')->count()],
                ['label' => 'Candidates in pipeline', 'value' => (string) Candidate::whereNotIn('stage', ['hired', 'rejected'])->count()],
                ['label' => 'Hired', 'value' => (string) Candidate::where('stage', 'hired')->count()],
            ],
            'bars' => null,
            'table' => null,
            'funnel' => $funnel,
            'departments' => $departments,
            'note' => null,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function diversity(array $filters = []): array
    {
        $deptId = $filters['department'] ?? null;

        // Gender representation per department (100%-stacked women/men).
        $rows = DB::table('hr_employees')
            ->join('hr_departments', 'hr_employees.department_id', '=', 'hr_departments.id')
            ->whereNull('hr_employees.deleted_at')
            ->when($deptId, fn ($q) => $q->where('hr_departments.id', $deptId))
            ->selectRaw("hr_departments.name dept, SUM(hr_employees.gender = 'Female') women, SUM(hr_employees.gender = 'Male') men")
            ->groupBy('hr_departments.name')
            ->orderBy('hr_departments.name')
            ->get();

        $points = [];
        foreach ($rows as $r) {
            $known = (int) $r->women + (int) $r->men;
            if ($known === 0) {
                continue; // no gender data for this department
            }
            $womenPct = (int) round((int) $r->women / $known * 100);
            $points[] = ['label' => $r->dept, 'values' => [$womenPct, 100 - $womenPct]];
        }

        $departments = DB::table('hr_departments')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();

        return [
            'title' => 'Diversity',
            'description' => 'Gender representation across departments; supports balanced representation. Restricted to HR and leadership.',
            'mock' => false,
            'restricted' => true,
            'summary' => [],
            'bars' => null,
            'table' => null,
            'representation' => [
                'series' => [['name' => 'Women', 'color' => null], ['name' => 'Men', 'color' => null]],
                'points' => $points,
            ],
            'departments' => $departments,
            'note' => null,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function compensation(array $filters = []): array
    {
        $deptId = $filters['department'] ?? null;
        $months = (int) ($filters['months'] ?? 9);
        $since = now(config('app.timezone_local'))->startOfMonth()->subMonths(max(0, $months - 1));

        // Contribution rates (for legend labels), from the editable settings.
        $cfg = ContributionSetting::pluck('config', 'type');
        $rate = fn (string $type) => isset($cfg[$type]['rate'])
            ? ' ' . rtrim(rtrim(number_format($cfg[$type]['rate'] * 100, 2), '0'), '.') . '%'
            : '';

        // Gross-to-net stacked per payroll run: net pay + the 3 gov contributions
        // (excludes withholding tax, matching the design's legend). Values in ₱ '000s.
        $series = [
            ['name' => 'Net pay', 'color' => null],
            ['name' => 'SSS', 'color' => null],
            ['name' => 'PhilHealth' . $rate('philhealth'), 'color' => null],
            ['name' => 'Pag-IBIG' . $rate('pagibig'), 'color' => null],
        ];

        $runs = PayrollRun::where('status', 'completed')
            ->where('cycle_start', '>=', $since->toDateString())
            ->orderBy('cycle_start')
            ->get();

        $points = [];
        foreach ($runs as $r) {
            $q = Payslip::where('payroll_run_id', $r->id);
            if ($deptId) {
                $q->join('hr_employees', 'hr_payslips.employee_id', '=', 'hr_employees.id')
                    ->where('hr_employees.department_id', $deptId);
            }
            $s = $q->selectRaw('SUM(hr_payslips.net_pay) net, SUM(hr_payslips.sss) sss, SUM(hr_payslips.philhealth) phil, SUM(hr_payslips.pagibig) pag')->first();
            $k = fn ($v) => round(((float) ($v ?? 0)) / 1000, 1);
            $sameMonth = $r->cycle_start->format('M') === $r->cycle_end->format('M');
            $points[] = [
                'label' => $r->cycle_start->format('M j') . '–' . ($sameMonth ? $r->cycle_end->format('j') : $r->cycle_end->format('M j')),
                'values' => [$k($s->net), $k($s->sss), $k($s->phil), $k($s->pag)],
            ];
        }

        // Benefits coverage — % of employees enrolled in each contribution.
        $total = max(1, Employee::count());
        $coverage = [];
        foreach (['sss' => 'SSS', 'philhealth' => 'PhilHealth', 'pagibig' => 'Pag-IBIG'] as $type => $label) {
            $enrolled = EmployeeBenefit::where('contribution_type', $type)->where('enrolled', true)->count();
            $pct = (int) round($enrolled / $total * 100);
            $coverage[] = ['label' => $label, 'value' => $pct, 'display' => "{$pct}%"];
        }

        $departments = DB::table('hr_departments')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();

        return [
            'title' => 'Compensation & Benefits',
            'description' => 'Gross-to-net breakdowns, cost per head, benefits coverage, payroll run history for audit.',
            'mock' => false,
            'summary' => [
                ['label' => 'Gross paid (YTD)', 'value' => $this->peso((float) PayrollRun::where('status', 'completed')->whereYear('cycle_start', $this->year())->sum('total_gross'))],
                ['label' => 'Net paid (YTD)', 'value' => $this->peso((float) PayrollRun::where('status', 'completed')->whereYear('cycle_start', $this->year())->sum('total_net'))],
                ['label' => 'Benefits coverage', 'value' => count($coverage) ? ($coverage[0]['display']) : '—'],
            ],
            'bars' => null,
            'table' => null,
            'stacked' => ['series' => $series, 'points' => $points],
            'departments' => $departments,
            'note' => 'Bars stack net pay + the three government contributions (excludes withholding tax).',
        ];
    }

    /** @param array<string, mixed> $filters */
    private function healthSafety(array $filters = []): array
    {
        // Safety-category concerns only — and NEVER any submitter identity (§14.3g).
        // We aggregate to counts (anonymous vs named), so anonymity is preserved.
        $deptId = $filters['department'] ?? null;
        $since = now(config('app.timezone_local'))->startOfMonth()->subMonths(11);

        $q = Concern::query()
            ->where('hr_concerns.category', 'safety')
            ->where('hr_concerns.submitted_at', '>=', $since);
        if ($deptId) {
            $q->join('hr_employees', 'hr_concerns.employee_id', '=', 'hr_employees.id')
                ->where('hr_employees.department_id', $deptId);
        }
        $agg = $q->selectRaw("DATE_FORMAT(hr_concerns.submitted_at, '%Y-%m') ym, SUM(hr_concerns.is_anonymous = 1) anon, SUM(hr_concerns.is_anonymous = 0) named")
            ->groupBy('ym')
            ->get();

        $cursor = $since->copy();
        $points = [];
        $total = 0;
        for ($i = 0; $i < 12; $i++) {
            $row = $agg->firstWhere('ym', $cursor->format('Y-m'));
            $anon = (int) ($row->anon ?? 0);
            $named = (int) ($row->named ?? 0);
            $total += $anon + $named;
            $points[] = ['label' => $cursor->format('M'), 'values' => [$anon, $named]];
            $cursor->addMonth();
        }

        $departments = DB::table('hr_departments')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();

        return [
            'title' => 'Health & Safety',
            'description' => 'Incident tracking required for the agency. Employees may report concerns anonymously, with an option to name the person being reported.',
            'mock' => false,
            'summary' => [
                ['label' => 'Safety reports (12 mo)', 'value' => (string) $total],
            ],
            'bars' => null,
            'table' => null,
            'stacked' => [
                'series' => [['name' => 'Anonymous', 'color' => null], ['name' => 'Named', 'color' => null]],
                'points' => $points,
            ],
            'departments' => $departments,
            'note' => 'Anonymous reporters are never identified — the report only counts.',
        ];
    }

    /** @param array<string, mixed> $filters */
    /** @param array<string, mixed> $filters */
    private function audit(array $filters): array
    {
        $months = (int) ($filters['months'] ?? 9);
        $since = now(config('app.timezone_local'))->startOfMonth()->subMonths(max(0, $months - 1));

        $entries = AuditLog::with('user')
            ->where('created_at', '>=', $since)
            ->latest()
            ->take(300)
            ->get();

        return [
            'title' => 'Audit Trail',
            'description' => 'System activity log: who did what, when, and to which record — record changes, approvals, logins, and report exports; restricted to admins and HR leadership.',
            'mock' => false,
            'restricted' => true,
            'summary' => [],
            'bars' => null,
            'table' => null,
            'entries' => $entries->map(fn ($a) => [
                'timestamp' => $a->created_at?->format('Y-m-d H:i') ?? '—',
                'user' => $a->user?->name ?? 'System',
                'module' => $a->module ?? '—',
                'action' => $a->action,
                'details' => $a->description ?? '—',
            ])->all(),
            'note' => null,
        ];
    }
}
