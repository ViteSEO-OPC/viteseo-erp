<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Policies;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.journal.view');
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $user->can('accounting.journal.view');
    }

    public function create(User $user): bool
    {
        return $user->can('accounting.journal.create');
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        return $user->can('accounting.journal.post');
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $user->can('accounting.journal.reverse');
    }
}
