<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Jobs\ImportNerbyDatasetJob;
use App\Modules\Crm\Requests\DryRunNerbyDatasetRequest;
use App\Modules\Crm\Requests\ImportNerbyDatasetRequest;
use App\Modules\Crm\Resources\NerbyDatasetImportJobResource;
use App\Modules\Crm\Resources\NerbyDatasetImportResource;
use App\Modules\Crm\Services\Discovery\NerbyDatasetImporter;
use App\Modules\Crm\Services\Discovery\NerbyDatasetImportJobStore;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Settings → NERBY → Dataset Import — a controlled wrapper around {@see NerbyDatasetImporter}, the
 * same engine `crm:nerby-import` uses. Nothing here decides what lands; it supplies the org (the
 * caller's own), the file, and the confirmation.
 *
 * - `dryRun`  validates the whole upload and reports what WOULD happen. Writes no lead.
 * - `store`   the explicit confirmation: the same file plus the SHA-256 the dry run reported. Parks the
 *             upload and queues {@see ImportNerbyDatasetJob}; answers 202 with a job id.
 * - `show`    the job's state and, once finished, its manifest. Foreign ids are a plain 404.
 *
 * No provider is ever called: the uploaded file is the only input.
 */
class NerbyDatasetImportController extends Controller
{
    public function __construct(private readonly NerbyDatasetImporter $importer) {}

    public function dryRun(DryRunNerbyDatasetRequest $request): JsonResponse
    {
        $orgId = $this->orgId();
        $file = $request->file('file');

        $manifest = $this->importer->run(
            (string) $file->getRealPath(),
            $orgId,
            [(string) $request->validated('source')],
            NerbyDatasetImporter::AI_ANY,
            null,
            null,
        );
        $manifest = ImportNerbyDatasetJob::withRequester($manifest, (int) $request->user()->id, $file->getClientOriginalName());
        $this->importer->storeManifest($manifest);

        return (new NerbyDatasetImportResource($manifest))->response();
    }

    public function store(ImportNerbyDatasetRequest $request, NerbyDatasetImportJobStore $jobs): JsonResponse
    {
        $orgId = $this->orgId();
        $file = $request->file('file');
        $confirm = (string) $request->validated('confirm_sha256');

        // Refused before anything is parked or queued. The importer checks this again against the bytes
        // it actually reads, so this is the fast answer, not the only guard.
        if (! hash_equals((string) hash_file('sha256', (string) $file->getRealPath()), $confirm)) {
            throw ValidationException::withMessages([
                'confirm_sha256' => 'This file is not the one that was reviewed. Run the dry run again.',
            ]);
        }

        $parked = Storage::disk('local')->putFileAs('nerby-imports/uploads', $file, Str::uuid()->toString().'.jsonl');
        $path = Storage::disk('local')->path((string) $parked);
        $userId = (int) $request->user()->id;

        $jobId = $jobs->create($userId, $orgId, $file->getClientOriginalName(), $confirm, $path);

        ImportNerbyDatasetJob::dispatch(
            $jobId,
            $path,
            $orgId,
            [(string) $request->validated('source')],
            $confirm,
            $userId,
        );

        return (new NerbyDatasetImportJobResource($jobs->get($jobId) ?? []))->response()->setStatusCode(202);
    }

    public function show(Request $request, string $jobId, NerbyDatasetImportJobStore $jobs): JsonResponse
    {
        $record = $jobs->forUser($jobId, (int) $request->user()->id, CurrentOrg::id());
        abort_if($record === null, 404);

        return (new NerbyDatasetImportJobResource($record))->response();
    }

    /** The caller's own org — the only place this screen can import into. */
    private function orgId(): int
    {
        $orgId = CurrentOrg::id();
        abort_if($orgId === null, 403, 'Your account is not attached to an organization.');

        return $orgId;
    }
}
