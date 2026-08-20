<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Whole days only — paid leave can't be a half-day.
        // FLOAT, not int. These are decimal(5,1) columns and half-day leave stores real
        // halves, so an int cast reported 2.5 days used as 2 and gifted back half a spent day.
        $entitled = (float) $this->entitled_days;
        $used = (float) $this->used_days;

        return [
            'id' => $this->id,
            'year' => $this->year,
            'entitled_days' => $entitled,
            'used_days' => $used,
            'remaining_days' => round($entitled - $used, 1),
            // How the entitlement was arrived at, so an employee who sees their balance
            // change on 1 January can be told why instead of assuming an error. `accrued` is what
            // this year has credited so far, `carried` came from last year, and `carried_expires_on`
            // is when the carried part lapses (null = never).
            'accrued_days' => (float) $this->accrued_days,
            'carried_days' => (float) $this->carried_days,
            'carried_expires_on' => $this->carried_expires_on?->format('Y-m-d'),
            // Null-safe on purpose. The queries above already exclude balances whose type was
            // deleted, but a resource that hard-crashes on a missing relation turns one bad row
            // into a 500 for the entire list — the failure mode this is guarding against, not a
            // hypothetical one. The placeholder keeps the shape the client expects.
            'leave_type' => [
                'id' => $this->leaveType?->id,
                'name' => $this->leaveType?->name ?? 'Deleted leave type',
                'code' => $this->leaveType?->code ?? '',
                'color' => $this->leaveType?->color,
            ],
        ];
    }
}
