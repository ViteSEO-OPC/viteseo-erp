<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Rules\AppliesFromToday;
use App\Modules\Hr\Services\AttendancePolicyService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendancePolicyRequest extends FormRequest
{
    /** Severity order for the monotonic-threshold guard. */
    private const SEVERITY_RANK = ['green' => 0, 'yellow' => 1, 'orange' => 2, 'red' => 3];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The date this save counts from. Not a policy field — it dates the VERSION payroll
            // resolves against, so a cycle that ended before it keeps the old rules. Absent
            // means today, which is what the UI now sends. No upper bound, because scheduling a
            // change ahead of time is legitimate; a PAST date is refused, because repricing a
            // period already run is a payslip correction's job. See AppliesFromToday.
            'effective_from' => ['sometimes', 'nullable', 'date', new AppliesFromToday],

            'monthly_late_reset' => ['sometimes', 'boolean'],
            // Per-time charging only. The per-N-lates penalty is now its own independent
            // switch (`late_penalty_enabled`), so the two can be combined.
            'late_deduction_mode' => ['sometimes', 'in:none,per_hour,tiered'],
            'late_penalty_enabled' => ['sometimes', 'boolean'],
            'lates_per_deduction' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'late_minutes_from' => ['sometimes', 'in:start_time,after_grace'],
            'late_tiers' => ['sometimes', 'nullable', 'array'],
            'late_tiers.*.over' => ['required_with:late_tiers', 'integer', 'min:0', 'max:1440'],
            'late_tiers.*.hours' => ['required_with:late_tiers', 'numeric', 'min:0', 'max:24'],
            // Repeat the rung per whole block of `over` minutes instead of charging it once.
            // Cross-validated below: it only has a meaning for a SINGLE rung with a non-zero
            // `over`, which is exactly the one-rung rule Company → Settings edits.
            'late_tier_recurring' => ['sometimes', 'boolean'],

            'undertime_deduction_mode' => ['sometimes', 'in:none,per_hour'],
            // Restricted to what the engine actually honours. This accepted
            // `treat_as_undertime_to_now` and `treat_as_absent`, neither of which is implemented:
            // DeductionService mentions the field only in a comment. Accepting a value that
            // silently does nothing is worse than rejecting it — HR would believe a forgotten
            // punch was being charged. Widen this again when the modes are built.
            'missing_clockout_mode' => ['sometimes', 'in:no_deduction_flag_for_review'],
            // Item 5 — minutes past the scheduled end before an open punch is abandoned. Floored
            // at 30 so a preview can never resolve someone who is genuinely still on shift, and
            // capped at 12h so the setting cannot be turned into "never".
            'missing_timeout_allowance_minutes' => ['sometimes', 'integer', 'min:30', 'max:720'],

            // same: `offset_undertime` and `overtime_eligible` have NO reader
            // anywhere in the codebase. The Rules page already shows this control disabled;
            // this stops the API from accepting them behind its back.
            'early_arrival_mode' => ['sometimes', 'in:none'],
            // What happens to an early punch, which is a separate question from what early
            // minutes are WORTH (early_arrival_mode above). AttendanceService::clockIn reads
            // this one. `defer` accepts the punch but stamps it at the shift start.
            'early_clock_in_mode' => ['sometimes', 'in:allow,defer,block'],
            // Whether a rest day may be worked without asking. Not cross-validated against
            // anything: pay is identical either way (rest-day premium is priced off the
            // attendance row, not off an approval), so this only moves who has to agree first.
            'rest_day_work_requires_request' => ['sometimes', 'boolean'],

            // The management exemption's two switches — see AttendancePolicyService::DEFAULTS.
            'management_auto_attendance' => ['sometimes', 'boolean'],
            'management_holiday_premium' => ['sometimes', 'boolean'],

            // Geofenced clock-in. Deliberately NOT cross-validated against
            // `geofence_enabled` — a half-configured policy must be SAVEABLE (HR pins the
            // office, saves, fetches the IP, saves again), and the server fails open on it
            // rather than blocking clock-ins. The pin is the one pair that has to move
            // together: half a coordinate is meaningless, not merely incomplete.
            'geofence_enabled' => ['sometimes', 'boolean'],
            'office_lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:office_lng'],
            'office_lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:office_lat'],
            // Floor of 20 m: GPS itself is only accurate to ~10–20 m, so anything tighter
            // would reject people standing in the office. 5 km is a generous ceiling.
            'office_radius_m' => ['sometimes', 'integer', 'min:20', 'max:5000'],
            'office_ips' => ['sometimes', 'nullable', 'array', 'max:20'],
            'office_ips.*' => ['required', 'ip'],

            'overtime_mode' => ['sometimes', 'in:none,approved_only,auto_after_threshold'],
            'overtime_threshold_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            // Ceilings on auto-mode overtime paid without an approval. 0 = uncapped, and
            // that is the shipped default: there is no statutory maximum on overtime in the
            // Philippines, so any figure here is the company's own control, not a legal one.
            'auto_overtime_daily_cap_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'auto_overtime_period_cap_minutes' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            // Item 7 — the review threshold, NOT a cap: it flags a day, it never holds pay back.
            // 0 turns the flag off. Floored at 30 so it cannot be set to "flag every overrun",
            // which would bury the queue in the twenty-minute days nobody needs to see.
            'auto_overtime_review_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],

            // Premium & overtime rates (Premium Pay epic, Phase 0). Whole-percent multipliers of the
            // hourly/daily rate; each HARD-FLOORED at its DOLE minimum (editable UP, never below).
            // night_diff_pct is the add-on percent (min +10%).
            'premium_rest_day_pct' => ['sometimes', 'integer', 'min:130', 'max:1000'],
            'premium_special_day_pct' => ['sometimes', 'integer', 'min:130', 'max:1000'],
            'premium_regular_holiday_pct' => ['sometimes', 'integer', 'min:200', 'max:1000'],
            'overtime_ordinary_pct' => ['sometimes', 'integer', 'min:125', 'max:1000'],
            'overtime_rest_special_pct' => ['sometimes', 'integer', 'min:169', 'max:1000'],
            'overtime_regular_holiday_pct' => ['sometimes', 'integer', 'min:260', 'max:1000'],
            'night_diff_pct' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'night_diff_start_time' => ['sometimes', 'date_format:H:i'],
            'night_diff_end_time' => ['sometimes', 'date_format:H:i'],

            // `exact` is the legally safe default; the round_* modes are a warned opt-in (§9).
            'deduction_rounding' => ['sometimes', 'in:exact,round_up,round_nearest,round_down'],
            'deduction_block_minutes' => ['sometimes', 'integer', 'min:1', 'max:480'],
            'daily_deduction_cap' => ['sometimes', 'numeric', 'min:0', 'max:1'],

            'daily_rate_basis' => ['sometimes', 'in:period_workdays,fixed_divisor'],
            // Free number with 261/313/365 as the usual presets — not restricted to them.
            'annual_divisor' => ['sometimes', 'integer', 'min:1', 'max:366'],
            'hours_per_day_divisor' => ['sometimes', 'integer', 'min:1', 'max:24'],

            // 0 is meaningful: no tolerance at all.
            'lunch_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'break_grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],

            // Violation colour ladder (step 3c). Rungs are evaluated top-down, first match
            // wins. Severity names are fixed — Violation::SEVERITY_RANK depends on them.
            'violation_rules' => ['sometimes', 'array'],
            'violation_rules.late' => ['sometimes', 'array'],
            'violation_rules.absence' => ['sometimes', 'array'],
            'violation_rules.missed_time_out' => ['sometimes', 'array'],
            'violation_rules.*.*.severity' => ['required', 'in:green,yellow,orange,red'],
            'violation_rules.*.*.streak_min' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'violation_rules.*.*.monthly_min' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'violation_rules.*.*.occurrence_min' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'violation_rules.*.*.type' => ['sometimes', 'string', 'max:40'],

            'task_format' => ['sometimes', 'array'],
            'task_format.require_english' => ['sometimes', 'boolean'],
            'task_format.uppercase' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // The default renders as "The office_ips.0 field…", which shows HR an array index.
            'office_ips.*.ip' => 'Each office IP must be a valid IP address (e.g. 203.0.113.10).',
            'office_lat.required_with' => 'The office pin needs both a latitude and a longitude.',
            'office_lng.required_with' => 'The office pin needs both a latitude and a longitude.',
        ];
    }

    /**
     * The alert levels must strictly ascend: a higher-severity level can't trigger at the
     * same or a lower threshold than a milder one (e.g. Low 2 ⇒ Caution must be > 2).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // Partial card updates are allowed, so compare each supplied value with the live
            // counterpart too. Equal endpoints are ambiguous (zero minutes vs a full 24 hours).
            $policy = app(AttendancePolicyService::class)->get();
            $nightStart = $this->input('night_diff_start_time', substr((string) $policy->night_diff_start_time, 0, 5));
            $nightEnd = $this->input('night_diff_end_time', substr((string) $policy->night_diff_end_time, 0, 5));
            if (preg_match('/^\d{2}:\d{2}$/', (string) $nightStart)
                && preg_match('/^\d{2}:\d{2}$/', (string) $nightEnd)
                && $nightStart === $nightEnd) {
                $message = 'Night differential start and end times must be different.';
                $v->errors()->add('night_diff_start_time', $message);
                $v->errors()->add('night_diff_end_time', $message);
            }

            // A repeating late rung needs a block width to repeat over, and needs to be the
            // ONLY rung — with a ladder there is no single answer to "which rung repeats?",
            // and silently picking one would over- or under-charge without anyone being told.
            // (The API accepts multi-rung ladders; only the recurring flag is restricted.)
            $recurring = $this->boolean('late_tier_recurring', (bool) $policy->late_tier_recurring);
            if ($this->has('late_tier_recurring') || $this->has('late_tiers')) {
                $tiers = $this->has('late_tiers') ? $this->input('late_tiers') : $policy->late_tiers;
                $tiers = is_array($tiers) ? $tiers : [];

                if ($recurring && count($tiers) > 1) {
                    $v->errors()->add(
                        'late_tier_recurring',
                        'A repeating late rule can only use one threshold, not a ladder.',
                    );
                }
                // `over` of 0 would make every minute of lateness its own block — an unbounded
                // charge that only the daily cap stops. Rejected rather than clamped.
                if ($recurring && count($tiers) === 1 && (int) ($tiers[0]['over'] ?? 0) < 1) {
                    $v->errors()->add(
                        'late_tiers',
                        'A repeating late rule needs a threshold of at least 1 minute.',
                    );
                }
            }

            foreach (['late', 'absence', 'missed_time_out'] as $category) {
                $rungs = $this->input("violation_rules.{$category}");
                if (! is_array($rungs)) {
                    continue;
                }

                // (severity rank → threshold) for each rung, then check ascending.
                $byRank = [];
                foreach ($rungs as $rung) {
                    $rank = self::SEVERITY_RANK[$rung['severity'] ?? ''] ?? null;
                    $threshold = $rung['monthly_min'] ?? $rung['streak_min'] ?? $rung['occurrence_min'] ?? null;
                    if ($rank !== null && $threshold !== null) {
                        $byRank[$rank] = (int) $threshold;
                    }
                }
                ksort($byRank);

                $prev = null;
                foreach ($byRank as $threshold) {
                    if ($prev !== null && $threshold <= $prev) {
                        $v->errors()->add(
                            "violation_rules.{$category}",
                            'Each alert level must use a higher number than the level below it.',
                        );
                        break;
                    }
                    $prev = $threshold;
                }
            }
        });
    }
}
