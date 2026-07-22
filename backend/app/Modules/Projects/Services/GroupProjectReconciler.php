<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Models\Group;
use App\Modules\Projects\Models\Project;

/**
 * Keeps the group↔project pivot in sync with membership.
 *
 * Rule (the single invariant): a group belongs to a project IFF the group has at
 * least one member and every one of those members is also a member of the
 * project. So attaching a project's members until a group's whole roster is
 * present auto-adds the group; removing any of them auto-removes it.
 */
class GroupProjectReconciler
{
    /**
     * Recompute which groups belong to the given project after its membership
     * changed. Empty groups never match (they'd otherwise be "in" every project).
     */
    public function syncForProject(Project $project): void
    {
        $memberIds = $project->members()->pluck('users.id')->all();
        $memberSet = array_flip($memberIds);

        $groups = Group::query()->with('members:id')->has('members')->get();

        $shouldBelong = [];
        foreach ($groups as $group) {
            if ($this->isContained($group, $memberSet)) {
                $shouldBelong[] = $group->getKey();
            }
        }

        // sync() makes the project's group set exactly the fully-contained groups.
        $project->groups()->sync(
            $this->withTimestamps($shouldBelong),
        );
    }

    /**
     * Recompute which projects the given group belongs to after ITS membership
     * changed (a group gaining/losing a member can enter/leave projects).
     */
    public function syncForGroup(Group $group): void
    {
        // Query the pivot directly so a just-added/removed member is always seen
        // (a loaded relation would be stale right after a membership mutation).
        $memberIds = $group->members()->pluck('users.id')->all();

        // An empty group belongs to no project.
        if ($memberIds === []) {
            $group->projects()->sync([]);

            return;
        }

        // Projects that have ALL of the group's members: count the project's
        // members restricted to the group's roster and require the full set.
        // (project_members are unique per user, so the count equality is exact.)
        $projectIds = Project::query()
            ->whereHas(
                'members',
                fn ($q) => $q->whereIn('users.id', $memberIds),
                '>=',
                count($memberIds),
            )
            ->pluck('id')
            ->all();

        $group->projects()->sync($this->withTimestamps($projectIds));
    }

    /**
     * @param  array<int, int>  $memberSet  keys are user ids on the project
     */
    private function isContained(Group $group, array $memberSet): bool
    {
        $ids = $group->members->pluck('id')->all();
        if ($ids === []) {
            return false;
        }
        foreach ($ids as $id) {
            if (! isset($memberSet[$id])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function withTimestamps(array $ids): array
    {
        return array_fill_keys($ids, ['created_at' => now()]);
    }
}
