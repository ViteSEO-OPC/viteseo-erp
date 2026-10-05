<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Jobs\ImportNerbyDatasetJob;
use App\Modules\Crm\Services\Import\XlsImportJobStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Lifecycle + ownership of one queued NERBY dataset import started from Settings.
 *
 * The write runs on the queue ({@see ImportNerbyDatasetJob}) because landing
 * thousands of leads outlives a web request; the screen polls this record for the outcome.
 *
 * BACKED BY THE CACHE, like {@see XlsImportJobStore}: no table, no
 * migration, and a record nobody polls simply expires. The AUDIT RECORD is not this — it is the
 * manifest file the job writes ({@see NerbyDatasetImporter::storeManifest()}), which outlives it.
 *
 * SECURITY RULES, enforced here so no controller can forget one:
 *  - **Ownership.** A record belongs to the user AND org that started it. {@see forUser()} returns
 *    null for anyone else, so a foreign job id is indistinguishable from a missing one.
 *  - **Cleanup.** The parked upload is deleted when the job finishes or fails ({@see finish()},
 *    {@see fail()}), and the record carries its path so an operator can find a stranded one.
 */
class NerbyDatasetImportJobStore
{
    /** Long enough for an import to run and be read back; the manifest file is the durable record. */
    public const TTL_SECONDS = 6 * 3600;

    private const PREFIX = 'crm:nerby-dataset-import:';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_FAILED = 'failed';

    /** Create a queued record owned by this user in this org and return its opaque id. */
    public function create(int $userId, int $orgId, string $originalName, string $sha256, string $uploadPath): string
    {
        $jobId = (string) Str::uuid();

        $this->put($jobId, [
            'job_id' => $jobId,
            'user_id' => $userId,
            'org_id' => $orgId,
            'status' => self::STATUS_QUEUED,
            'original_name' => $originalName,
            'sha256' => $sha256,
            'upload_path' => $uploadPath,
            'manifest' => null,
            'error' => null,
            'created_at' => now()->toIso8601String(),
        ]);

        return $jobId;
    }

    /**
     * The record, ONLY if it exists and belongs to this user in this org.
     *
     * @return array<string, mixed>|null
     */
    public function forUser(string $jobId, int $userId, ?int $orgId): ?array
    {
        $record = $this->get($jobId);

        if ($record === null || $record['user_id'] !== $userId || $record['org_id'] !== $orgId) {
            return null;
        }

        return $record;
    }

    /** @return array<string, mixed>|null */
    public function get(string $jobId): ?array
    {
        $record = Cache::get(self::PREFIX.$jobId);

        return is_array($record) ? $record : null;
    }

    public function markRunning(string $jobId): void
    {
        $this->update($jobId, ['status' => self::STATUS_RUNNING]);
    }

    /**
     * The importer ran to an outcome — `ok`, `refused` or `failed` is in the manifest itself; this
     * status only says the job is over and the manifest is ready to read.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function finish(string $jobId, array $manifest): void
    {
        $this->removeUpload($jobId);
        $this->update($jobId, ['status' => self::STATUS_FINISHED, 'manifest' => $manifest, 'upload_path' => null]);
    }

    /** The job itself broke (not a refused import — that still finishes with a manifest). */
    public function fail(string $jobId, string $error): void
    {
        $this->removeUpload($jobId);
        $this->update($jobId, ['status' => self::STATUS_FAILED, 'error' => $error, 'upload_path' => null]);
    }

    private function removeUpload(string $jobId): void
    {
        $path = $this->get($jobId)['upload_path'] ?? null;
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
    }

    /** @param  array<string, mixed>  $changes */
    private function update(string $jobId, array $changes): void
    {
        $record = $this->get($jobId);
        if ($record !== null) {
            $this->put($jobId, [...$record, ...$changes]);
        }
    }

    /** @param  array<string, mixed>  $record */
    private function put(string $jobId, array $record): void
    {
        Cache::put(self::PREFIX.$jobId, $record, self::TTL_SECONDS);
    }
}
