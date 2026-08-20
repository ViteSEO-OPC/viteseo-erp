<?php

namespace App\Modules\Hr\Services\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Withdrawing a request you filed yourself.
 *
 * @see \App\Modules\Hr\Services\LeaveService::cancel() the older, leave-specific version of this
 *      rule; leave keeps its own because an APPROVED leave is also cancellable (by HR, before it
 *      starts) and that unwinds a balance, which none of these three have to do.
 */
trait CancelsOwnRequest
{
    /**
     * Guard, then stamp the cancellation trail. Callers own the audit line and the reload,
     * because the module name and wording differ per request type.
     *
     * @param  Model  $record  Must expose `employee_id` and the three cancel columns.
     * @param  string  $noun  What to call it in the error, e.g. "claim" or "concern".
     */
    protected function applyCancellation(Model $record, User $actor, string $reason, string $noun): void
    {
        $isHr = $actor->hasAnyRole(['hr_officer', 'super_admin']);
        $isOwner = $actor->employee !== null
            && (int) $actor->employee->id === (int) $record->getAttribute('employee_id');

        if (! $isHr && ! $isOwner) {
            throw new AuthorizationException("You cannot cancel this {$noun}.");
        }

        $status = (string) $record->getAttribute('status');

        if ($status === 'cancelled') {
            throw ValidationException::withMessages([
                'status' => ["This {$noun} has already been cancelled."],
            ]);
        }

        // Anyone, HR included, is held to `pending`. Withdrawing a claim already committed to a
        // payroll run, or a concern already under review, would leave work downstream pointing at
        // a record that says it was never made.
        if ($status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ["Only a pending {$noun} can be cancelled — this one is {$status}."],
            ]);
        }

        $record->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
            'cancel_reason' => $reason,
        ]);
    }
}
