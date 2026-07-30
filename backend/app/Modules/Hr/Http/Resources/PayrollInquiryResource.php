<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PayrollInquiryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'topic' => $this->topic,
            'message' => $this->message,
            'attachment_url' => $this->attachment_path
                ? Storage::disk('public')->url($this->attachment_path)
                : null,
            'priority' => $this->priority,
            'status' => $this->status,
            'resolution_note' => $this->resolution_note,
            'resolution_attachments' => collect($this->resolution_attachments ?? [])
                ->map(fn (array $a) => [
                    'url' => Storage::disk('public')->url($a['path']),
                    'name' => $a['name'] ?? basename($a['path']),
                ])
                ->all(),
            'resolved_at' => $this->resolved_at,
            'resolved_by' => $this->whenLoaded('resolver', fn () => $this->resolver?->name),
            'payslip_id' => $this->payslip_id,
            'payslip_cycle' => $this->payslipCycle(),
            'employee' => [
                'id' => $this->employee_id,
                'name' => $this->whenLoaded('employee', fn () => $this->employee->user?->name),
                'photo_url' => $this->whenLoaded('employee', fn () => $this->employee->photo_url),
            ],
            'created_at' => $this->created_at,
        ];
    }

    private function payslipCycle(): ?string
    {
        $run = $this->payslip?->payrollRun;

        return $run ? "{$run->cycle_start->format('Y-m-d')} – {$run->cycle_end->format('Y-m-d')}" : null;
    }
}
