<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PayrollRun;
use App\Modules\Hr\Models\RetroFlag;
use App\Modules\Hr\Models\SalaryHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hand-editing the compensation timeline — which is NOT the same kind of thing as editing the
 * employment one, and the difference is the whole design of this service.
 */
class SalaryHistoryService
{
    /** `hire` is included so the opening row can be corrected, not just the raises after it. */
    public const CHANGE_TYPES = ['hire', 'promotion', 'merit', 'adjustment'];

    public function __construct(private readonly PayrollFreezeService $freeze) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Employee $employee, array $data): SalaryHistory
    {
        return DB::transaction(function () use ($employee, $data) {
            $this->assertNotSettled(
                $employee->id,
                $data['effective_date'],
                (bool) ($data['acknowledge_repricing'] ?? false),
            );

            $history = SalaryHistory::create([
                'employee_id' => $employee->id,
                'base_salary' => $data['base_salary'],
                'effective_date' => $data['effective_date'],
                'reason' => $data['reason'] ?? 'Salary adjustment',
                'change_type' => $data['change_type'] ?? 'adjustment',
                'changed_by' => Auth::id(),
            ]);

            $this->flag($history, $employee->id, $data['effective_date'], 'Compensation entry added: '
                . number_format((float) $data['base_salary'], 2) . ' effective ' . $data['effective_date']);

            $this->syncCurrentSalary($employee);

            return $history;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SalaryHistory $history, array $data): SalaryHistory
    {
        return DB::transaction(function () use ($history, $data) {
            $employee = $history->employee()->withTrashed()->firstOrFail();
            $oldDate = $history->effective_date->toDateString();
            $newDate = $data['effective_date'] ?? $oldDate;

            // Check from the EARLIER of the two dates. Moving a raise later still repriced
            // everything from where it used to sit, and checking only the new date would miss it.
            $this->assertNotSettled(
                $history->employee_id,
                min($oldDate, $newDate),
                (bool) ($data['acknowledge_repricing'] ?? false),
            );

            $history->update([
                'base_salary' => $data['base_salary'] ?? $history->base_salary,
                'effective_date' => $newDate,
                'reason' => array_key_exists('reason', $data) ? $data['reason'] : $history->reason,
                'change_type' => $data['change_type'] ?? $history->change_type,
                'changed_by' => Auth::id(),
            ]);

            $this->flag(
                $history,
                $history->employee_id,
                min($oldDate, $newDate),
                'Compensation entry corrected to ' . number_format((float) $history->base_salary, 2)
                    . ' effective ' . $newDate . ' — the days already paid were priced differently',
            );

            $this->syncCurrentSalary($employee);

            return $history->fresh();
        });
    }

    public function delete(SalaryHistory $history): void
    {
        DB::transaction(function () use ($history) {
            $employee = $history->employee()->withTrashed()->firstOrFail();

            $affected = $this->settledRuns($history->employee_id, $history->effective_date->toDateString());

            if ($affected->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'id' => [
                        'This entry priced ' . $this->describe($affected) . '. Deleting it would '
                            . 'leave those payslips with no basis, and payroll would fall back to the '
                            . 'current salary if the period were ever re-run. Correct the amount '
                            . 'instead, or add a new dated entry above it.',
                    ],
                ]);
            }

            // Leaving an employee with no compensation history at all is the same failure by a
            // slower route: `salariesAsOf()` finds nothing and quietly uses today's figure for
            // every period, however old.
            if (SalaryHistory::where('employee_id', $history->employee_id)->count() <= 1) {
                throw ValidationException::withMessages([
                    'id' => [
                        'This is the only compensation entry on the record. Payroll prices every run '
                            . 'from this history, so at least one entry has to remain — edit this one '
                            . 'instead of deleting it.',
                    ],
                ]);
            }

            $history->delete();

            $this->syncCurrentSalary($employee);
        });
    }

    /**
     * The completed runs a change at `$from` would reprice — the same question
     * `EmployeeService::assertSalaryDateIsSafe()` asks when a backdated raise is entered.
     *
     * @return Collection<int, PayrollRun>
     */
    public function settledRuns(int $employeeId, string $from): Collection
    {
        return $this->freeze->completedRunsRepricedBy($employeeId, $from);
    }

    private function assertNotSettled(int $employeeId, string $from, bool $acknowledged): void
    {
        if ($acknowledged) {
            return;
        }

        $affected = $this->settledRuns($employeeId, $from);

        if ($affected->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'effective_date' => [
                'This entry prices ' . $this->describe($affected) . ', which were already paid at '
                    . 'the figure recorded here. Correcting it does not repay the difference — it '
                    . 'will be raised as an outstanding one. Confirm to record the correction anyway.',
            ],
        ]);
    }

    /** @param  Collection<int, PayrollRun>  $runs */
    private function describe(Collection $runs): string
    {
        $periods = $runs
            ->map(fn ($r) => $r->cycle_start->format('M j') . '–' . $r->cycle_end->format('M j, Y'))
            ->implode(', ');

        return $runs->count() . ' completed payroll run' . ($runs->count() === 1 ? '' : 's')
            . " ({$periods})";
    }

    /**
     * `flagIfSettled()` returns null on its own when no run covers the date, so a forward-dated or
     * otherwise harmless entry raises nothing.
     */
    private function flag(SalaryHistory $history, int $employeeId, string $from, string $reason): void
    {
        app(RetroFlagService::class)->flagIfSettled(
            $history,
            $employeeId,
            $from,
            null,
            RetroFlag::OWED,
            $reason,
        );
    }

    /** Keep `hr_employees.base_salary` agreeing with the timeline. */
    private function syncCurrentSalary(Employee $employee): void
    {
        $today = now(config('app.timezone_local'))->toDateString();

        $inForce = SalaryHistory::where('employee_id', $employee->id)
            ->whereDate('effective_date', '<=', $today)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first()
            // Everything on the record is future-dated (or it is a brand-new hire): the earliest
            // entry is the best statement of what they are on.
            ?? SalaryHistory::where('employee_id', $employee->id)
                ->orderBy('effective_date')
                ->orderBy('id')
                ->first();

        if ($inForce === null) {
            return;
        }

        // Written straight to the column on purpose: routing this through EmployeeService::update()
        // would append ANOTHER history row for a change that came from the history itself.
        $employee->forceFill(['base_salary' => $inForce->base_salary])->saveQuietly();
    }
}
