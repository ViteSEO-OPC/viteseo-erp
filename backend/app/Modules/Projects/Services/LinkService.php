<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Models\User;
use App\Modules\Projects\Models\Link;
use App\Modules\Projects\Models\Subtask;
use App\Modules\Projects\Models\Task;

class LinkService
{
    /**
     * Attach a link to a task. Enforces polymorphism (R2): task_id set,
     * subtask_id null, by construction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForTask(Task $task, array $data, User $user): Link
    {
        return Link::create([
            'task_id' => $task->id,
            'subtask_id' => null,
            'user_id' => $user->id,
            'url' => $data['url'],
        ]);
    }

    /**
     * Attach a link to a subtask. Enforces polymorphism (R2): subtask_id set,
     * task_id null, by construction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForSubtask(Subtask $subtask, array $data, User $user): Link
    {
        return Link::create([
            'task_id' => null,
            'subtask_id' => $subtask->id,
            'user_id' => $user->id,
            'url' => $data['url'],
        ]);
    }

    public function delete(Link $link): void
    {
        $link->delete();
    }
}
