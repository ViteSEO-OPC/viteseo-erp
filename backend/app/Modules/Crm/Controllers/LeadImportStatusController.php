<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Services\Import\XlsImportJobStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Poll target for a queued `.xls` conversion.
 *
 * The route is gated by the SAME `import` ability as the upload itself, so this adds no new
 * permission surface. Ownership is enforced a second time here, in the store: a job belonging to
 * another user resolves to null and renders as **404, not 403** — a probe therefore cannot
 * distinguish "someone else's job" from "no such job", which is what keeps the ids non-enumerable.
 *
 * Read-only. Committing is still `POST /leads/import` (with `job_id`), so the polling endpoint can
 * never mutate anything.
 */
class LeadImportStatusController extends Controller
{
    public function show(Request $request, string $jobId, XlsImportJobStore $store): JsonResponse
    {
        $record = $store->forUser($jobId, (int) $request->user()->id);

        if ($record === null) {
            return response()->json(['message' => 'Import job not found.'], 404);
        }

        return response()->json([
            'data' => [
                'jobId' => $record['job_id'],
                'status' => $record['status'],
                'originalName' => $record['original_name'],
                // Present only once conversion has finished; this is the SAME dry-run payload the
                // synchronous path returns, so the frontend renders the existing preview unchanged.
                'result' => $record['preview'] ?? null,
                'error' => $record['error'] ?? null,
            ],
        ]);
    }
}
