<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectStatusService
{
    /**
     * Create a custom (non-protected) status for the given project.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, array $data): ProjectStatus
    {
        $order = $data['order']
            ?? ((int) $project->statuses()->max('order') + 1);

        return $project->statuses()->create([
            'name' => $data['name'],
            'key' => StatusKey::CUSTOM,
            'color' => $data['color'] ?? null,
            'is_protected' => false,
            'order' => (int) $order,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProjectStatus $status, array $data): ProjectStatus
    {
        $status->update($data);

        return $status;
    }

    /**
     * Delete a custom status, guarding the protected defaults. Any tasks or
     * subtasks still on this status fall back to "no status" (null status_id).
     *
     * @throws ValidationException
     */
    public function delete(ProjectStatus $status): void
    {
        if ($status->is_protected) {
            throw ValidationException::withMessages([
                'status' => 'Protected statuses cannot be deleted.',
            ]);
        }

        DB::transaction(function () use ($status): void {
            // "no status" fallback — orphan the work items before removing the status.
            $status->tasks()->update(['status_id' => null]);
            $status->subtasks()->update(['status_id' => null]);
            $status->delete();
        });
    }
}
