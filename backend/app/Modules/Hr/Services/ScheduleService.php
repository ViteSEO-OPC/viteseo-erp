<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Work schedules (Company → Rules) + the single resolver every attendance/violation
 * check reads: an employee's EFFECTIVE schedule = per-employee override → assigned
 * schedule → the default schedule. Never a company-wide cutoff.
 */
class ScheduleService
{
    public function __construct(
        private readonly DailyOverrideService $dailyOverrides,
        private readonly AuditService $audit,
    ) {}

    public function list(): Collection
    {
        return WorkSchedule::orderByDesc('is_default')->orderBy('name')->get();
    }

    public function default(): ?WorkSchedule
    {
        return WorkSchedule::where('is_default', true)->first()
            ?? WorkSchedule::orderBy('id')->first();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): WorkSchedule
    {
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
            'custom_grace_minutes' => $data['custom_grace_minutes'] ?? null,
        ]);

        $employee->loadMissing('user');
        $name = $employee->user?->name ?? $employee->name;
        $this->audit->log('assigned', 'Schedules', "Assigned schedule to {$name}", $employee);

        return $employee->fresh(['workSchedule']);
    }

    /**
     * The effective schedule for an employee (optionally on a given date). This is
     * the one method Slice 16's violation engine calls.
     *
     * @return array{workdays:array<int>, start_time:?string, end_time:?string, grace_minutes:int, source:string, schedule_name:?string, is_workday:?bool}
     */
    public function effectiveFor(Employee $employee, ?Carbon $date = null): array
    {
        $schedule = $employee->workSchedule ?? $this->default();
        $hasOverride = $employee->custom_workdays !== null
            || $employee->custom_start_time !== null
            || $employee->custom_end_time !== null
            || $employee->custom_grace_minutes !== null;

        $workdays = $employee->custom_workdays ?? $schedule?->workdays ?? [1, 2, 3, 4, 5];
        $grace = $date !== null ? $this->dailyOverrides->graceForDate($date) : null;
        $grace ??= $employee->custom_grace_minutes ?? $schedule?->grace_minutes ?? 0;

        $source = $hasOverride ? 'employee_override' : ($employee->workSchedule ? 'assigned' : 'default');

        return [
            'workdays' => array_values(array_map('intval', $workdays)),
            'start_time' => $this->hm($employee->custom_start_time ?? $schedule?->start_time),
            'end_time' => $this->hm($employee->custom_end_time ?? $schedule?->end_time),
            'grace_minutes' => (int) $grace,
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
            // is_default is handled by syncDefault so we never clear the last one.
            'is_default' => $data['is_default'] ?? false,
        ];
    }

    /** Normalise a stored time (HH:MM:SS) to HH:MM. */
    private function hm(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }
}
