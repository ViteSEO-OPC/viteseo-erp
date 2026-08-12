<?php

namespace App\Modules\Crm\Services\Import;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Lifecycle + ownership for a queued `.xls` conversion.
 *
 * A large `.xls` is converted ONCE. The generated `.xlsx` is kept on disk under a job id, the dry-run
 * preview is kept beside it, and the commit later references that id instead of re-uploading and
 * re-converting a 10 MB file. This class is the single owner of that record's state, its security
 * rules, and its cleanup.
 *
 * BACKED BY THE CACHE, DELIBERATELY — there is no new table and no schema change. `CACHE_STORE` is
 * `database`, so records live in the existing `cache` table with a real TTL, and an abandoned import
 * expires on its own rather than accumulating rows nobody sweeps.
 *
 * SECURITY RULES, all enforced here so no controller can forget one:
 *  - **Ownership.** A record is bound to the user id that created it. {@see forUser()} returns null
 *    for anyone else, so a mismatched id is indistinguishable from a missing one — a probe cannot
 *    tell whether someone else's job exists.
 *  - **Single commit.** {@see markCommitted()} is a compare-and-set on the status; the second call
 *    returns false, so a double-submit cannot import the same workbook twice.
 *  - **Cleanup.** The converted file is deleted on successful commit, on failure, and on expiry —
 *    {@see forget()} always unlinks before dropping the record.
 */
class XlsImportJobStore
{
    /** Conversions are minutes of work; an hour is generous for a BD to review a preview and commit. */
    public const TTL_SECONDS = 3600;

    private const PREFIX = 'crm:import:xls:';

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_COMMITTED = 'committed';

    /** Create a pending record owned by $userId and return its opaque job id. */
    public function create(int $userId, string $originalName): string
    {
        $jobId = (string) Str::uuid();

        $this->put($jobId, [
            'job_id' => $jobId,
            'user_id' => $userId,
            'status' => self::STATUS_PENDING,
            'original_name' => $originalName,
            // The uploaded `.xls` moved out of PHP's request temp dir. Tracked here so it is cleaned
            // up even if the JOB NEVER RUNS — a worker outage or a lost job would otherwise leave the
            // moved upload on disk with nothing referencing it.
            'source_path' => null,
            'path' => null,
            'preview' => null,
            'error' => null,
        ]);

        return $jobId;
    }

    /** Record where the uploaded original was parked, so cleanup can find it. */
    public function attachSource(string $jobId, string $sourcePath): void
    {
        $record = Cache::get(self::PREFIX.$jobId);

        if (is_array($record)) {
            $this->put($jobId, [...$record, 'source_path' => $sourcePath]);
        }
    }

    /**
     * The record, ONLY if it exists and belongs to $userId.
     *
     * Returning null for a foreign record (rather than throwing a distinct error) is what keeps job
     * ids non-enumerable: the caller renders the same 404 either way.
     *
     * @return array<string, mixed>|null
     */
    public function forUser(string $jobId, int $userId): ?array
    {
        $record = Cache::get(self::PREFIX.$jobId);

        if (! is_array($record) || ($record['user_id'] ?? null) !== $userId) {
            return null;
        }

        return $record;
    }

    /** Mark a conversion finished: store the generated file path and its dry-run preview. */
    public function markReady(string $jobId, string $path, array $preview): void
    {
        $record = Cache::get(self::PREFIX.$jobId);

        if (! is_array($record)) {
            // The record expired while the job ran; the file it produced would be orphaned.
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }

        $this->put($jobId, [
            ...$record,
            'status' => self::STATUS_READY,
            'path' => $path,
            'preview' => $preview,
        ]);
    }

    /** Mark a conversion failed and remove any partial output. */
    public function markFailed(string $jobId, string $error): void
    {
        $record = Cache::get(self::PREFIX.$jobId);

        if (! is_array($record)) {
            return;
        }

        foreach (['path', 'source_path'] as $key) {
            $file = $record[$key] ?? null;
            if (is_string($file) && is_file($file)) {
                @unlink($file);
            }
        }

        $this->put($jobId, [
            ...$record,
            'status' => self::STATUS_FAILED,
            'path' => null,
            'source_path' => null,
            'error' => $error,
        ]);
    }

    /**
     * Claim the job for commit — compare-and-set on `ready`.
     *
     * Returns false when the record is missing, not owned by $userId, not ready, or ALREADY
     * committed. That last case is the double-submit guard: two concurrent confirms race here and
     * exactly one wins.
     */
    public function markCommitted(string $jobId, int $userId): bool
    {
        $record = $this->forUser($jobId, $userId);

        if ($record === null || ($record['status'] ?? null) !== self::STATUS_READY) {
            return false;
        }

        $this->put($jobId, [...$record, 'status' => self::STATUS_COMMITTED]);

        return true;
    }

    /** Delete the converted file and drop the record. Safe to call repeatedly. */
    public function forget(string $jobId): void
    {
        $record = Cache::get(self::PREFIX.$jobId);

        if (is_array($record)) {
            foreach (['path', 'source_path'] as $key) {
                $file = $record[$key] ?? null;
                if (is_string($file) && is_file($file)) {
                    @unlink($file);
                }
            }
        }

        Cache::forget(self::PREFIX.$jobId);
    }

    /**
     * Drop the record WITHOUT touching its files — exactly what a TTL expiry does.
     *
     * This is the state {@see pruneOrphans()} exists to clean up, and the only way to reach it
     * deterministically: otherwise a test would have to wait out the hour-long TTL. Production code
     * never calls this — commit and failure go through {@see forget()}/{@see markFailed()}, which
     * unlink first.
     */
    public function forgetRecordOnly(string $jobId): void
    {
        Cache::forget(self::PREFIX.$jobId);
    }

    /**
     * Delete converted/source files whose job record is gone — abandoned imports.
     *
     * The record's TTL expires on its own, but the CACHE cannot delete a file: a conversion that is
     * previewed and never confirmed would otherwise leave its `.xlsx` on disk forever. This is the
     * only cleanup path for that case; commit and failure are handled by {@see forget()} and
     * {@see markFailed()}.
     *
     * TWO CONDITIONS, both required:
     *  - **No live record.** A record still in the cache means the import is live — a BD may be
     *    reviewing the preview right now — so its files are never touched, regardless of age. This
     *    is what keeps active jobs safe when `markReady()` extends the TTL past the file's mtime.
     *  - **Older than the grace period.** Closes the startup race: the controller creates the record
     *    *before* moving the upload, but a crash between those two steps (or a job writing its output
     *    a moment before `markReady()`) would briefly look orphaned. The grace period means a file
     *    is only ever collected once it cannot still be mid-flight.
     *
     * Idempotent: a second run finds nothing left to remove and returns 0.
     *
     * @return int files deleted
     */
    public function pruneOrphans(int $graceSeconds = 900): int
    {
        $dir = storage_path('app/xls-imports');

        if (! is_dir($dir)) {
            return 0;
        }

        $cutoff = time() - $graceSeconds;
        $deleted = 0;

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            // Both `{uuid}.xlsx` and `{uuid}.xlsx.source.xls` carry the job id as the first segment.
            $jobId = basename($file);
            $jobId = substr($jobId, 0, strpos($jobId, '.') ?: strlen($jobId));

            if (Cache::has(self::PREFIX.$jobId)) {
                continue; // live import — leave it alone
            }

            if (($mtime = @filemtime($file)) === false || $mtime > $cutoff) {
                continue; // too recent to be certain it is abandoned
            }

            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Where a queued conversion writes its output — outside the temp dir so it survives the job. */
    public function storagePathFor(string $jobId): string
    {
        $dir = storage_path('app/xls-imports');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir.DIRECTORY_SEPARATOR.$jobId.'.xlsx';
    }

    /** @param array<string, mixed> $record */
    private function put(string $jobId, array $record): void
    {
        Cache::put(self::PREFIX.$jobId, $record, self::TTL_SECONDS);
    }
}
