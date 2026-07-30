<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Datetimes are returned as UTC ISO strings; the frontend formats them to
 * Asia/Manila for display. `state` is the derived clock state (see the model).
 */
class AttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date?->format('Y-m-d'),
            'time_in' => $this->time_in,
            'time_out' => $this->time_out,
            'lunch_minutes' => $this->lunch_minutes,
            'break_minutes' => $this->break_minutes,
            'hours_worked' => (float) $this->hours_worked,
            'status' => $this->status,
            'state' => $this->state,
            // 'manual' (a person entered it) vs 'system' (generated for exempt
            // management staff) — an audit has to be able to tell them apart.
            'source' => $this->source,
            // How the clock-in proved where they were: ip_gps | ip_only | remote_approved,
            // or null for "no check ran" (feature off, or the row predates it).
            'location_verified_by' => $this->location_verified_by,
            'on_lunch' => $this->lunch_started_at !== null,
            'on_break' => $this->break_started_at !== null,
            'tasks' => $this->tasks ?? [],
        ];
    }
}
