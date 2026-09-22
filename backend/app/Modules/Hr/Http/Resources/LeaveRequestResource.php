<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => [
                'id' => $this->employee?->id,
                'name' => $this->employee?->user?->name,
                'photo_url' => $this->employee?->photo_url,
            ],
            'leave_type' => [
                'id' => $this->leaveType?->id,
                'name' => $this->leaveType?->name,
                'code' => $this->leaveType?->code,
                'color' => $this->leaveType?->color,
            ],
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'is_half_day' => $this->is_half_day,
            'half_day_period' => $this->half_day_period,
            'days' => (int) $this->days,
            'reason' => $this->reason,
            'status' => $this->status,
            // An intern's leave is filed for the record, and is unpaid unless Company → Intern
            // Allowance pays it by its leave type. Null for everyone else, and only worked out for
            // interns' requests, which are few.
            'unpaid_for_intern' => $this->employee?->isIntern()
                ? app(\App\Modules\Hr\Services\InternAllowanceService::class)
                    ->asOf($this->start_date?->toDateString())
                    ->get('leave_pay_rule') === 'unpaid'
                    || ! $this->leaveType?->is_paid
                : null,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->reviewer?->name,
            'review_note' => $this->review_note,
            'cancelled_at' => $this->cancelled_at,
            'cancelled_by' => $this->canceller?->name,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at,
        ];
    }
}
