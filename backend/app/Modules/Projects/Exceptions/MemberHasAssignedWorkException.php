<?php

declare(strict_types=1);

namespace App\Modules\Projects\Exceptions;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Someone is still holding unfinished work on the project they are being removed
 * from.
 *
 * Removal used to be a bare roster detach, which left the work assigned to a
 * person no longer on the project: off their board, still in their workload, and
 * invisible to the open-task pool (which only lists work with no assignee). So
 * it is refused — and the response carries exactly what is in the way, so the PM
 * can reassign from the same dialog instead of hunting for it.
 */
class MemberHasAssignedWorkException extends RuntimeException
{
    /**
     * @param  Collection<int, array<string, mixed>>  $tasks
     * @param  Collection<int, array<string, mixed>>  $subtasks
     */
    public function __construct(
        private readonly User $member,
        private readonly Collection $tasks,
        private readonly Collection $subtasks,
    ) {
        parent::__construct('This member still has unfinished work on the project.');
    }

    public function render(Request $request): JsonResponse
    {
        $count = $this->tasks->count() + $this->subtasks->count();

        return response()->json([
            'message' => sprintf(
                '%s still has %d unfinished %s on this project. Reassign %s first.',
                $this->member->name,
                $count,
                $count === 1 ? 'item' : 'items',
                $count === 1 ? 'it' : 'them',
            ),
            'error' => 'member_has_assigned_work',
            'data' => [
                'member' => ['id' => $this->member->id, 'name' => $this->member->name],
                'tasks' => $this->tasks->values(),
                'subtasks' => $this->subtasks->values(),
            ],
        ], 409);
    }
}
