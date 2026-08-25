<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Work schedules (Company → Settings) + the single resolver every attendance/violation
 * check reads: an employee's EFFECTIVE schedule = per-employee override → assigned
 * schedule → the default schedule. Never a company-wide cutoff.
 */
class ScheduleService
{
    public function __construct(
        private readonly DailyOverrideService $dailyOverrides,
        private readonly AuditService $audit,
    ) {}

    /**
     * Per-instance memo of the default schedule (F /). effectiveFor() falls back to
     * default() for every schedule-less employee, once per day — a payroll run hit this
     * single, unchanging row thousands of times. The service is resolved once per run; any
     * write (create/update/delete) clears the memo, so it can never go stale within a request.
     */
    private ?WorkSchedule $defaultSchedule = null;

    private bool $defaultResolved = false;

    public function list(): Collection
    {
        return WorkSchedule::orderByDesc('is_default')->orderBy('name')->get();
    }

    public function default(): ?WorkSchedule
    {
        if (! $this->defaultResolved) {
            $this->defaultSchedule = WorkSchedule::where('is_default', true)->first()
                ?? WorkSchedule::orderBy('id')->first();
            $this->defaultResolved = true;
        }

        return $this->defaultSchedule;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): WorkSchedule
    {
        $this->defaultResolved = false; // a new schedule may change the default fallback
        return DB::transaction(function () use ($data) {
            $schedule = WorkSchedule::create($this->clean($data));
            $this->syncDefault($schedule, $data['is_default'] ?? false);

            $this->audit->log('created', 'Schedules', "Created schedule '{$schedule->name}'", $schedule);

            return $schedule->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(WorkSchedule $schedule, array $data): WorkSchedule
    {
        $this->defaultResolved = false; // is_default may flip
        return DB::transaction(function () use ($schedule, $data) {
            $schedule->update($this->clean($data));
            $this->syncDefault($schedule, $data['is_default'] ?? $schedule->is_default);

            $this->audit->log('updated', 'Schedules', "Updated schedule '{$schedule->name}'", $schedule);

            return $schedule->refresh();
        });
    }

    public function delete(WorkSchedule $schedule): void
    {
        // Keep at least one default around; don't orphan the resolver.
        if ($schedule->is_default) {
            throw new \RuntimeException('Cannot delete the default schedule. Set another as default first.');
        }
        $schedule->delete();
        $this->defaultResolved = false; // removed row may change the default fallback

        $this->audit->log('removed', 'Schedules', "Removed schedule '{$schedule->name}'", $schedule);
    }

    /**
     * Assign a schedule and/or set per-employee overrides.
     *
     * @param array<string, mixed> $data
     */
    public function assignForEmployee(Employee $employee, array $data): Employee
    {
        $employee->update([
            'work_schedule_id' => $data['work_schedule_id'] ?? null,
            'custom_workdays' => $data['custom_workdays'] ?? null,
            'custom_start_time' => $data['custom_start_time'] ?? null,
            'custom_end_time' => $data['custom_end_time'] ?? null,
            'custom_am_start_time' => $data['custom_am_start_time'] ?? null,
            'custom_am_end_time' => $data['custom_am_end_time'] ?? null,
            'custom_pm_start_time' => $data['custom_pm_start_time'] ?? null,
            'custom_pm_end_time' => $data['custom_pm_end_time'] ?? null,
            'custom_grace_minutes' => $data['custom_grace_minutes'] ?? null,
            'custom_unpaid_break_minutes' => $data['custom_unpaid_break_minutes'] ?? null,
            'custom_paid_break_minutes' => $data['custom_paid_break_minutes'] ?? null,
        ]);

        $employee->loadMissing('user');
        $name = $employee->user?->name ?? $employee->name;
        $this->audit->log('assigned', 'Schedules', "Assigned schedule to {$name}", $employee);

        return $employee->fresh(['workSchedule']);
    }

    /**
     * The effective schedule for an employee (optionally on a given date). This is
     * the one method 's violation engine calls.
     *
     * @return array{workdays:array<int>, start_time:?string, end_time:?string, grace_minutes:int, unpaid_break_minutes:int, paid_break_minutes:int, expected_minutes:?int, am_start_time:?string, am_end_time:?string, pm_start_time:?string, pm_end_time:?string, source:string, schedule_name:?string, is_workday:?bool}
     */
    public function effectiveFor(Employee $employee, ?Carbon $date = null): array
    {
        $schedule = $employee->workSchedule ?? $this->default();
        $hasOverride = $employee->custom_workdays !== null
            || $employee->custom_start_time !== null
            || $employee->custom_end_time !== null
            || $employee->custom_am_start_time !== null
            || $employee->custom_am_end_time !== null
            || $employee->custom_pm_start_time !== null
            || $employee->custom_pm_end_time !== null
            || $employee->custom_grace_minutes !== null
            || $employee->custom_unpaid_break_minutes !== null
            || $employee->custom_paid_break_minutes !== null;

        $workdays = $employee->custom_workdays ?? $schedule?->workdays ?? [1, 2, 3, 4, 5];
        $grace = $date !== null ? $this->dailyOverrides->graceForDate($date) : null;
        $grace ??= $employee->custom_grace_minutes ?? $schedule?->grace_minutes ?? 0;

        $startTime = $this->hm($employee->custom_start_time ?? $schedule?->start_time);
        $endTime = $this->hm($employee->custom_end_time ?? $schedule?->end_time);

        // Break minutes coalesce like every other field: employee override → schedule → default.
        $unpaidBreak = (int) ($employee->custom_unpaid_break_minutes ?? $schedule?->unpaid_break_minutes ?? 60);
        $paidBreak = (int) ($employee->custom_paid_break_minutes ?? $schedule?->paid_break_minutes ?? 20);

        // The paid day: full span minus the unpaid meal period. Null when the window is unknown.
        $span = $this->spanMinutes($startTime, $endTime);
        $expectedMinutes = $span !== null ? max(0, $span - $unpaidBreak) : null;

        $source = $hasOverride ? 'employee_override' : ($employee->workSchedule ? 'assigned' : 'default');

        return [
            'workdays' => array_values(array_map('intval', $workdays)),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'grace_minutes' => (int) $grace,
            // The day's "minimum hours = full day" bar, or null when HR declared none (almost
            // always). Company-wide per date, exactly like the grace override above, which is
            // why it rides along here rather than on the employee: every consumer that already
            // asks for a day's effective rules needs it, and none of them should query for it.
            'min_full_day_minutes' => $date !== null ? $this->dailyOverrides->minFullDayForDate($date) : null,
            'unpaid_break_minutes' => $unpaidBreak,
            'paid_break_minutes' => $paidBreak,
            'expected_minutes' => $expectedMinutes,
            // Half-day windows coalesce PER FIELD, exactly like start/end/grace above: an
            // employee who starts their afternoons at 13:30 overrides only `custom_pm_start_time`
            // and keeps the template's end. These decide what a half day is worth — lateness,
            // undertime and the early-out fraction are all measured against them — so a template
            // value that does not match the person's real hours is billable minutes.
            'am_start_time' => $this->hm($employee->custom_am_start_time ?? $schedule?->am_start_time),
            'am_end_time' => $this->hm($employee->custom_am_end_time ?? $schedule?->am_end_time),
            'pm_start_time' => $this->hm($employee->custom_pm_start_time ?? $schedule?->pm_start_time),
            'pm_end_time' => $this->hm($employee->custom_pm_end_time ?? $schedule?->pm_end_time),
            'source' => $source,
            'schedule_name' => $schedule?->name,
            'is_workday' => $date ? in_array($date->isoWeekday(), array_map('intval', $workdays), true) : null,
        ];
    }

    // --- helpers ---

    /** Only one schedule can be the default. */
    private function syncDefault(WorkSchedule $schedule, bool $isDefault): void
    {
        if ($isDefault) {
            WorkSchedule::where('id', '!=', $schedule->id)->update(['is_default' => false]);
            $schedule->update(['is_default' => true]);
        }
    }

    /** @param array<string, mixed> $data */
    private function clean(array $data): array
    {
        return [
            'name' => $data['name'],
            'workdays' => array_values(array_map('intval', $data['workdays'])),
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'grace_minutes' => $data['grace_minutes'] ?? 0,
            'unpaid_break_minutes' => $data['unpaid_break_minutes'] ?? 60,
            'paid_break_minutes' => $data['paid_break_minutes'] ?? 20,
            'am_start_time' => $data['am_start_time'] ?? null,
            'am_end_time' => $data['am_end_time'] ?? null,
            'pm_start_time' => $data['pm_start_time'] ?? null,
            'pm_end_time' => $data['pm_end_time'] ?? null,
            // is_default is handled by syncDefault so we never clear the last one.
            'is_default' => $data['is_default'] ?? false,
        ];
    }

    /** Normalise a stored time (HH:MM:SS) to HH:MM. */
    private function hm(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }

    /** Minutes between two HH:MM times, or null if either is missing/invalid. */
    private function spanMinutes(?string $start, ?string $end): ?int
    {
        if (! $start || ! $end) {
            return null;
        }
        [$sh, $sm] = array_map('intval', explode(':', substr($start, 0, 5)));
        [$eh, $em] = array_map('intval', explode(':', substr($end, 0, 5)));
        $span = ($eh * 60 + $em) - ($sh * 60 + $sm);
        if ($span < 0) {
            $span += 24 * 60; // overnight schedule ends on the following day
        }

        return $span > 0 ? $span : null;
    }
}
