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
     * One live overtime request per date (blocked while pending or approved), and the derived
     * duration must be sane — 0.25h to 16h. A window ending at/before its start counts as
     * crossing midnight, so only a zero-length window is rejected outright.
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

            $duplicate = OvertimeRequest::where('employee_id', $employeeId)
                ->where('work_date', $this->input('work_date'))
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($duplicate) {
                $validator->errors()->add(
                    'work_date',
                    'You already have a pending or approved overtime request for this date.',
                );
            }
        });
    }
}
