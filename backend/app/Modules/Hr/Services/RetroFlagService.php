<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use App\Modules\Hr\Models\RetroFlag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Records that a decision changed what a period SHOULD have paid, after it was already paid */
class RetroFlagService
{
    public function __construct(
        private readonly PayrollFreezeService $freeze,
        private readonly AuditService $audit,
    ) {}

    /**
     * Flag a decision if — and only if — the days it covers have already been paid.
     *
     * Returns the flag, or null when the period is still open, in which case nothing is owed:
     * re-running the period is the correct and available way to absorb the change.
     */
    public function flagIfSettled(
        Model $record,
        int $employeeId,
        string $from,
        ?string $to,
        string $direction,
        string $reason,
    ): ?RetroFlag {
        $run = $this->freeze->runCovering($employeeId, $from, $to);

        if ($run === null) {
            return null;
        }

        $period = $run->cycle_start->format('M j') . '–' . $run->cycle_end->format('M j, Y');

        // updateOrCreate: approving, un-approving and re-approving the same request must leave one
        // outstanding difference, not a pile of them.
        $flag = RetroFlag::updateOrCreate(
            [
                'flaggable_type' => $record::class,
                'flaggable_id' => $record->getKey(),
                'resolved_at' => null,
            ],
            [
                'employee_id' => $employeeId,
                'payroll_run_id' => $run->id,
                'direction' => $direction,
                'reason' => "{$reason} — the {$period} payroll was already settled.",
            ],
        );

        $this->audit->log(
            'created',
            'Payroll',
            ($direction === RetroFlag::OWED ? 'Back pay owed' : 'Overpayment recorded')
                . " for the {$period} period — {$reason}",
            $flag,
        );

        return $flag;
    }

    /** Everything still outstanding, oldest first — the queue HR works from. */
    public function outstanding(): Collection
    {
        return RetroFlag::with(['employee.user', 'payrollRun', 'flaggable'])
            ->whereNull('resolved_at')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Mark a difference settled — typically after an adjustment run has paid it.
     *
     * The note is required: "resolved" with no explanation is the same dead end as the manual
     * settlement this whole mechanism replaced.
     */
    public function resolve(RetroFlag $flag, User $actor, string $note): RetroFlag
    {
        if (! $flag->isOutstanding()) {
            throw ValidationException::withMessages([
                'status' => ['This difference has already been settled.'],
            ]);
        }

        $flag->update([
            'resolved_at' => now(),
            'resolved_by' => $actor->id,
            'resolution_note' => $note,
        ]);

        $this->audit->log('resolved', 'Payroll', "Settled a retroactive pay difference — {$note}", $flag);

        return $flag->fresh();
    }
}
