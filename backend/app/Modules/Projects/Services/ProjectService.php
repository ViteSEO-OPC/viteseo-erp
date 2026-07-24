<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\StatusKey;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectStatus;
use App\Modules\Projects\Models\Workspace;
use App\Modules\Projects\Support\PublicId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    public function __construct(
        private readonly GroupProjectReconciler $reconciler,
    ) {}

    /**
     * Paginate projects with optional filtering and sorting.
     *
     * @param  array<string, mixed>  $filters
     * @param  User|null  $scopeToMember  When set, restrict to projects this user is a member of.
     */
    public function paginate(array $filters, ?string $sort, int $perPage, ?User $scopeToMember = null): LengthAwarePaginator
    {
        // Eager-load the workspace so its public UUID can be exposed as `workspace_id`.
        $query = Project::query()
            ->with('workspace')
            ->withCount(['tasks', 'members']);

        // Members only see projects they are assigned to.
        if ($scopeToMember !== null) {
            $query->whereHas('members', fn ($q) => $q->whereKey($scopeToMember->getKey()));
        }

        if (! empty($filters['workspace_id'])) {
            // The client filters by the workspace's public UUID; resolve to the internal id.
            $query->where('workspace_id', PublicId::toInternalId(Workspace::class, $filters['workspace_id']));
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        [$field, $direction] = $this->resolveSort($sort);
        $query->orderBy($field, $direction);

        return $query->paginate($perPage);
    }

    /**
     * Create a project and seed its 3 protected default statuses.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Project
    {
        return DB::transaction(function () use ($data): Project {
            $project = Project::create([
                'workspace_id' => $data['workspace_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            foreach (StatusKey::defaults() as $order => $key) {
                ProjectStatus::create([
                    'project_id' => $project->id,
                    'name' => $key->label(),
                    'key' => $key,
                    'is_protected' => true,
                    'order' => $order,
                ]);
            }

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, array $data): Project
    {
        $project->update($data);

        return $project;
    }

    public function delete(Project $project): void
    {
        $project->delete();
    }

    public function addMember(Project $project, User $user): Project
    {
        // pm_project_members has only created_at (no updated_at) — do NOT set updated_at.
        $project->members()->syncWithoutDetaching([
            $user->getKey() => ['created_at' => now()],
        ]);

        // A newly-complete group roster auto-joins the project (and vice-versa).
        $this->reconciler->syncForProject($project);

        return $project;
    }

    public function removeMember(Project $project, User $user): Project
    {
        $project->members()->detach($user->getKey());

        // Removing a member may break a group's roster → it leaves the project.
        $this->reconciler->syncForProject($project);

        return $project;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveSort(?string $sort): array
    {
        $allowed = ['name', 'created_at', 'updated_at'];

        if ($sort === null || $sort === '') {
            return ['created_at', 'desc'];
        }

        $direction = 'asc';
        $field = $sort;

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $field = substr($sort, 1);
        }

        if (! in_array($field, $allowed, true)) {
            return ['created_at', 'desc'];
        }

        return [$field, $direction];
    }
}
