<?php

declare(strict_types=1);

namespace App\Modules\Projects\Policies;

use App\Models\User;
use App\Modules\Projects\Models\Comment;

class CommentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.comments.view');
    }

    public function view(User $user, Comment $comment): bool
    {
        return $user->can('projects.comments.view');
    }

    public function create(User $user): bool
    {
        return $user->can('projects.comments.create');
    }

    public function delete(User $user, Comment $comment): bool
    {
        // The author may always delete their own comment.
        if ($comment->author_id === $user->id) {
            return true;
        }

        return $user->can('projects.comments.delete');
    }
}
