<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Models\OvertimeRequest;
use App\Modules\Hr\Services\OvertimeRequestService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth:sanctum on the route; ownership derived from token
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'work_date' => ['required', 'date'],
            // The window; `hours` is DERIVED from it server-side (never sent by the client).
            'time_from' => ['required', 'date_format:H:i'],
            'time_to' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * Does this window collide with one the employee already has live on that date?
     *
     * Rejected and cancelled rows are ignored — they authorise nothing, so they cannot
     * double-pay anything. Rows with no window at all (legacy hours-only approvals) are skipped
     * too: with nothing to compare against, blocking on them would forbid filing on a date the
     * employee cannot see the shape of.
     *
     * Shared with the HR retroactive form via a static so the two filing paths cannot drift.
     */
    public static function overlapsExisting(int $employeeId, string $workDate, string $from, string $to, ?int $ignoreId = null): bool
    {
        return OvertimeRequest::where('employee_id', $employeeId)
            ->where('work_date', $workDate)
            ->whereIn('status', ['pending', 'approved'])
            ->whereNull('cancelled_at')
            ->whereNotNull('time_from')
            ->whereNotNull('time_to')
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get(['time_from', 'time_to'])
            ->contains(fn (OvertimeRequest $existing) => OvertimeRequestService::windowsOverlap(
                $from,
                $to,
                (string) $existing->time_from,
                (string) $existing->time_to,
            ));
    }

    /**
     * Several overtime requests per date are allowed as long as their WINDOWS do not overlap
     * (item 10), and the derived duration must be sane — 0.25h to 16h. A window ending at/before
     * its start counts as crossing midnight, so only a zero-length window is rejected outright.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // No back-dating: overtime is requested for today or a future date. Compared in Manila,
            // where the workday boundary is — not the server's UTC clock.
            $date = $this->input('work_date');
            if (is_string($date) && $date !== '' && strtotime($date) !== false) {
                $tz = config('app.timezone_local');
                if (Carbon::parse($date, $tz)->startOfDay()->lt(Carbon::now($tz)->startOfDay())) {
                    $validator->errors()->add('work_date', 'Overtime cannot be filed for a past date.');
                }
            }

            $from = $this->input('time_from');
            $to = $this->input('time_to');
            if (is_string($from) && is_string($to) && preg_match('/^\d{2}:\d{2}$/', $from) && preg_match('/^\d{2}:\d{2}$/', $to)) {
                if ($from === $to) {
                    $validator->errors()->add('time_to', 'The end time must differ from the start time.');
                } else {
                    $hours = OvertimeRequestService::hoursBetween($from, $to);
                    if ($hours < 0.25) {
                        $validator->errors()->add('time_to', 'Overtime must be at least 15 minutes.');
                    } elseif ($hours > 16) {
                        $validator->errors()->add('time_to', 'Overtime cannot exceed 16 hours.');
                    }
                }
            }

            $employeeId = $this->user()?->employee?->id;
            if (! $employeeId) {
                return;
            }

            // Item 10 — OVERLAP, not "one per date". A split evening (17:00–19:00, dinner, then
            // 22:00–01:00) is two genuine windows and the pay engine has always merged several
            // windows on one date. What must never happen is the same minutes being approved
            // twice, and that is exactly what this now blocks.
            $wellFormed = is_string($from) && is_string($to)
                && preg_match('/^\d{2}:\d{2}$/', $from) && preg_match('/^\d{2}:\d{2}$/', $to)
                && $from !== $to;
            if ($wellFormed && self::overlapsExisting($employeeId, (string) $this->input('work_date'), $from, $to)) {
                $validator->errors()->add(
                    'time_from',
                    'This overlaps an overtime request you have already filed for this date.',
                );
            }
        });
    }
}
