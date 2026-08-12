<?php

namespace App\Modules\Crm\Jobs;

use App\Modules\Crm\Services\Import\XlsConverter;
use App\Modules\Crm\Services\Import\XlsImportJobStore;
use App\Modules\Crm\Services\LeadImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Converts a large legacy `.xls` off the web request, then runs the normal dry-run against it.
 *
 * WHY THIS EXISTS. Conversion memory scales with CELL COUNT (~1 KB per cell), so a 10 MB BIFF file
 * (~700K cells) needs roughly 750 MB — six times a production FPM worker's 128 MB limit — and takes
 * long enough to exceed both `max_execution_time` (30s) and the nginx/Cloudflare edge timeouts that
 * application code cannot raise. Files above {@see XlsConverter::SYNC_CELL_THRESHOLD} are therefore
 * routed here, where the CLI worker has no execution-time ceiling and its own memory budget.
 *
 * Small files never reach this job: they convert inline in a couple of hundred milliseconds and keep
 * the existing synchronous preview exactly as it is.
 *
 * The generated `.xlsx` is kept under the job id so the eventual COMMIT reuses it instead of
 * re-uploading and re-converting the same workbook. {@see XlsImportJobStore} owns that record's
 * lifecycle, ownership and cleanup.
 */
class ConvertXlsImportJob implements ShouldQueue
{
    use Queueable;

    /** Conversion is CPU-bound and deterministic — a retry re-runs identical work, so keep it low. */
    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        private readonly string $jobId,
        private readonly string $sourcePath,
        private readonly string $originalName,
        private readonly int $userId,
        private readonly ?int $assignedTo,
    ) {}

    public function handle(
        XlsConverter $converter,
        XlsImportJobStore $store,
        LeadImportService $importer,
    ): void {
        try {
            $target = $store->storagePathFor($this->jobId);
            $converter->convert($this->sourcePath, $target);

            // The SAME dry-run the synchronous path runs — identical validation, aliasing,
            // translation and deduplication. Only the file's provenance differs.
            $preview = $importer->import(
                $this->uploadedStub($target),
                $this->userId,
                dryRun: true,
                assignedTo: $this->assignedTo,
                preConvertedPath: $target,
            );

            $store->markReady($this->jobId, $target, $preview);
        } catch (ValidationException $e) {
            $store->markFailed($this->jobId, $this->firstMessage($e));
        } catch (Throwable $e) {
            $store->markFailed(
                $this->jobId,
                'The Excel file could not be processed. Please re-save it as .xlsx and try again.',
            );

            throw $e; // let the queue record the real failure for operators
        } finally {
            // The uploaded original is ours once it has been queued; the converted copy is what the
            // commit will use, so the source is never needed again.
            if (is_file($this->sourcePath)) {
                @unlink($this->sourcePath);
            }
        }
    }

    /** The queue failed terminally (retries exhausted) — surface it to the poller. */
    public function failed(?Throwable $e): void
    {
        app(XlsImportJobStore::class)->markFailed(
            $this->jobId,
            'The Excel file could not be processed. Please re-save it as .xlsx and try again.',
        );
    }

    /**
     * `LeadImportService::import()` types its first parameter as an UploadedFile for the request
     * path. The queued path already has a real file on disk and passes `preConvertedPath`, so this
     * stub exists only to satisfy the signature — its contents are never read.
     */
    private function uploadedStub(string $path): UploadedFile
    {
        return new UploadedFile($path, $this->originalName, null, null, true);
    }

    private function firstMessage(ValidationException $e): string
    {
        $messages = $e->validator->errors()->all();

        return $messages[0] ?? 'The Excel file could not be processed.';
    }
}
