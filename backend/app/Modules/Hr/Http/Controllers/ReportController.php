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
    public function __construct(private readonly ReportService $service) {}

    // GET /api/v1/hr/reports/{key} — one analytics report (HR only).
    public function show(Request $request, string $key): JsonResponse
    {
        $report = $this->service->get($key, $request->only(['action', 'from', 'to', 'department', 'months']));

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

        return $this->service->download($key);
    }
}
