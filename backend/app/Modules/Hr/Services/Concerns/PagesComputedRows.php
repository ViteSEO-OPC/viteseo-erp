<?php

namespace App\Modules\Hr\Services\Concerns;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Paging for the views that are COMPUTED rather than queried.
 *
 * The queue endpoints page in SQL, because their filters are columns. Two views cannot: the
 * attendance board and the open-shift review both derive each row's status in PHP — from the
 * employee's effective schedule, the day's holidays and suspensions, an approved leave, whether
 * the day has ended. None of that is a column, and none of it can be a WHERE clause without
 * reimplementing schedule resolution in SQL and letting the two definitions drift.
 *
 * So the order here is compute → filter → slice, and it is deliberate:
 *
 *   · filtering AFTER the computation keeps the filters honest — "show me everyone still not
 *     checked in" means the roster, not the twenty-five rows that happened to be on screen;
 *   · slicing after the filter is what the reader actually receives.
 *
 * What this does NOT do is make the server's work smaller — the roster is still walked in full,
 * because that is what "today's status for everyone" means. What it removes is the payload: a
 * response that carried a row per employee, on a board that polls every sixty seconds, now
 * carries a page of them.
 */
trait PagesComputedRows
{
    private const COMPUTED_PER_PAGE = 25;

    private const COMPUTED_MAX_PER_PAGE = 200;

    /**
     * @param  list<array<string, mixed>>  $rows  the fully computed, already filtered set
     * @param  array<string, mixed>  $filters  page, per_page
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function paginateComputed(array $rows, array $filters): LengthAwarePaginator
    {
        $perPage = min(
            self::COMPUTED_MAX_PER_PAGE,
            max(1, (int) ($filters['per_page'] ?? self::COMPUTED_PER_PAGE)),
        );

        $total = count($rows);
        // Clamped rather than trusted: a page number past the end returns the last page instead
        // of an empty table, which is what a reader who was deep in a list sees when a filter
        // narrows it under them.
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($lastPage, max(1, (int) ($filters['page'] ?? 1)));

        return new LengthAwarePaginator(
            array_values(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            $total,
            $perPage,
            $page,
        );
    }

    /**
     * Case-insensitive substring match across a row's named fields — the search box, applied to
     * computed rows for the same reason the slice is: the fields it searches are built here, not
     * stored.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    protected function searchComputed(array $rows, ?string $search, array $fields): array
    {
        $needle = trim((string) $search);

        if ($needle === '') {
            return $rows;
        }

        $needle = mb_strtolower($needle);

        return array_values(array_filter($rows, function (array $row) use ($needle, $fields) {
            foreach ($fields as $field) {
                if (str_contains(mb_strtolower((string) ($row[$field] ?? '')), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
