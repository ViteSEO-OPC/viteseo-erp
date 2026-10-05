<?php

namespace App\Modules\Crm\Resources;

use App\Models\Org;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One NERBY dataset-import manifest, as the Settings screen shows it.
 *
 * The full manifest stays on the server as the audit record. This is the reviewable subset: what was
 * read, where it would land, what would happen to each line, and who asked. It deliberately leaves
 * out the server-side file paths, the internal actor id and the ingest bookkeeping.
 *
 * @property array<string, mixed> $resource
 */
class NerbyDatasetImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $m = $this->resource;
        $orgId = (int) $m['target']['org_id'];

        return [
            'mode' => $m['mode'],
            'status' => $m['status'],
            'error' => $m['error'],
            'file' => [
                'name' => $m['input']['original_name'] ?? $m['input']['file'],
                'sha256' => $m['input']['sha256'],
                'bytes' => $m['input']['bytes'],
                'lines' => $m['input']['lines'],
            ],
            'org' => ['id' => $orgId, 'name' => Org::query()->whereKey($orgId)->value('name')],
            'sources' => $m['filters']['sources'],
            'counts' => $m['counts'],
            'reasons' => $m['reasons'],
            'dropped_fields' => (object) $m['dropped_fields'],
            'requested_by' => isset($m['requested_by']) ? [
                'id' => $m['requested_by']['id'],
                'name' => $m['requested_by']['name'],
            ] : null,
            'started_at' => $m['started_at'],
            'finished_at' => $m['finished_at'],
        ];
    }
}
