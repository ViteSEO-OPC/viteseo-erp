<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Enums\UserRole;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cross-entity lookup powering the topbar's global search. Deliberately narrow:
 * it returns a handful of best matches per entity for a jump-to menu, not a
 * paginated result set — the per-entity list endpoints already own that.
 *
 * Visibility mirrors the list endpoints exactly: managers (Admin/PM) see
 * everything, members only see projects they belong to and the tasks and
 * sub-tasks inside them. Nothing here bypasses that scoping.
 */
class SearchService
{
    /**
     * Shortest term worth a query — one character matches most rows and makes
     * the LIKE scan pointless. Mirrored by the `min:2` rule on SearchRequest.
     */
    public const MIN_TERM_LENGTH = 2;

    /**
     * @return Collection<int, Project>
     */
    public function projects(User $user, string $term, int $limit): Collection
    {
        $like = $this->likeTerm($term);

        return Project::query()
            ->with('workspace')
            ->withCount('tasks')
            ->when(
                ! $this->isManager($user),
                fn (Builder $q) => $q->whereHas(
                    'members',
                    fn (Builder $m) => $m->whereKey($user->getKey()),
                ),
            )
            ->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('description', 'like', $like))
            // Name matches beat description-only matches, and a name that
            // *starts* with the term beats one that merely contains it.
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [
                $this->prefixTerm($term),
                $like,
            ])
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Task>
     */
    public function tasks(User $user, string $term, int $limit): Collection
    {
        $like = $this->likeTerm($term);

        return Task::query()
            ->with(['project', 'status', 'assignee'])
            // A task is reachable only through a project the user can see, so
            // scope on the project's roster rather than on the task itself.
            ->when(
                ! $this->isManager($user),
                fn (Builder $q) => $q->whereHas(
                    'project.members',
                    fn (Builder $m) => $m->whereKey($user->getKey()),
                ),
            )
            ->where(fn (Builder $q) => $q
                ->where('title', 'like', $like)
                ->orWhere('description', 'like', $like))
            ->orderByRaw('CASE WHEN title LIKE ? THEN 0 WHEN title LIKE ? THEN 1 ELSE 2 END', [
                $this->prefixTerm($term),
                $like,
            ])
            // Then most-recently-touched first — the task you are looking for is
            // usually one you worked on lately.
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Subtask>
     */
    public function subtasks(User $user, string $term, int $limit): Collection
    {
        $like = $this->likeTerm($term);

        return Subtask::query()
            // The parent task supplies the row's context and its open target,
            // and the project behind it supplies the visibility rule.
            ->with(['task.project', 'status', 'assignee'])
            ->when(
                ! $this->isManager($user),
                fn (Builder $q) => $q->whereHas(
                    'task.project.members',
                    fn (Builder $m) => $m->whereKey($user->getKey()),
                ),
            )
            ->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('description', 'like', $like))
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [
                $this->prefixTerm($term),
                $like,
            ])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    private function isManager(User $user): bool
    {
        return $user->hasAnyRole(UserRole::managerRoles());
    }

    /** `%term%`, with the user's own LIKE wildcards neutralised. */
    private function likeTerm(string $term): string
    {
        return '%'.$this->escapeLike($term).'%';
    }

    /** `term%` — used to rank prefix matches above mid-string ones. */
    private function prefixTerm(string $term): string
    {
        return $this->escapeLike($term).'%';
    }

    /**
     * Escape the LIKE metacharacters so a query of "100%" looks for the literal
     * text instead of matching every row. `\` is MySQL's default LIKE escape.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
