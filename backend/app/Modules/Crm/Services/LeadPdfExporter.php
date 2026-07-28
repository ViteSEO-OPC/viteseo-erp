<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Lead;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Str;

/**
 * Renders one lead as a PDF profile.
 *
 * The single implementation behind every Export PDF entry point — the Lead Details header and the
 * BD table's row action both hit the same endpoint, which calls this. Adding another surface means
 * calling this service, never re-rendering a lead somewhere else.
 *
 * Follows the HR payslip pattern exactly (Pdf::loadView over a module-namespaced Blade template),
 * so the CRM introduces no second PDF mechanism and no new dependency — barryvdh/laravel-dompdf is
 * already installed and in production use.
 *
 * Scope is the lead PROFILE only. Activity timeline, internal notes and email history are
 * deliberately excluded for this iteration.
 */
class LeadPdfExporter
{
    public function render(Lead $lead): DomPdf
    {
        $lead->loadMissing('assignedTo');

        return Pdf::loadView('crm::lead-profile', [
            'lead' => $lead,
            // Rendered in the business timezone. Datetimes are stored UTC (platform rule), so the
            // PDF converts on the way out rather than showing UTC to a Manila reader.
            'createdAt' => $lead->created_at?->timezone(config('app.timezone_local'))->format('M j, Y g:i A'),
            'generatedAt' => now(config('app.timezone_local'))->format('M j, Y g:i A'),
        ]);
    }

    /**
     * Download filename for a lead, e.g. `lead-acme-industries-142.pdf`. The id keeps it unique when
     * two leads share a business name.
     */
    public function filename(Lead $lead): string
    {
        $slug = Str::slug((string) $lead->business_name) ?: 'lead';

        return "lead-{$slug}-{$lead->id}.pdf";
    }
}
