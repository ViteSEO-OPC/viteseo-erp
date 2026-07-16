<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Models\User;
use App\Modules\Projects\Models\Link;

class LinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.links.view');
    }

    public function view(User $user, Link $link): bool
    {
        return $user->can('projects.links.view');
    }

    public function create(User $user): bool
    {
        return $user->can('projects.links.create');
    }

    public function delete(User $user, Link $link): bool
    {
        // The user who attached the link may always delete it.
        if ($link->user_id === $user->id) {
            return true;
        }

        return $user->can('projects.links.delete');
    }
}
