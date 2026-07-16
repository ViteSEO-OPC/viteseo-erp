<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Support\PublicId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class GroupService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Group>
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        // Eager-load the project (for its public UUID) plus the leader and
        // members so clients can show each member's group membership.
        $query = Group::query()->with(['project', 'leader', 'members'])->withCount('members');

        if (isset($filters['project_id']) && $filters['project_id'] !== '') {
            // The client filters by the project's public UUID; resolve to the internal id.
            $query->where('project_id', PublicId::toInternalId(Project::class, $filters['project_id']));
        }

        if (array_key_exists('archived', $filters) && $filters['archived'] !== '' && $filters['archived'] !== null) {
            $query->where('archived', filter_var($filters['archived'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $query->where('name', 'like', '%'.$filters['search'].'%');
        }

        $this->applySort($query, $sort);

        return $query->paginate($perPage);
    }

    /**
     * @param  Builder<Group>  $query
     */
    private function applySort($query, ?string $sort): void
    {
        $allowed = ['name', 'created_at', 'updated_at', 'archived'];

        if ($sort === null || $sort === '') {
            $query->orderBy('name');

            return;
        }

        $direction = 'asc';
        $field = $sort;

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $field = substr($sort, 1);
        }

        if (! in_array($field, $allowed, true)) {
            $field = 'name';
            $direction = 'asc';
        }

        $query->orderBy($field, $direction);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Group
    {
        // A group is a team scoped to a single project.
        Project::findOrFail($data['project_id']);

        return Group::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Group $group, array $data): Group
    {
        $group->update($data);

        return $group;
    }

    public function delete(Group $group): void
    {
        $group->delete();
    }

    public function addMember(Group $group, int $userId): Group
    {
        $group->members()->syncWithoutDetaching([
            $userId => ['created_at' => now()],
        ]);

        return $group;
    }

    public function removeMember(Group $group, User $user): void
    {
        $group->members()->detach($user->id);
    }
}
