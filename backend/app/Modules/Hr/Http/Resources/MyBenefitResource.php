<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Employee "My Benefits" view — Slice B12.
 *
 * WHAT WAS MISSING. This carried a name, a category and a status, and the three fields meant to
 * hold the content (`details`, `progress`, `footer_label`) are empty on every programme. So an
 * employee's only benefits screen showed them less than their payslip did: no amount, no idea what
 * any of it is worth. It could not be fixed in the frontend, because the money was never sent.
 *
 * THEIR FIGURE, NOT THE COMPANY'S. Where B14 gave someone their own allowance amount, this shows
 * what THEY are paid and says nothing about the standard (decided 2026-08-03). Nobody should learn
 * from a self-service page that they are paid above or below their colleagues; that is a
 * conversation with HR, not a card.
 *
 * NOTHING CROSS-EMPLOYEE, as before: no headcounts, no other people's amounts, no employer costs.
 */
class MyBenefitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAllowance = $this->kind === 'allowance';

        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'kind' => $this->kind,
            'color' => $this->color,
            // B13 item 4 — an allowance's badge is DERIVED, not the stored string. `status` is a
            // free-text field HR types, and an allowance appearing on this page is already proof
            // that it is live (a deleted one is not returned), so the two could only disagree.
            // Company plans keep theirs: "Coming soon" versus "Active" is a real difference there.
            'status' => $isAllowance ? 'Active' : $this->status,

            // --- what it is worth to this employee (B12) ---
            //
            // Always a MONTHLY figure, computed by the same service payroll runs, so the total on
            // this page and the allowance line on the payslip are the same arithmetic rather than
            // two implementations that can drift.
            'monthly_amount' => $isAllowance
                ? (float) ($this->monthly_amount ?? 0)
                : null,
            // Their share of a company plan — what is DEDUCTED from their pay, so it is theirs to
            // see. Null means the company pays the whole premium.
            'employee_share' => $isAllowance ? null : $this->employee_share_amount,
            'tier' => $isAllowance ? null : $this->employee_tier,
            // It changes their withholding, so it is theirs to see.
            'tax_treatment' => $isAllowance ? $this->tax_treatment : null,

            'description' => $this->description,
            'details' => $this->details ?? [],
            'progress' => $this->progress,
            'footer_label' => $this->footer_label,
        ];
    }
}
