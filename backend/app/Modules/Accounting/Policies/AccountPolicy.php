<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Policies;

use App\Models\User;
use App\Modules\Accounting\Models\Account;

class AccountPolicy
{
    /** Class-level authorization for the chart-of-accounts index. */
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.accounts.view');
    }

    public function view(User $user, Account $account): bool
    {
        return $user->can('accounting.accounts.view');
    }

    public function create(User $user): bool
    {
        return $user->can('accounting.accounts.create');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->can('accounting.accounts.update');
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->can('accounting.accounts.delete');
    }
}
