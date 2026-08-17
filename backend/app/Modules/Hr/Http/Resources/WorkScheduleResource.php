<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'workdays' => array_map('intval', $this->workdays ?? []),
            'start_time' => $this->start_time ? substr($this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr($this->end_time, 0, 5) : null,
            'grace_minutes' => (int) $this->grace_minutes,
            'unpaid_break_minutes' => (int) $this->unpaid_break_minutes,
            'paid_break_minutes' => (int) $this->paid_break_minutes,
            'am_start_time' => $this->am_start_time ? substr($this->am_start_time, 0, 5) : null,
            'am_end_time' => $this->am_end_time ? substr($this->am_end_time, 0, 5) : null,
            'pm_start_time' => $this->pm_start_time ? substr($this->pm_start_time, 0, 5) : null,
            'pm_end_time' => $this->pm_end_time ? substr($this->pm_end_time, 0, 5) : null,
            // Derived paid day = span − unpaid meal. Null when the window is incomplete.
            'expected_minutes' => $this->expectedMinutes(),
            'is_default' => (bool) $this->is_default,
        ];
    }

    /** Paid minutes = (end − start) − unpaid meal; null if the window is incomplete. */
    private function expectedMinutes(): ?int
    {
        if (! $this->start_time || ! $this->end_time) {
            return null;
        }
        [$sh, $sm] = array_map('intval', explode(':', substr((string) $this->start_time, 0, 5)));
        [$eh, $em] = array_map('intval', explode(':', substr((string) $this->end_time, 0, 5)));
        $span = ($eh * 60 + $em) - ($sh * 60 + $sm);
        if ($span < 0) {
            $span += 24 * 60; // overnight schedule ends on the following day
        }

        return $span > 0 ? max(0, $span - (int) $this->unpaid_break_minutes) : null;
    }
}
