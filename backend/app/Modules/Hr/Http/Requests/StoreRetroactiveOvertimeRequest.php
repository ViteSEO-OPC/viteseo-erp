<?php

namespace App\Modules\Hr\Http\Requests;

use App\Modules\Hr\Services\OvertimeRequestService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * HR filing overtime on an employee's behalf, for a date that has already passed (item 10).
 *
 * WHY THIS IS A SEPARATE FORM AND NOT A FLAG ON THE OTHER ONE. The employee-facing rule is "today
 * or later" — back-dating your own overtime is asking to be paid for work nobody can still
 * observe. The correction case is the exact opposite: the date is necessarily in the past, and the
 * evidence is the punch record HR is looking at. Two opposite date rules in one request class,
 * switched on the caller's role, is how a filing guard quietly stops applying to anyone.
 *
 * WHAT MAKES IT "CONTROLLED":
 *   • HR-only, by route (`role:hr_officer|super_admin`).
 *   • Filed as PENDING, never auto-approved. Filing and approving stay two acts, so the existing
 *     approve path — with its audit entry and its FIN-009 retro flag for periods already paid —
 *     runs unchanged. HR filing and HR approving may also be different people.
 *   • `filed_by` records who did it, so the row never masquerades as the employee's own.
 *   • The same overlap rule as ordinary filing: a correction must not double-pay minutes an
 *     existing request already covers.
 *
 * NOT bounded by the payroll freeze. Deliberate, and it matches `approve()`: overtime that was
 * worked was worked, and a cutoff closing does not unmake it. A settled period raises a retro
 * flag so an adjustment run can settle the difference (FIN-009) rather than refusing the record.
 */
class StoreRetroactiveOvertimeRequest extends FormRequest
{
    /** How far back HR may reach. Long enough for the previous cycle and its corrections. */
    private const MAX_BACKDATE_DAYS = 90;

    public function authorize(): bool
    {
        return true; // route is behind auth:sanctum + role:hr_officer|super_admin
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:hr_employees,id'],
            'work_date' => ['required', 'date'],
            'time_from' => ['required', 'date_format:H:i'],
            'time_to' => ['required', 'date_format:H:i'],
            // Longer floor than the employee form's: a correction filed by someone other than the
            // person who worked it has to say why, because the audit trail is the only account of
            // it anybody will have later.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tz = config('app.timezone_local');
            $today = Carbon::now($tz)->startOfDay();

            $date = $this->input('work_date');
            $parsed = null;
            if (is_string($date) && $date !== '' && strtotime($date) !== false) {
                $parsed = Carbon::parse($date, $tz)->startOfDay();

                if ($parsed->gt($today)) {
                    $validator->errors()->add(
                        'work_date',
                        'This is for correcting a date that has passed. The employee files their own future overtime.',
                    );
                } elseif ($parsed->lt($today->copy()->subDays(self::MAX_BACKDATE_DAYS))) {
                    $validator->errors()->add(
                        'work_date',
                        'Overtime can only be corrected up to '.self::MAX_BACKDATE_DAYS.' days back.',
                    );
                }
            }

            $from = $this->input('time_from');
            $to = $this->input('time_to');
            $wellFormed = is_string($from) && is_string($to)
                && preg_match('/^\d{2}:\d{2}$/', $from) && preg_match('/^\d{2}:\d{2}$/', $to);

            if (! $wellFormed) {
                return;
            }

            if ($from === $to) {
                $validator->errors()->add('time_to', 'The end time must differ from the start time.');

                return;
            }

            // Same bounds as ordinary filing — a correction is not a licence for a stranger figure.
            $hours = OvertimeRequestService::hoursBetween($from, $to);
            if ($hours < 0.25) {
                $validator->errors()->add('time_to', 'Overtime must be at least 15 minutes.');
            } elseif ($hours > 16) {
                $validator->errors()->add('time_to', 'Overtime cannot exceed 16 hours.');
            }

            $employeeId = (int) $this->input('employee_id');
            if ($parsed !== null && $employeeId > 0 && StoreOvertimeRequestRequest::overlapsExisting(
                $employeeId,
                $parsed->toDateString(),
                $from,
                $to,
            )) {
                $validator->errors()->add(
                    'time_from',
                    'This overlaps an overtime request already filed for this employee on this date.',
                );
            }
        });
    }
}
