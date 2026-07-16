<?php

declare(strict_types=1);

namespace App\Modules\Projects\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared date-validation helpers for the task/subtask form requests:
 *  - a due date can't be in the past (only when it's actually being changed),
 *  - a due date must be on or after its start date,
 *  - a subtask's due date can't be after its parent task's.
 */
trait ChecksDeadlineDates
{
    /** Parse a submitted/model date value to a start-of-day date, or null. */
    protected function asDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Flag a past due date — but only when it's actually being changed. Editing
     * an already-overdue task is allowed as long as its date isn't moved to a
     * new past value.
     */
    protected function flagPastDeadline(
        Validator $validator,
        ?CarbonImmutable $deadline,
        ?CarbonImmutable $current,
    ): void {
        if ($deadline === null) {
            return;
        }

        $changed = $current === null || ! $deadline->equalTo($current);

        if ($changed && $deadline->lessThan(CarbonImmutable::now()->startOfDay())) {
            $validator->errors()->add('deadline', 'The due date can’t be in the past.');
        }
    }
}
