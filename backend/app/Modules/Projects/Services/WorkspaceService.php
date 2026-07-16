<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WorkspaceService
{
    /**
     * Paginated, filterable, sortable list of workspaces (a manager-only, org-level
     * view). A workspace is just a container of projects — it has no members.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Workspace::query();

        $search = $filters['search'] ?? null;
        if (is_string($search) && $search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        [$sortField, $sortDir] = $this->resolveSort($filters['sort'] ?? null);
        $query->orderBy($sortField, $sortDir);

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $creator): Workspace
    {
        return Workspace::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Workspace $workspace, array $data): Workspace
    {
        $workspace->fill($data);
        $workspace->save();

        return $workspace;
    }

    public function delete(Workspace $workspace): void
    {
        $workspace->delete();
    }

    /**
     * Resolve the `sort` filter into a safe [field, direction] pair.
     * A leading `-` denotes descending order (e.g. `-name`).
     *
     * @return array{0: string, 1: string}
     */
    private function resolveSort(mixed $sort): array
    {
        $allowed = ['name', 'created_at', 'updated_at'];

        if (! is_string($sort) || $sort === '') {
            return ['created_at', 'desc'];
        }

        $direction = 'asc';
        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $sort = substr($sort, 1);
        }

        if (! in_array($sort, $allowed, true)) {
            return ['created_at', 'desc'];
        }

        return [$sort, $direction];
    }
}
