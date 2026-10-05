<?php

namespace App\Modules\Crm\Jobs;

use App\Models\User;
use App\Modules\Crm\Services\Discovery\NerbyDatasetImporter;
use App\Modules\Crm\Services\Discovery\NerbyDatasetImportJobStore;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The WRITE half of Settings → NERBY → Dataset Import, run on the queue.
 *
 * A thin wrapper: everything that decides what lands — validation, the source filter, the exact
 * place_id skip (soft-deleted included), the SHA-256 binding, the org lock — is
 * {@see NerbyDatasetImporter::run()}, the same engine the operator command uses. This job only
 * supplies who and where:
 *
 *  - `created_by` is the NERBY service account, as for every lead discovery lands;
 *  - the admin who pressed Import is recorded in the manifest as `requested_by`, never on the leads;
 *  - the org is the one the request was made in, captured at queue time — a queued job has no user,
 *    and guessing a tenant is the bug CurrentOrg exists to stop.
 *
 * ONE attempt. A retry would be safe (existing place_ids are skipped) but would turn a failure an
 * admin should look at into a silent second run; the screen reports the failure instead.
 */
class ImportNerbyDatasetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    /** @param  list<string>  $sources */
    public function __construct(
        public readonly string $jobId,
        public readonly string $path,
        public readonly int $orgId,
        public readonly array $sources,
        public readonly string $confirmSha256,
        public readonly int $requestedBy,
    ) {}

    public function handle(NerbyDatasetImporter $importer, NerbyDatasetImportJobStore $store, NerbyServiceIdentity $identity): void
    {
        $store->markRunning($this->jobId);

        try {
            $manifest = $importer->run(
                $this->path,
                $this->orgId,
                $this->sources,
                NerbyDatasetImporter::AI_ANY,
                $this->confirmSha256,
                (int) $identity->user()->getKey(),
            );

            $manifest = self::withRequester($manifest, $this->requestedBy, $store->get($this->jobId)['original_name'] ?? null);
            $importer->storeManifest($manifest);
            $store->finish($this->jobId, $manifest);
        } catch (Throwable $e) {
            $store->fail($this->jobId, 'The import could not be completed. Nothing further was written; see the server log.');

            throw $e; // let the queue record the real failure for operators
        }
    }

    public function failed(?Throwable $e): void
    {
        $store = app(NerbyDatasetImportJobStore::class);
        if (($store->get($this->jobId)['status'] ?? null) !== NerbyDatasetImportJobStore::STATUS_FAILED) {
            $store->fail($this->jobId, 'The import could not be completed. See the server log.');
        }
    }

    /**
     * Stamp the audit facts only the Settings path knows: who asked, through which door, and the file
     * name they chose (the importer only ever sees the parked upload's own name).
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public static function withRequester(array $manifest, int $userId, ?string $originalName): array
    {
        $user = User::query()->find($userId);

        $manifest['requested_by'] = [
            'id' => $userId,
            'name' => $user?->name,
            'email' => $user?->email,
        ];
        $manifest['via'] = 'settings';
        if ($originalName !== null) {
            $manifest['input']['original_name'] = $originalName;
        }

        return $manifest;
    }
}
