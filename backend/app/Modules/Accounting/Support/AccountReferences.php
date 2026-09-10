<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Answers one question: does anything in the books point at this account?
 *
 * Two callers need it and they used to disagree. `AccountController::destroy`
 * asked only about journal lines, so an account an open purchase order pointed
 * at could be deleted out from under it; the retired-account prune asked about
 * everything. They ask the same question here now.
 *
 * The tables are read from the SCHEMA rather than listed. Every `acc_*` column
 * whose name ends in `account_id` is a way to point at an account, and this
 * module has already shipped four tables whose migrations were left behind in
 * another repo -- a hand-written list of "everywhere an account is used" is a
 * list that goes stale the day someone adds a table, silently, in the one
 * direction that lets a referenced account be deleted.
 */
class AccountReferences
{
    /** @var list<array{0: string, 1: string}>|null */
    private ?array $columns = null;

    /** Does any row anywhere point at this account? */
    public function exist(int $accountId): bool
    {
        return $this->firstReferencing($accountId) !== null;
    }

    /**
     * The first table found pointing at this account, or null. Returned rather
     * than a bare bool so the refusal can say what is holding the account.
     */
    public function firstReferencing(int $accountId): ?string
    {
        foreach ($this->columns() as [$table, $column]) {
            if (DB::table($table)->where($column, $accountId)->exists()) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Every (table, column) in the accounting schema that points at an account.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $found = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (! str_starts_with($name, 'acc_') || $name === 'acc_accounts') {
                continue;
            }

            foreach (Schema::getColumnListing($name) as $column) {
                if (str_ends_with($column, 'account_id')) {
                    $found[] = [$name, $column];
                }
            }
        }

        return $this->columns = $found;
    }
}
