<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\AttendancePolicyVersion;

/**
 * The single attendance-policy row (Company → Settings). 's violation engine
 * reads these thresholds; the task-format rule governs the time-in task checklist.
 */
class AttendancePolicyService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Every editable field, with its current-behaviour default. The deduction-policy
     * fields (late_minutes_from onward) all seed to today's behaviour, so a fresh
     * install computes exactly the same pesos until HR opts into a mode.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        'monthly_late_reset' => true,
        'late_deduction_mode' => 'none',
        'lates_per_deduction' => 3,
        'late_penalty_enabled' => false,
        'late_minutes_from' => 'start_time',
        'late_tiers' => null,
        // Whether the tiered rung REPEATS. Off = today's behaviour: the rung's charge is
        // applied once, however late the arrival. On, it is charged per whole block of
        // `over` minutes (30-min rung, 90 min late ⇒ three blocks).
        'late_tier_recurring' => false,
        'undertime_deduction_mode' => 'none',
        'missing_clockout_mode' => 'no_deduction_flag_for_review',
        // Item 5 — how long past the scheduled end an open punch is still "in progress". Past
        // it the shift is abandoned and pay resolves it to the scheduled end. A DURATION, so
        // each employee's cutoff is their own; see the migration for why not a clock time.
        'missing_timeout_allowance_minutes' => 180,
        'early_arrival_mode' => 'none',
        // How an early punch is handled: allow (recorded as-is — today's behaviour),
        // defer (accepted, but stamped at the shift's start time), block (refused).
        'early_clock_in_mode' => 'allow',
        // Working a day the schedule doesn't cover needs HR to agree first — the rest-day work
        // request. On is today's behaviour and the sensible default: a rest day worked earns a
        // premium, so somebody should be choosing to spend it. Off suits companies whose
        // weekend coverage is routine, where a queue that is always approved is a queue nobody
        // reads; the punch is then simply recorded, and pays exactly the same either way.
        'rest_day_work_requires_request' => true,
        // Management is exempt from the time clock and the platform writes their attendance
        // instead — on is today's behaviour. Off puts them back on the clock like everyone
        // else; it never changes their pay, which is fixed either way.
        'management_auto_attendance' => true,
        // Off is today's behaviour: fixed-salary management earns no holiday premium. On pays
        // them the same statutory premium as everyone else for a holiday they worked.
        'management_holiday_premium' => false,
        // Geofenced clock-in — off is today's behaviour. Also the kill switch: turning it
        // off puts everyone back on the normal clock instantly, with no deploy.
        'geofence_enabled' => false,
        'office_lat' => null,
        'office_lng' => null,
        'office_radius_m' => 200,
        'office_ips' => null,
        'overtime_mode' => 'approved_only',
        'overtime_threshold_minutes' => 0,
        'auto_overtime_daily_cap_minutes' => 0, // uncapped
        'auto_overtime_period_cap_minutes' => 0,
        // Item 7 — flags a day for review without withholding a peso of it. Non-zero by default
        // precisely BECAUSE the caps above are 0: something has to notice a runaway overrun on a
        // stock install, and a notification is the version of that which cannot underpay anyone.
        'auto_overtime_review_minutes' => 240,
        // Premium & overtime rates (Phase 0) — seeded to the DOLE legal minimums (no-op).
        'premium_rest_day_pct' => 130,
        'premium_special_day_pct' => 130,
        'premium_regular_holiday_pct' => 200,
        'overtime_ordinary_pct' => 125,
        'overtime_rest_special_pct' => 169,
        'overtime_regular_holiday_pct' => 260,
        'night_diff_pct' => 10,
        // The statutory default remains 22:00–06:00; HR may configure the company window.
        'night_diff_start_time' => '22:00',
        'night_diff_end_time' => '06:00',
        'deduction_rounding' => 'exact',
        'deduction_block_minutes' => 60,
        'daily_deduction_cap' => 1.0,
        'daily_rate_basis' => 'period_workdays',
        'annual_divisor' => 261,
        'hours_per_day_divisor' => 8,
        // How far over the SCHEDULE's own allowance each may run before it is
        // flagged. The allowances themselves live on the work schedule; lunch and break are
        // judged separately so a short lunch can't bankroll a long break. 0 = no tolerance.
        'lunch_grace_minutes' => 15,
        'break_grace_minutes' => 15,
        'violation_rules' => self::DEFAULT_VIOLATION_RULES,
        'task_format' => ['require_english' => true, 'uppercase' => true],
    ];

    /**
     * The violation colour ladder (step 3c /). These values are TODAY'S
     * hard-coded literals from ViolationService::recompute() (:167-168, :183, :186, :196),
     * lifted into data so HR can change them — seeding them here keeps the engine's
     * behaviour identical until ViolationService is taught to read this column.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    public const DEFAULT_VIOLATION_RULES = [
        // Four ascending alert levels, matching the Attendance modal legend
        // (green Low → yellow Caution → orange Warning → red Critical). Lateness is by
        // count of lates this month; absence by consecutive days. Evaluated top-down.
        'late' => [
            ['monthly_min' => 6, 'severity' => 'red'],
            ['monthly_min' => 4, 'severity' => 'orange'],
            ['monthly_min' => 3, 'severity' => 'yellow'],
            ['monthly_min' => 1, 'severity' => 'green'],
        ],
        'absence' => [
            ['streak_min' => 4, 'severity' => 'red', 'type' => 'awol'],
            ['streak_min' => 3, 'severity' => 'orange', 'type' => 'ua'],
            ['streak_min' => 2, 'severity' => 'yellow', 'type' => 'ua'],
            ['streak_min' => 1, 'severity' => 'green', 'type' => 'ua'],
        ],
        // Missed clock-outs this month — a count ladder, like `late` (HR-configurable, step B).
        'missed_time_out' => [
            ['occurrence_min' => 4, 'severity' => 'red'],
            ['occurrence_min' => 3, 'severity' => 'orange'],
            ['occurrence_min' => 2, 'severity' => 'yellow'],
            ['occurrence_min' => 1, 'severity' => 'green'],
        ],
        // Days this month where lunch OR break ran past its own schedule allowance
        // by more than its grace. Flag only: an over-long break NEVER costs pay.
        'long_break' => [
            ['occurrence_min' => 4, 'severity' => 'red'],
            ['occurrence_min' => 3, 'severity' => 'orange'],
            ['occurrence_min' => 2, 'severity' => 'yellow'],
            ['occurrence_min' => 1, 'severity' => 'green'],
        ],
    ];

    /**
     * Per-request memo. This single row is read by every pricing path, once per
     * employee per payroll run. update() clears it.
     */
    private ?AttendancePolicy $memo = null;

    public function get(): AttendancePolicy
    {
        return $this->memo ??= AttendancePolicy::firstOrCreate([], self::DEFAULTS);
    }

    /**
     * The rules in force on $date — the latest version dated on or before it.
     *
     * @param  ?string  $date  Manila calendar date (Y-m-d)
     */
    public function asOf(?string $date = null): AttendancePolicy
    {
        if ($date === null) {
            return $this->get();
        }

        if (isset($this->asOfMemo[$date])) {
            return $this->asOfMemo[$date];
        }

        $version = AttendancePolicyVersion::query()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        // No version on or before the date. The migration backfills an opening row at
        // 2000-01-01 so this should be unreachable, but a run must never fail — and pricing at
        // today's rules is exactly the behaviour that existed before this feature, so falling
        // back here can only ever be as wrong as the old code was.
        if ($version === null) {
            return $this->asOfMemo[$date] = $this->get();
        }

        $payload = $version->payload;
        // Versions written before the configurable window keep the exact historical behavior.
        // The migration backfills these keys, while this fallback also protects imported or
        // manually restored legacy payloads.
        $payload['night_diff_start_time'] ??= '22:00:00';
        $payload['night_diff_end_time'] ??= '06:00:00';

        // newFromBuilder(), NOT new/forceFill(): the payload is in database shape, so the casts
        // must apply on the way OUT only. Filling would re-encode every array column.
        // It is an instance method, hence the throwaway model to call it on.
        return $this->asOfMemo[$date] = (new AttendancePolicy)->newFromBuilder($payload);
    }

    /**
     * Per-request memo, keyed by date. A payroll run resolves one date for every employee, so
     * this is the same saving `get()`'s memo makes — see.
     *
     * @var array<string, AttendancePolicy>
     */
    private array $asOfMemo = [];

    /**
     * The violation colour ladder, with a null column meaning "today's behaviour".
     * ViolationService should read the ladder through HERE, never off the column, so a
     * policy row predating the feature can never yield an empty ladder (= no flags at all).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function violationRules(): array
    {
        return array_merge(self::DEFAULT_VIOLATION_RULES, $this->get()->violation_rules ?: []);
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): AttendancePolicy
    {
        $policy = $this->get();

        // Only the known fields, and only when supplied — an absent key keeps its value.
        $changes = [];
        foreach (array_keys(self::DEFAULTS) as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        // Item 13b.12 — record WHAT changed. This was one line reading "Attendance rules
        // updated" with no before/after, which is the single audit entry guarding every
        // money-moving rule in the module: it could not answer "who changed the overtime rate?".
        $before = [];
        $after = [];
        foreach ($changes as $field => $value) {
            $old = $policy->getOriginal($field);
            if ($old != $value) {
                $before[$field] = $old;
                $after[$field] = $value;
            }
        }

        $policy->update($changes);
        $this->memo = $policy; // keep the memo in step with what was just written

        $effectiveFrom = $this->recordVersion($policy, $data['effective_from'] ?? null);

        $this->audit->log(
            'updated',
            'Rules',
            ($before === []
                ? 'Attendance rules saved (no changes)'
                : 'Attendance rules updated: '.implode(', ', array_keys($before)))
                ." — effective {$effectiveFrom}",
            $policy,
            $before ?: null,
            $after ?: null,
        );

        return $policy;
    }

    /**
     * Write the dated version an old payroll run will resolve against.
     *
     * @return string the date the version was written for, for the audit line
     */
    private function recordVersion(AttendancePolicy $policy, ?string $effectiveFrom): string
    {
        $date = $effectiveFrom ?: now(config('app.timezone_local'))->toDateString();

        // getAttributes() is already in database shape — array columns encoded, booleans as
        // 0/1 — which is exactly what asOf() expects to rehydrate through newFromBuilder().
        $payload = $policy->getAttributes();
        foreach (['night_diff_start_time', 'night_diff_end_time'] as $field) {
            if (isset($payload[$field]) && strlen((string) $payload[$field]) === 5) {
                $payload[$field] .= ':00';
            }
        }
        unset($payload['id'], $payload['created_at'], $payload['updated_at']);

        AttendancePolicyVersion::updateOrCreate(
            ['effective_from' => $date],
            ['payload' => $payload, 'created_by' => auth()->id()],
        );

        $this->asOfMemo = [];

        return $date;
    }
}
