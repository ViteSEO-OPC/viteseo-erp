<?php

namespace App\Modules\Hr\Services\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns a request queue into ONE PAGE of a request queue.
 *
 * Every queue in the module was written the same way — `->with(...)->latest()->get()` — which
 * hands the browser every row that has ever been filed and lets it do the searching, the status
 * filtering and the paging. That is correct only while the queue is small, and these are the
 * tables with no ceiling at all: every employee adds to them, forever, and nobody deletes a
 * settled request.
 *
 * Shared rather than repeated because there are a dozen of them and they differ only in which
 * columns are worth searching. Repeating the logic twelve times is twelve chances to get the
 * search grouping wrong — see the note on the group below for what that costs.
 */
trait PagesQueues
{
    /** Rows per page when the caller doesn't say. Matches the tables' own page size. */
    private const QUEUE_PER_PAGE = 10;

    /** Ceiling, so `?per_page=100000` cannot ask for the whole table back. */
    private const QUEUE_MAX_PER_PAGE = 100;

    /**
     * @param  Builder  $query  already scoped to what the caller may see, and NOT yet ordered
     * @param  array<string, mixed>  $filters  search, status, page, per_page
     * @param  list<string>  $searchable  columns on the queue's own table
     * @param  bool  $searchEmployee  also match the filer's name — HR-facing queues only,
     *                                never one where the filer is meant to be anonymous
     * @param  ?\Closure  $extraSearch  further OR branches, applied INSIDE the search group.
     *                                  For a queue whose filer is matchable only conditionally
     * @param  string  $statusColumn  the column the status pills filter on. Not every queue
     *                                calls it `status` — a hiring requisition has a `stage`
     * @param  ?list<array{0:string, 1:string}>  $sort  an explicit [column, direction] ordering
     *                                                  the reader chose, replacing BOTH the
     *                                                  priority rung and the recency default
     * @param  list<string>  $priorityStatuses  statuses that sort ahead of everything else,
     *                                          whatever their date — the queue's "still needs
     *                                          a decision" rows
     * @param  string  $recencyColumn  what "newest first" means for this queue
     * @param  ?string  $urgentColumn  a column whose `$urgentValue` sorts ahead of its peers
     *                                 within the priority group — a queue with an explicit
     *                                 urgency flag on it
     */
    protected function paginateQueue(
        Builder $query,
        array $filters,
        array $searchable = [],
        bool $searchEmployee = true,
        array $priorityStatuses = [],
        string $recencyColumn = 'created_at',
        ?string $urgentColumn = null,
        string $urgentValue = 'high',
        ?\Closure $extraSearch = null,
        string $statusColumn = 'status',
        ?array $sort = null,
    ): LengthAwarePaginator {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;

        $perPage = min(
            self::QUEUE_MAX_PER_PAGE,
            max(1, (int) ($filters['per_page'] ?? self::QUEUE_PER_PAGE)),
        );

        return $query
            ->when(
                $status && $status !== 'all',
                fn (Builder $q) => $q->where($statusColumn, $status),
            )
            ->when($search !== '', function (Builder $q) use ($search, $searchable, $searchEmployee, $extraSearch) {
                // GROUPED. These branches are OR'd and AND binds tighter, so ungrouped they sit
                // beside the ownership scope above — and an employee searching their own queue
                // would match on somebody else's row. The same mistake in the employee directory
                // leaked active staff into the former roster; here it would leak other people's
                // requests, which is worse.
                $q->where(function (Builder $group) use ($search, $searchable, $searchEmployee, $extraSearch) {
                    foreach ($searchable as $i => $column) {
                        $i === 0
                            ? $group->where($column, 'like', "%{$search}%")
                            : $group->orWhere($column, 'like', "%{$search}%");
                    }

                    if ($searchEmployee) {
                        $group->orWhereHas(
                            'employee',
                            fn ($e) => $e->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")),
                        );
                    }

                    if ($extraSearch) {
                        $extraSearch($group, $search);
                    }
                });
            })
            // Ordering is applied HERE, after the filters and before the slice, because paging is
            // what makes it matter. The tables used to float rows needing a decision to the top
            // in the browser — which, once only one page is in hand, would reorder the ten rows
            // that happened to land on it and leave the pending ones scattered through the rest.
            // Sorting it in the query puts them on page 1, where the whole point was to put them.
            // An explicit sort is the reader saying what order they want, so it replaces the
            // defaults entirely rather than fighting them. Leaving the priority rung in front of
            // "sort by amount, highest first" would answer a different question than the one the
            // control asks — and the reader has no way to see why the top row is not the biggest.
            ->when($sort !== null, function (Builder $q) use ($sort) {
                foreach ($sort as [$column, $direction]) {
                    $q->orderBy($column, $direction === 'asc' ? 'asc' : 'desc');
                }
            })
            ->when($sort === null && $priorityStatuses !== [], fn (Builder $q) => $q->orderByRaw(
                "case when {$statusColumn} in (" . implode(',', array_fill(0, count($priorityStatuses), '?')) . ') then 0 else 1 end',
                $priorityStatuses,
            ))
            // Within the outstanding group, anything explicitly flagged urgent leads. Paging is
            // what makes this necessary: while the whole queue arrived at once, an urgent item
            // was somewhere on screen and the reader could find it. One page at a time, an
            // urgent inquiry filed three weeks ago sits on page 4 behind newer routine ones —
            // reachable only by someone who already knew to look.
            ->when($sort === null && $urgentColumn !== null, fn (Builder $q) => $q->orderByRaw(
                "case when {$urgentColumn} = ? then 0 else 1 end",
                [$urgentValue],
            ))
            ->when($sort === null, fn (Builder $q) => $q->orderByDesc($recencyColumn))
            // A UNIQUE tiebreaker, and not optional. `created_at` has second precision, so rows
            // filed in the same second — an import, a seeder, two people submitting at once —
            // tie, and a tied sort has no defined order. Unordered ties are harmless while the
            // whole queue is fetched at once and corrupting the moment it is paged: the database
            // is free to answer differently for page 1 and page 2, so a row can appear on both
            // or on neither. That is a record silently missing from a queue nobody is watching.
            ->orderByDesc($query->getModel()->getQualifiedKeyName())
            ->paginate($perPage);
    }
}
