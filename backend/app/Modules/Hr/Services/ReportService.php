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
use App\Modules\Hr\Models\LeaveSetting;
use App\Modules\Hr\Models\SharedLeaveBalance;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\Payslip;
use App\Modules\Hr\Models\ReportExport;
use App\Modules\Hr\Models\Requisition;
use App\Modules\Hr\Services\RecruitmentService;
use App\Modules\Hr\Services\TaxService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Read-only analytics for the Reports hub. Real aggregates where the data
 * exists; the diversity report + the attrition RATE are clearly flagged mock
 * (no termination / demographic data in the MVP schema).
 */
class ReportService
{
    public const KEYS = [
        'headcount', 'attrition', 'leave', 'compensation', 'payroll',
        'recruitment', 'diversity', 'health_safety', 'audit',
        // The export route gates on THIS list, not on `get()`'s match. A report added to one
        // and not the other reads fine and refuses to export, which is how it would ship half-wired.
        'leave_liability',
        // The pre-disbursement review screen: register → totals → variance.
        'payroll_register',
        // The year-end check: tax due against tax withheld, per employee.
        'tax_reconciliation',
        // The three month-scoped attendance views, built by AttendanceReportService.
        ...AttendanceReportService::KEYS,
    ];

    public function __construct(
        private readonly AuditService $audit,
        // The legend resolves the dated rate version payroll actually priced with.
        private readonly ContributionService $contributions,
        // The leave-liability report values leave on exactly the basis final pay uses, so it
        // borrows PayrollService rather than reimplementing `unusedLeaveDaysFor()` + `dailyRateFor()`.
        private readonly PayrollService $payrollService,
        // The attendance reports share one month-long day grid; they live in their own service so
        // the three of them can read the same calendar instead of rebuilding it each.
        private readonly AttendanceReportService $attendanceReports,
        // The reconciliation recomputes each employee's annual tax from the same dated
        // table payroll used, rather than trusting the figure payroll happened to withhold.
        private readonly TaxService $tax,
        // Who the employer is, for the PDF letterhead.
        private readonly CompanyProfileService $companyProfile,
    ) {}

    /** @param array<string, mixed> $filters */
    public function get(string $key, array $filters = []): ?array
    {
        $report = match ($key) {
            'headcount' => $this->headcount($filters),
            'attrition' => $this->attrition($filters),
            'leave' => $this->leave($filters),
            'leave_liability' => $this->leaveLiability($filters),
            'payroll_register' => $this->payrollRegister($filters),
            'tax_reconciliation' => $this->taxReconciliation($filters),
            'compensation' => $this->compensation($filters),
            'payroll' => $this->payroll($filters),
            'recruitment' => $this->recruitment($filters),
            'diversity' => $this->diversity($filters),
            'health_safety' => $this->healthSafety($filters),
            'audit' => $this->audit($filters),
            'attendance_summary' => $this->attendanceReports->summary($filters),
            'attendance_dtr' => $this->attendanceReports->dailyTimeRecord($filters),
            'attendance_leave' => $this->attendanceReports->leaveReport($filters),
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
        // The liability report exports CSV for the same reason the audit trail does: it is a
        // row-per-employee table that accounting reconciles against, not a chart anyone reads. The
        // attendance reports are the same shape: a register HR reconciles, not a chart.
        // The tax reconciliation joins that list for the same reason: it is a
        // row-per-employee table accounting works through against BIR 2316, not a chart.
        return in_array($key, ['audit', 'leave_liability', 'payroll_register', 'tax_reconciliation', ...AttendanceReportService::KEYS], true)
            ? 'csv'
            : 'pdf';
    }

    /**
     * Build the downloadable file for a report: a CSV of the rows for table reports,
     * or a rendered PDF (dompdf) of the summary + chart data for chart reports.
     */
    /**
     * @param  array<string, mixed>  $filters  the same filters the screen was showing, so the file
     *                                         matches what the reader was looking at when they
     *                                         clicked export rather than silently reverting to the
     *                                         report's defaults.
     */
    public function download(string $key, array $filters = []): HttpResponse
    {
        // An export carries EVERY row in range, not the first page. A file named after the
        // whole period that contains page 1 of 4 is the same silent truncation the old 300-row cap
        // was, moved into a filename.
        $report = $this->get($key, $filters + ['all' => true]);
        $slug = Str::slug($report['title']);
        // A month-scoped report is named for its month; everything else for the day it was pulled.
        $date = $report['selected_month']['value']
            ?? now(config('app.timezone_local'))->format('Y-m-d');

        if ($this->exportFormat($key) === 'csv') {
            return response($this->buildCsv($report), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$slug}-{$date}.csv\"",
            ]);
        }

        return Pdf::loadView('hr::report', [
            'r' => $report,
            'generatedAt' => now(config('app.timezone_local'))->format('M j, Y g:i A'),
            // The letterhead, from Company → Settings — the same one the payslip prints.
            'company' => $this->companyProfile->letterhead(),
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

        // Audit `details`, usernames and generic table rows are all user-controlled
        // free text, so they go out through the shared neutralising writer.
        return CsvWriter::render($rows);
    }

    private function reportTitle(string $key): string
    {
        return match ($key) {
            'headcount' => 'Headcount & Movement',
            'recruitment' => 'Recruitment Funnel',
            'leave' => 'Absences & Leaves',
            'leave_liability' => 'Leave Liability',
            'payroll_register' => 'Payroll Register',
            'tax_reconciliation' => 'Year-End Tax Reconciliation',
            'compensation' => 'Compensation & Benefits',
            'attrition' => 'Attrition & Tenure',
            'diversity' => 'Diversity',
            'health_safety' => 'Health & Safety',
            'audit' => 'Audit Trail',
            'attendance_summary', 'attendance_dtr', 'attendance_leave' => AttendanceReportService::titleFor($key),
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

    /** Money to the centavo. */
    private function pesoExact(float $n): string
    {
        return '₱' . number_format($n, 2);
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
            'description' => 'Onboard, off-board, transfers; net change by department and month; filterable by department, location, date range.',
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

        // "Days used" reads the ACTIVE bucket: the shared pool in shared mode,
        // else the sum of per-type balances (per-type used_days stays 0 under
        // shared, so summing them would wrongly report 0).
        $year = $this->year();
        $isShared = LeaveSetting::current()->allotment_mode === LeaveSetting::MODE_SHARED;
        $daysUsed = $isShared
            ? (float) SharedLeaveBalance::where('year', $year)->sum('used_days')
            : (float) LeaveBalance::where('year', $year)->sum('used_days');

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
                ['label' => $isShared ? 'Days used (shared pool)' : 'Days used (all types)', 'value' => number_format($daysUsed, 1)],
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

    /**
     * The peso value of unused paid leave — an obligation that was nowhere on the books.
     *
     * @param  array<string, mixed>  $filters
     */
    private function leaveLiability(array $filters = []): array
    {
        $deptId = $filters['department'] ?? null;
        $tz = config('app.timezone_local');
        $asOf = now($tz)->toDateString();
        $year = $this->year();

        // Active staff only. A former employee's balance is not an accrual — it has either been paid
        // out by final pay or it lapsed with the employment, and carrying it here would double-count
        // against the payslip that already settled it.
        $employees = Employee::query()
            ->with(['user', 'department'])
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->get();

        $rows = [];
        $totalAmount = 0.0;
        $totalDays = 0.0;
        $byDepartment = [];

        foreach ($employees as $employee) {
            $liability = $this->payrollService->leaveLiabilityFor($employee, $year, $asOf);

            if ($liability['days'] <= 0) {
                continue; // nothing accrued means nothing owed, and an all-zero table hides the signal
            }

            $totalDays += $liability['days'];
            $totalAmount += $liability['amount'];

            $deptName = $employee->department?->name ?? 'Unassigned';
            $byDepartment[$deptName] = ($byDepartment[$deptName] ?? 0) + $liability['amount'];

            $rows[] = [
                'name' => $employee->user?->name ?? $employee->employee_no,
                'department' => $deptName,
                'days' => $liability['days'],
                'daily_rate' => $liability['daily_rate'],
                'amount' => $liability['amount'],
            ];
        }

        // Largest exposure first — the point of the report is which balances are worth acting on.
        usort($rows, fn (array $a, array $b) => $b['amount'] <=> $a['amount']);

        arsort($byDepartment);

        $departments = DB::table('hr_departments')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();

        return [
            'title' => 'Leave Liability',
            'description' => 'What unused paid leave would cost if it were paid out today — the accrued obligation behind every leave balance.',
            'mock' => false,
            'summary' => [
                ['label' => 'Accrued liability', 'value' => $this->peso($totalAmount)],
                ['label' => 'Unused paid days', 'value' => number_format($totalDays, 1)],
                ['label' => 'Employees with a balance', 'value' => (string) count($rows)],
            ],
            // `bars` is {title, items:[{label,value,display}]} — the `{series, points}` shape belongs
            // to `stacked` and would have rendered nothing here.
            'bars' => $byDepartment === [] ? null : [
                'title' => 'Liability by department',
                'items' => array_map(
                    fn (string $name, float $amount) => [
                        'label' => $name,
                        'value' => (int) round($amount),
                        'display' => $this->peso($amount),
                    ],
                    array_keys($byDepartment),
                    array_values($byDepartment),
                ),
            ],
            'table' => [
                'columns' => ['Employee', 'Department', 'Unused days', 'Daily rate', 'Liability'],
                'rows' => array_map(fn (array $r) => [
                    $r['name'],
                    $r['department'],
                    number_format($r['days'], 1),
                    $this->peso($r['daily_rate']),
                    $this->peso($r['amount']),
                ], $rows),
            ],
            'departments' => $departments,
            'note' => 'An estimate at today\'s salaries and today\'s balances. Only PAID leave types count '
                . 'toward it, valued on the same basis final pay uses to convert unused leave to cash. '
                . 'Former employees are excluded — their balances were settled by their final pay.',
        ];
    }

    /**
     * A gross/net total across the given runs, department-scoped and filter-aware.
     *
     * @param  \Illuminate\Support\Collection<int, PayrollRun>  $runs
     */
    private function compensationTotal($runs, string $column, ?int $deptId, bool $scoped): float
    {
        if ($deptId !== null) {
            $payslipColumn = $column === 'total_gross' ? 'gross' : 'net_pay';

            return (float) Payslip::query()
                ->join('hr_employees', 'hr_payslips.employee_id', '=', 'hr_employees.id')
                ->whereIn('hr_payslips.payroll_run_id', $runs->pluck('id'))
                ->where('hr_employees.department_id', $deptId)
                ->sum('hr_payslips.' . $payslipColumn);
        }

        if ($scoped) {
            return (float) $runs->sum($column);
        }

        return (float) PayrollRun::where('status', 'completed')
            ->whereYear('cycle_start', $this->year())
            ->sum($column);
    }

    /**
     * One run's net, for the chart — from the run header normally, from the payslips when a department
     * filter is on.
     */
    private function runNet(PayrollRun $run, ?int $deptId): float
    {
        if ($deptId === null) {
            return (float) $run->total_net;
        }

        return (float) $run->payslips()
            ->whereHas('employee', fn ($e) => $e->where('department_id', $deptId))
            ->sum('net_pay');
    }

    /**
     * One run's gross / deductions / net as display strings, department-scoped when asked.
     *
     * @return list<string>
     */
    private function runTotals(PayrollRun $run, ?int $deptId): array
    {
        if ($deptId === null) {
            return [
                $this->peso((float) $run->total_gross),
                $this->peso((float) $run->total_deductions),
                $this->peso((float) $run->total_net),
            ];
        }

        $slips = $run->payslips()
            ->whereHas('employee', fn ($e) => $e->where('department_id', $deptId));

        $gross = (float) $slips->clone()->sum('gross');
        $net = (float) $slips->clone()->sum('net_pay');

        return [
            $this->peso($gross),
            // Derived as gross − net rather than summing `total_deductions`: the payslip column is
            // capped at gross, so summing it would not equal what actually came off.
            $this->peso(round($gross - $net, 2)),
            $this->peso($net),
        ];
    }

    /**
     * The payroll register — the screen payroll review actually needs before disbursement.
     *
     * @param  array<string, mixed>  $filters
     */
    /**
     * Year-end tax reconciliation — per employee, tax DUE against tax WITHHELD.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function taxReconciliation(array $filters = []): array
    {
        $year = (int) ($filters['year'] ?? now(config('app.timezone_local'))->year);
        $tolerance = 1.0; // a peso — below this is rounding across a year of payslips, not a gap.

        $slips = DB::table('hr_payslips')
            ->join('hr_payroll_runs', 'hr_payslips.payroll_run_id', '=', 'hr_payroll_runs.id')
            ->join('hr_employees', 'hr_payslips.employee_id', '=', 'hr_employees.id')
            ->leftJoin('users', 'hr_employees.user_id', '=', 'users.id')
            ->leftJoin('hr_departments', 'hr_employees.department_id', '=', 'hr_departments.id')
            ->whereNull('hr_payslips.deleted_at')
            ->whereNull('hr_payroll_runs.deleted_at')
            ->where('hr_payroll_runs.type', 'regular')
            ->where('hr_payroll_runs.status', 'completed')
            ->whereYear('hr_payroll_runs.cycle_start', $year)
            ->groupBy('hr_payslips.employee_id', 'users.name', 'hr_departments.name')
            ->selectRaw(
                'hr_payslips.employee_id, users.name AS employee, hr_departments.name AS department,'
                .' SUM(hr_payslips.taxable_pay) AS taxable,'
                .' SUM(hr_payslips.withholding_tax) AS withheld,'
                // Non-zero on the payslip that settled the year — so "was this employee ever
                // annualised at all?" is answerable, which is the question that catches a whole
                // pay group silently missing out.
                .' SUM(ABS(hr_payslips.tax_annualisation)) AS settled,'
                .' COUNT(*) AS periods'
            )
            ->orderBy('users.name')
            ->get();

        $rows = [];
        $short = 0;
        $over = 0;
        $unsettled = 0;
        $totalDue = 0.0;
        $totalWithheld = 0.0;

        foreach ($slips as $s) {
            $taxable = round((float) $s->taxable, 2);
            $withheld = round((float) $s->withheld, 2);
            $due = round($this->tax->annualTax($taxable, "{$year}-12-31"), 2);
            $gap = round($withheld - $due, 2);

            $totalDue = round($totalDue + $due, 2);
            $totalWithheld = round($totalWithheld + $withheld, 2);

            $settledEver = round((float) $s->settled, 2) > 0.0;
            if (! $settledEver) {
                $unsettled++;
            }
            // Counted only once the year is settled for them, so the headline figures agree with
            // the Status column rather than contradicting it on an open year.
            if ($settledEver && $gap < -$tolerance) {
                $short++;
            } elseif ($settledEver && $gap > $tolerance) {
                $over++;
            }

            $rows[] = [
                $s->employee ?? '—',
                $s->department ?? 'Unassigned',
                (string) $s->periods,
                $this->pesoExact($taxable),
                $this->pesoExact($due),
                $this->pesoExact($withheld),
                ($gap > 0 ? '+' : '') . $this->pesoExact($gap),
                // The word, not the sign — "short ₱1,300" is the sentence someone acts on.
                match (true) {
                    abs($gap) <= $tolerance && $settledEver => 'Balanced',
                    ! $settledEver => 'Not settled yet',
                    $gap < 0 => 'SHORT — under-withheld',
                    default => 'OVER — refund owed',
                },
            ];
        }

        $balanced = count($rows) - $short - $over - $unsettled;

        return [
            'title' => 'Year-End Tax Reconciliation',
            'description' => 'Per employee, the tax actually due on the year against the tax actually withheld — the BIR 2316 pair, recomputed from the payslips themselves.',
            'mock' => false,
            'summary' => [
                ['label' => 'Employees', 'value' => (string) count($rows)],
                ['label' => 'Balanced', 'value' => (string) $balanced],
                ['label' => 'Short', 'value' => (string) $short],
                ['label' => 'Over-withheld', 'value' => (string) $over],
                ['label' => 'Not settled', 'value' => (string) $unsettled],
                ['label' => 'Tax due', 'value' => $this->pesoExact($totalDue)],
                ['label' => 'Tax withheld', 'value' => $this->pesoExact($totalWithheld)],
            ],
            'bars' => null,
            'table' => [
                'columns' => ['Employee', 'Department', 'Payslips', 'Taxable pay', 'Tax due', 'Tax withheld', 'Difference', 'Status'],
                'rows' => $rows,
            ],
            'years' => $this->reconciliationYears(),
            'selected_year' => $year,
            'note' => $this->reconciliationNote(count($rows), $short, $over, $unsettled, $year),
        ];
    }

    /** Years that actually have completed regular payroll, newest first. */
    private function reconciliationYears(): array
    {
        return DB::table('hr_payroll_runs')
            ->whereNull('deleted_at')
            ->where('type', 'regular')
            ->where('status', 'completed')
            ->selectRaw('DISTINCT YEAR(cycle_start) AS y')
            ->orderByDesc('y')
            ->pluck('y')
            ->map(fn ($y) => (int) $y)
            ->all();
    }

    /** What the reader should do about it, in one sentence. */
    private function reconciliationNote(int $count, int $short, int $over, int $unsettled, int $year): string
    {
        if ($count === 0) {
            return "No completed regular payroll in {$year} — there is nothing to reconcile.";
        }

        // Nobody settled ⇒ the year has not been closed yet. The differences below are the
        // projection doing its job, not a fault.
        if ($unsettled === $count) {
            return "{$year} has not been closed yet — no payslip has carried the year-end settlement, "
                . 'so these differences are the ordinary effect of withholding against a projected '
                . "year. Run the cycle that contains 31 December {$year}, then read this again.";
        }

        if ($short === 0 && $over === 0) {
            return "Every employee balances: the tax withheld equals the tax due on the year's actual pay.";
        }

        $parts = [];
        if ($short > 0) {
            $parts[] = "{$short} under-withheld";
        }
        if ($over > 0) {
            $parts[] = "{$over} over-withheld";
        }

        $note = implode(' and ', $parts)
            . '. A gap after the year is closed usually means the settlement did not reach them — a '
            . 'payslip already disbursed is never recomputed, and a locked run cannot be re-run. '
            . 'Check December for those employees before filing.';

        // The sharpest signal in the report: the year IS closed for some people and not for these.
        // That is the shape every silent failure took — a whole pay group missed, one employee's
        // December disbursed before the correction, a run locked early.
        return $unsettled > 0
            ? $note . " {$unsettled} never carried a settlement at all — start there."
            : $note;
    }

    private function payrollRegister(array $filters = []): array
    {
        $deptId = $filters['department'] ?? null;

        $run = isset($filters['run'])
            ? PayrollRun::find($filters['run'])
            : PayrollRun::where('status', 'completed')->orderByDesc('cycle_start')->orderByDesc('id')->first();

        $departments = DB::table('hr_departments')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
            ->all();

        if (! $run) {
            return [
                'title' => 'Payroll Register',
                'description' => 'Per-employee gross-to-net for one run, with department subtotals and a comparison against the previous period.',
                'mock' => false,
                'summary' => [['label' => 'Completed runs', 'value' => '0']],
                'bars' => null,
                'table' => null,
                'departments' => $departments,
                'runs' => [],
                'note' => 'No completed payroll run yet — there is nothing to review.',
            ];
        }

        $slips = $run->payslips()
            ->with(['employee.user', 'employee.department'])
            ->when($deptId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $deptId)))
            ->get()
            ->sortBy(fn (Payslip $p) => [$p->employee?->department?->name ?? 'zzz', $p->employee?->user?->name ?? '']);

        // --- the register rows, grouped by department with a subtotal after each ------------------
        $rows = [];
        $running = $this->blankTotals();

        foreach ($slips->groupBy(fn (Payslip $p) => $p->employee?->department?->name ?? 'Unassigned') as $department => $group) {
            $subtotal = $this->blankTotals();

            foreach ($group as $slip) {
                $line = $this->registerLine($slip);
                $rows[] = array_merge([$slip->employee?->user?->name ?? '—', $department], $line['display']);

                foreach ($line['figures'] as $key => $value) {
                    $subtotal[$key] = round($subtotal[$key] + $value, 2);
                    $running[$key] = round($running[$key] + $value, 2);
                }
            }

            // A subtotal row rather than a separate table: it belongs where the eye already is, in
            // the sequence it summarises. Marked with an em dash so it reads as a rule, not a person.
            $rows[] = array_merge(["— {$department} subtotal —", (string) $group->count() . ' staff'], array_map(
                fn (float $v) => $this->pesoExact($v),
                array_values($subtotal),
            ));
        }

        // --- variance against the previous run of the SAME pay group -----------------------------
        $previous = PayrollRun::where('status', 'completed')
            ->where('frequency', $run->frequency)
            ->where('type', $run->type)
            ->where('cycle_start', '<', $run->cycle_start)
            ->orderByDesc('cycle_start')
            ->orderByDesc('id')
            ->first();

        $variance = null;
        if ($previous) {
            $prior = $this->blankTotals();
            foreach ($previous->payslips()->with('employee')->when(
                $deptId,
                fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $deptId)),
            )->get() as $slip) {
                foreach ($this->registerLine($slip)['figures'] as $key => $value) {
                    $prior[$key] = round($prior[$key] + $value, 2);
                }
            }

            $variance = [
                'title' => 'Versus ' . $previous->cycle_start->format('M j') . '–' . $previous->cycle_end->format('M j, Y'),
                'columns' => ['Line', 'This run', 'Previous', 'Change', '%'],
                'rows' => array_map(function (string $label, string $key) use ($running, $prior) {
                    $now = $running[$key];
                    $was = $prior[$key];
                    $delta = round($now - $was, 2);

                    return [
                        $label,
                        $this->pesoExact($now),
                        $this->pesoExact($was),
                        ($delta > 0 ? '+' : '') . $this->pesoExact($delta),
                        // No percentage against a zero base — "+∞%" is not information.
                        $was == 0.0 ? '—' : ($delta >= 0 ? '+' : '') . number_format($delta / $was * 100, 1) . '%',
                    ];
                }, array_values($this->registerColumns()), array_keys($this->registerColumns())),
            ];
        }

        // --- reconciliation ----------------------------------------------------------------------
        // The finding's validation: these must match the run's own stored totals to the centavo. When a
        // department filter is on they legitimately will not, so the note says which is being shown
        // rather than letting the reader think the register disagrees with payroll.
        $reconciles = $deptId === null
            && abs($running['gross'] - (float) $run->total_gross) < 0.01
            && abs($running['net'] - (float) $run->total_net) < 0.01;

        return [
            'title' => 'Payroll Register',
            'description' => 'Per-employee gross-to-net for one run, with department subtotals and a comparison against the previous period.',
            'mock' => false,
            'summary' => [
                ['label' => 'Gross', 'value' => $this->pesoExact($running['gross'])],
                ['label' => 'Deductions', 'value' => $this->pesoExact(round($running['gross'] - $running['net'], 2))],
                ['label' => 'Net pay', 'value' => $this->pesoExact($running['net'])],
                ['label' => 'Employees', 'value' => (string) $slips->count()],
            ],
            'bars' => null,
            'table' => [
                'columns' => array_merge(['Employee', 'Department'], array_values($this->registerColumns())),
                'rows' => $rows,
            ],
            'variance' => $variance,
            'departments' => $departments,
            // So the UI can offer a run picker rather than only ever showing the latest.
            'runs' => PayrollRun::where('status', 'completed')
                ->orderByDesc('cycle_start')
                ->orderByDesc('id')
                ->limit(24)
                ->get()
                ->map(fn (PayrollRun $r) => [
                    'id' => $r->id,
                    'label' => $r->cycle_start->format('M j') . '–' . $r->cycle_end->format('M j, Y')
                        . ' · ' . ucwords(str_replace('_', '-', (string) $r->frequency)),
                ])->all(),
            'selected_run' => [
                'id' => $run->id,
                'cycle' => $run->cycle_start->format('M j') . '–' . $run->cycle_end->format('M j, Y'),
                'status' => $run->status,
                'is_locked' => $run->locked_at !== null,
            ],
            'note' => $deptId !== null
                ? 'Showing one department only, so these totals are a subset of the run — they are not expected to match the run’s own figures.'
                : ($reconciles
                    ? 'Totals reconcile to the run’s recorded gross and net exactly.'
                    : 'These totals DO NOT match the run’s recorded gross and net. The payslips and the run header disagree — do not disburse until that is explained.'),
        ];
    }

    /**
     * The register's money columns, in order. One list, so the header, each row, every subtotal and the
     * variance table cannot drift out of alignment with one another.
     *
     * @return array<string, string>
     */
    private function registerColumns(): array
    {
        return [
            'basic' => 'Basic',
            'allowances' => 'Allowances',
            'premiums' => 'OT & premiums',
            'other_earnings' => 'Other earnings',
            'gross' => 'Gross',
            'statutory' => 'SSS/PH/PI',
            'tax' => 'Tax',
            'other_deductions' => 'Other',
            'net' => 'Net pay',
        ];
    }

    /** @return array<string, float> */
    private function blankTotals(): array
    {
        return array_map(fn () => 0.0, $this->registerColumns());
    }

    /**
     * One payslip as register figures plus their display strings.
     *
     * @return array{figures: array<string, float>, display: list<string>}
     */
    private function registerLine(Payslip $slip): array
    {
        $premiums = round(
            (float) $slip->overtime_pay + (float) $slip->night_diff_pay
                + (float) $slip->holiday_premium + (float) $slip->rest_day_premium,
            2,
        );

        $otherEarnings = round(
            (float) $slip->thirteenth_month + (float) $slip->adjustment_pay
                + (float) ($slip->separation_pay ?? 0) + (float) ($slip->leave_conversion ?? 0)
                + (float) $slip->reimbursements,
            2,
        );

        $figures = [
            'basic' => (float) $slip->base_salary,
            'allowances' => (float) $slip->allowances,
            'premiums' => $premiums,
            'other_earnings' => $otherEarnings,
            'gross' => (float) $slip->gross,
            'statutory' => round((float) $slip->sss + (float) $slip->philhealth + (float) $slip->pagibig, 2),
            'tax' => (float) $slip->withholding_tax,
            'other_deductions' => (float) $slip->other_deductions,
            'net' => (float) $slip->net_pay,
        ];

        return [
            'figures' => $figures,
            'display' => array_map(fn (float $v) => $this->pesoExact($v), array_values($figures)),
        ];
    }

    /**
     * Payroll & cost — now filterable by period, department and pay group.
     *
     * @param  array<string, mixed>  $filters
     */
    private function payroll(array $filters = []): array
    {
        $deptId = $filters['department'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $frequency = $filters['frequency'] ?? null;
        $ranged = $from !== null || $to !== null;

        $runs = PayrollRun::where('status', 'completed')
            ->when($from, fn ($q) => $q->whereDate('cycle_end', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('cycle_start', '<=', $to))
            ->when($frequency, fn ($q) => $q->where('frequency', $frequency))
            ->orderByDesc('cycle_start')
            ->get();

        // Payslip-level aggregation, so a department filter is expressible at all — and so the same
        // query shape answers both the ranged and the YTD case.
        $scopedSlips = fn () => Payslip::query()
            ->join('hr_payroll_runs', 'hr_payslips.payroll_run_id', '=', 'hr_payroll_runs.id')
            ->join('hr_employees', 'hr_payslips.employee_id', '=', 'hr_employees.id')
            ->where('hr_payroll_runs.status', 'completed')
            ->whereIn('hr_payroll_runs.id', $runs->pluck('id'))
            ->when($deptId, fn ($q) => $q->where('hr_employees.department_id', $deptId));

        // Unfiltered, this is the run headers summed exactly as before. Filtered, it has to come from
        // the payslips — the run header cannot be split by department.
        $netYtd = ($deptId === null && ! $ranged && ! $frequency)
            ? (float) $runs->filter(fn ($r) => (int) $r->cycle_start->format('Y') === $this->year())->sum('total_net')
            : (float) $scopedSlips()->sum('hr_payslips.net_pay');

        // This figure used to be labelled "Net paid (YTD)" while summing COMPLETED RUNS.
        // A run reaches `completed` the moment payroll finishes computing it; whether the money
        // ever left is a separate fact entirely. So the headline number management reads first
        // asserted cash movement that may not have happened, and a computed-but-unpaid cycle
        // inflated it by a full period with nothing on the report to say so.
        $disbursedYtd = (float) ($deptId === null && ! $ranged && ! $frequency
            ? Payslip::query()
                ->join('hr_payroll_runs', 'hr_payslips.payroll_run_id', '=', 'hr_payroll_runs.id')
                ->where('hr_payslips.status', 'disbursed')
                ->whereYear('hr_payroll_runs.cycle_start', $this->year())
                ->sum('hr_payslips.net_pay')
            : $scopedSlips()->where('hr_payslips.status', 'disbursed')->sum('hr_payslips.net_pay'));

        $scopeLabel = $ranged || $frequency || $deptId !== null ? '(filtered)' : '(YTD)';
        $awaitingPayment = round($netYtd - $disbursedYtd, 2);
        $latest = $runs->first();
        $costPerHead = $latest && $latest->employee_count > 0
            ? (float) $latest->total_net / $latest->employee_count
            : 0;

        // Government remittance (YTD): employee vs employer contribution per agency,
        // summed over this year's completed runs' payslips.
        $agg = ($deptId === null && ! $ranged && ! $frequency
            ? Payslip::query()
                ->join('hr_payroll_runs', 'hr_payslips.payroll_run_id', '=', 'hr_payroll_runs.id')
                ->where('hr_payroll_runs.status', 'completed')
                ->whereYear('hr_payroll_runs.cycle_start', $this->year())
            : $scopedSlips())
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
                // "Computed" and "disbursed" are different claims — see above.
                // The label states its own scope. A figure whose label lies about what it
                // covers is exactly how happened.
                ['label' => 'Net computed ' . $scopeLabel, 'value' => $this->peso($netYtd)],
                ['label' => 'Net disbursed ' . $scopeLabel, 'value' => $this->peso($disbursedYtd)],
                ['label' => 'Awaiting payment', 'value' => $this->peso($awaitingPayment)],
                ['label' => 'Employer share ' . $scopeLabel, 'value' => $this->peso($employerYtd)],
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
                    'value' => (int) $this->runNet($r, $deptId),
                    'display' => $this->peso($this->runNet($r, $deptId)),
                ])->all(),
            ],
            'table' => [
                'columns' => ['Cycle', 'Pay group', 'Gross', 'Deductions', 'Net'],
                'rows' => $runs->map(fn ($r) => [
                    $r->cycle_start->format('M j') . ' – ' . $r->cycle_end->format('M j'),
                    ucwords(str_replace('_', '-', (string) $r->frequency)),
                    ...$this->runTotals($r, $deptId),
                ])->all(),
            ],
            'departments' => DB::table('hr_departments')
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($d) => ['id' => (int) $d->id, 'name' => $d->name])
                ->all(),
            // The pay groups that actually have completed runs — offering one with no data is a
            // filter that silently returns nothing.
            'pay_groups' => PayrollRun::where('status', 'completed')
                ->distinct()
                ->orderBy('frequency')
                ->pluck('frequency')
                ->filter()
                ->values()
                ->all(),
            'note' => $deptId !== null
                ? 'Filtered to one department, so every figure is computed from payslips rather than the run headers — a run’s recorded total is company-wide and cannot be split.'
                : null,
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
        // An explicit range wins over the rolling `months` window. Both are kept: `months` is
        // what the chart has always used, and a date range is what reconciling a specific period needs.
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $frequency = $filters['frequency'] ?? null;
        $ranged = $from !== null || $to !== null;
        $since = $from
            ? Carbon::parse($from, config('app.timezone_local'))->startOfDay()
            : now(config('app.timezone_local'))->startOfMonth()->subMonths(max(0, $months - 1));

        // Contribution rates for the legend labels — from the DATED VERSION IN FORCE, not the legacy `hr_contribution_settings.config`.
        $versions = $this->contributions->versionsAsOf(now(config('app.timezone_local'))->toDateString());
        $rate = function (string $type) use ($versions): string {
            $version = $versions->get($type);
            if ($version === null) {
                return '';
            }

            $pct = (float) $version->employee_rate * 100;

            return ' ' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%';
        };

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
            ->when($to, fn ($q) => $q->whereDate('cycle_start', '<=', $to))
            ->when($frequency, fn ($q) => $q->where('frequency', $frequency))
            ->orderBy('cycle_start')
            ->get();

        $scopeLabel = $ranged || $frequency || $deptId !== null ? '(filtered)' : '(YTD)';

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
            $enrolled = EmployeeBenefit::where('contribution_type', $type)
                ->where('enrolled', true)
                ->whereIn('employee_id', Employee::query()->select('id'))
                ->count();
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
                // "computed", not "paid". A completed run means payroll finished
                // calculating; it says nothing about whether the money left. See payroll() above.
                // These follow the filter, and the LABEL says which scope it is showing.
                // A figure labelled YTD while a range is applied is the mistake again.
                ['label' => 'Gross computed ' . $scopeLabel, 'value' => $this->peso($this->compensationTotal($runs, 'total_gross', $deptId, $ranged || $frequency))],
                ['label' => 'Net computed ' . $scopeLabel, 'value' => $this->peso($this->compensationTotal($runs, 'total_net', $deptId, $ranged || $frequency))],
                ['label' => 'Benefits coverage', 'value' => count($coverage) ? ($coverage[0]['display']) : '—'],
            ],
            'bars' => null,
            'table' => null,
            'stacked' => ['series' => $series, 'points' => $points],
            // The pay groups with completed runs, so the filter only offers real options.
            'pay_groups' => PayrollRun::where('status', 'completed')
                ->distinct()
                ->orderBy('frequency')
                ->pluck('frequency')
                ->filter()
                ->values()
                ->all(),
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
    private function audit(array $filters): array
    {
        $months = (int) ($filters['months'] ?? 9);
        $since = now(config('app.timezone_local'))->startOfMonth()->subMonths(max(0, $months - 1));

        // PAGINATED, and it states the total.
        $query = AuditLog::with('user')->where('created_at', '>=', $since)->latest();

        $total = $query->clone()->count();
        $exportAll = (bool) ($filters['all'] ?? false);
        $perPage = $exportAll ? $total : min(500, max(1, (int) ($filters['per_page'] ?? 100)));
        $page = $exportAll ? 1 : max(1, (int) ($filters['page'] ?? 1));

        $entries = $perPage > 0
            ? $query->forPage($page, $perPage)->get()
            : collect();

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
            // So the screen can page, and so "showing 100 of 4,312" is sayable. The
            // absence of this is what made the old cap silent.
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                'returned' => $entries->count(),
            ],
            'note' => null,
        ];
    }
}
