<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ReportController extends Controller
{
    /**
     * Every filter a report may read. One list, because `show()` and `export()` must accept the
     * same set — a filter honoured on screen and dropped on export produces a file that quietly
     * disagrees with the table it was downloaded from.
     *
     * FIN-041 paginates the audit report; FIN-042/FIN-017 add `run` (which register to show) and
     * `frequency` (the pay group); `month` scopes the attendance reports.
     */
    private const FILTERS = [
        'action', 'from', 'to', 'department', 'months', 'page', 'per_page',
        'run', 'frequency', 'month',
    ];

    public function __construct(private readonly ReportService $service) {}

    // GET /api/v1/hr/reports/{key} — one analytics report (HR only).
    public function show(Request $request, string $key): JsonResponse
    {
        // FIN-041 — `page`/`per_page` let the audit report page instead of silently truncating.
        $report = $this->service->get(
            $key,
            $request->only(self::FILTERS),
        );

        if (! $report) {
            return response()->json(['message' => 'Unknown report.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $report]);
    }

    // POST /api/v1/hr/reports/{key}/export — log the export + stream a downloadable
    // file: CSV for table reports (Audit Trail), PDF for the chart reports.
    public function export(Request $request, string $key): HttpResponse
    {
        if (! in_array($key, ReportService::KEYS, true)) {
            return response()->json(['message' => 'Unknown report.'], Response::HTTP_NOT_FOUND);
        }

        $this->service->logExport($key, $request->user());

        return $this->service->download($key, $request->only(self::FILTERS));
    }
}
