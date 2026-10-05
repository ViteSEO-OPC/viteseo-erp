<?php

namespace App\Modules\Crm\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The polled state of one queued dataset import: `queued` → `running` → `finished` | `failed`.
 *
 * `finished` means the job is over, not that rows were written — the manifest's own `status` says
 * whether the import ran (`ok`) or was refused (`refused`, e.g. the file changed after review).
 *
 * @property array<string, mixed> $resource
 */
class NerbyDatasetImportJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'job_id' => $r['job_id'],
            'status' => $r['status'],
            'error' => $r['error'],
            'file_name' => $r['original_name'],
            'sha256' => $r['sha256'],
            'created_at' => $r['created_at'],
            'manifest' => is_array($r['manifest']) ? new NerbyDatasetImportResource($r['manifest']) : null,
        ];
    }
}
