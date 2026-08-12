<?php

declare(strict_types=1);

namespace App\Modules\Projects\Exceptions;

use App\Modules\Projects\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Two people clicked Claim on the same open task and this one lost the race.
 *
 * Both callers pass the policy — the pool is shared and the check is a read —
 * so the conditional UPDATE in TaskService::claim() is what actually decides,
 * and this is how the loser hears about it. Renders itself so the controller
 * stays linear, and names the winner: "someone took it" is a worse message than
 * "Grace took it", and the client already has the task to refresh.
 */
class TaskAlreadyClaimedException extends RuntimeException
{
    public function __construct(private readonly Task $task)
    {
        parent::__construct('This task is no longer open.');
    }

    public function render(Request $request): JsonResponse
    {
        $this->task->loadMissing('assignee');

        return response()->json([
            'message' => $this->task->assignee !== null
                ? "{$this->task->assignee->name} claimed this task a moment ago."
                : $this->getMessage(),
            'error' => 'already_claimed',
        ], 409);
    }
}
