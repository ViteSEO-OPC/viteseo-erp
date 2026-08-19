<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Services\HalfDayWindowService;
use App\Modules\Hr\Services\ScheduleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class HalfDayRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date?->format('Y-m-d'),
            'period' => $this->period,
            'reason' => $this->reason,
            'status' => $this->status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at,
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->canceller?->name,
            'cancel_reason' => $this->cancel_reason,
            'reviewed_by' => $this->reviewer?->name,
            'employee' => [
                'id' => $this->employee_id,
                'name' => $this->whenLoaded('employee', fn () => $this->employee?->user?->name),
                'photo_url' => $this->whenLoaded('employee', fn () => $this->employee?->photo_url),
            ],
            // The hours this request actually leaves the employee owing, resolved from their own
            // effective schedule. HR is deciding whether someone may take a morning off; what the
            // afternoon then is, is the other half of that question.
            //
            // NULL IS THE WARNING. It means the worked half has no window configured, and the
            // platform will therefore decline to judge that day's lateness at all rather than
            // measure it against a shift nobody defined. The schedule form now requires these, so
            // a null here is a pre-existing schedule that still needs one — surfaced at the moment
            // it starts to matter instead of discovered later in a payslip.
            'worked_window' => $this->workedWindow(),
            'created_at' => $this->created_at,
        ];
    }

    /**
     * The window the employee is expected to work on this date, or null when their schedule has
     * no times for the half they are NOT taking off.
     *
     * @return array{start: string, end: string}|null
     */
    private function workedWindow(): ?array
    {
        $employee = $this->employee;
        if (! $employee || ! $this->work_date) {
            return null;
        }

        $eff = app(ScheduleService::class)->effectiveFor(
            $employee,
            Carbon::parse($this->work_date->toDateString(), config('app.timezone_local')),
        );

        [$start, $end] = app(HalfDayWindowService::class)->windowFor($eff, strtoupper((string) $this->period));

        return $start === null ? null : ['start' => $start, 'end' => $end];
    }
}
