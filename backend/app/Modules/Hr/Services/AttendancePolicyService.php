<?php

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\AttendancePolicy;
use App\Modules\Hr\Models\AttendancePolicyVersion;

/**
 * The single attendance-policy row (Company → Rules). Slice 16's violation engine
 * reads these thresholds; the task-format rule governs the time-in task checklist.
 *
 * TWO WAYS TO READ IT, and the distinction is what stops payroll drifting:
 *
 *   get()          the rules as they stand NOW. Everything live wants this — clock-in gating,
 *                  the geofence, violation flags, the overtime approval rate.
 *   asOf($date)    the rules as they stood on $date. PAYROLL ONLY, always the cycle end, so
 *                  re-running an old cycle cannot reprice it with today's divisor.
 *
 * Anything that answers "what happens if I clock in right now?" must stay on get(). Anything
 * that answers "what was this period worth?" must use asOf().
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
        'undertime_deduction_mode' => 'none',
        'missing_clockout_mode' => 'no_deduction_flag_for_review',
        'early_arrival_mode' => 'none',
        // How an early punch is handled: allow (recorded as-is — today's behaviour),
        // defer (accepted, but stamped at the shift's start time), block (refused).
        'early_clock_in_mode' => 'allow',
        // Geofenced clock-in — off is today's behaviour. Also the kill switch: turning it
        // off puts everyone back on the normal clock instantly, with no deploy.
        'geofence_enabled' => false,
        'office_lat' => null,
        'office_lng' => null,
        'office_radius_m' => 200,
        'office_ips' => null,
        'overtime_mode' => 'approved_only',
        'overtime_threshold_minutes' => 0,
        // Premium & overtime rates (Phase 0) — seeded to the DOLE legal minimums (no-op).
        'premium_rest_day_pct' => 130,
        'premium_special_day_pct' => 130,
        'premium_regular_holiday_pct' => 200,
        'overtime_ordinary_pct' => 125,
        'overtime_rest_special_pct' => 169,
        'overtime_regular_holiday_pct' => 260,
        'night_diff_pct' => 10,
        'deduction_rounding' => 'exact',
        'deduction_block_minutes' => 60,
        'daily_deduction_cap' => 1.0,
        'daily_rate_basis' => 'period_workdays',
        'annual_divisor' => 261,
        'hours_per_day_divisor' => 8,
        // Slice 9 — how far over the SCHEDULE's own allowance each may run before it is
        // flagged. The allowances themselves live on the work schedule; lunch and break are
        // judged separately so a short lunch can't bankroll a long break. 0 = no tolerance.
        'lunch_grace_minutes' => 15,
        'break_grace_minutes' => 15,
        'violation_rules' => self::DEFAULT_VIOLATION_RULES,
        'task_format' => ['require_english' => true, 'uppercase' => true],
    ];

    /**
     * The violation colour ladder (step 3c / finding P1-5). These values are TODAY'S
     * hard-coded literals from ViolationService::recompute() (:167-168, :183, :186, :196),
     * lifted into data so HR can change them — seeding them here keeps the engine's
     * behaviour identical until ViolationService is taught to read this column.
     *
     * Evaluated TOP-DOWN, first match wins (so list most-severe first). An empty list for
     * a category means "never flag it". Severity names must stay green|yellow|orange|red —
     * Violation::SEVERITY_RANK and the UI badges depend on them.
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
        // Slice 9 — days this month where lunch OR break ran past its own schedule allowance
        // by more than its grace. Flag only: an over-long break NEVER costs pay.
        'long_break' => [
            ['occurrence_min' => 4, 'severity' => 'red'],
            ['occurrence_min' => 3, 'severity' => 'orange'],
            ['occurrence_min' => 2, 'severity' => 'yellow'],
            ['occurrence_min' => 1, 'severity' => 'green'],
        ],
    ];

    /**
     * Per-request memo (Slice 15). This single row is read by every pricing path, once per
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
     * Payroll passes the CYCLE END, matching salariesAsOf() (13c), the contribution versions
     * (B3a) and allowance pricing (B11): one resolution date for the whole period, so a cycle
     * is never priced under two different rulebooks. A change dated inside the open cycle
     * therefore prices the WHOLE cycle, which is the same promise allowances make.
     *
     * Null $date means "now" and returns the live singleton, so a caller that has no cycle in
     * hand (every screen, every clock-in) is unaffected by any of this.
     *
     * The returned model is READ-ONLY in practice: it is rehydrated from the stored payload and
     * is not the singleton, so saving it would not write the policy back. Nothing does.
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

        // newFromBuilder(), NOT new/forceFill(): the payload is in database shape, so the casts
        // must apply on the way OUT only. Filling would re-encode every array column.
        // It is an instance method, hence the throwaway model to call it on.
        return $this->asOfMemo[$date] = (new AttendancePolicy)->newFromBuilder($version->payload);
    }

    /**
     * Per-request memo, keyed by date. A payroll run resolves one date for every employee, so
     * this is the same saving `get()`'s memo makes — see Slice 15.
     *
     * @var array<string, AttendancePolicy>
     */
    private array $asOfMemo = [];

    /**
     * The violation colour ladder, with a null column meaning "today's behaviour".
     * ViolationService should read the ladder through HERE, never off the column, so a
     * policy row predating the feature can never yield an empty ladder (= no flags at all).
     *
     * A stored ladder takes priority per CATEGORY, with defaults filling any category it
     * doesn't mention — so adding a category (Slice 9's `long_break`) works immediately
     * instead of being silently absent for every org that has ever saved this page.
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
                : 'Attendance rules updated: ' . implode(', ', array_keys($before)))
                . " — effective {$effectiveFrom}",
            $policy,
            $before ?: null,
            $after ?: null,
        );

        return $policy;
    }

    /**
     * Write the dated version an old payroll run will resolve against.
     *
     * DEFAULTS TO TODAY, not to the open cycle: rules HR types now are the rules from now, and
     * back-dating silently would rewrite payslips that have already been computed. HR passes a
     * date deliberately when correcting a cycle that has already been run — which is the one
     * workflow versioning would otherwise break, since a fix saved after the cycle ended could
     * never reach it.
     *
     * SAVING TWICE ON ONE DATE UPDATES that version rather than failing on the unique index.
     * That is the common case twice over here: the Rules page saves one card at a time, so an
     * HR officer working through three cards on a Tuesday writes Tuesday's version three times,
     * each carrying the full policy as it stands after that save.
     *
     * THE WHOLE ROW IS DATED, so back-dating reaches every rule currently saved, not only the
     * card being saved. That is inherent to versioning the row rather than the field, and the
     * Rules page says so next to the date.
     *
     * @return string the date the version was written for, for the audit line
     */
    private function recordVersion(AttendancePolicy $policy, ?string $effectiveFrom): string
    {
        $date = $effectiveFrom ?: now(config('app.timezone_local'))->toDateString();

        // getAttributes() is already in database shape — array columns encoded, booleans as
        // 0/1 — which is exactly what asOf() expects to rehydrate through newFromBuilder().
        $payload = $policy->getAttributes();
        unset($payload['id'], $payload['created_at'], $payload['updated_at']);

        AttendancePolicyVersion::updateOrCreate(
            ['effective_from' => $date],
            ['payload' => $payload, 'created_by' => auth()->id()],
        );

        $this->asOfMemo = [];

        return $date;
    }
}
