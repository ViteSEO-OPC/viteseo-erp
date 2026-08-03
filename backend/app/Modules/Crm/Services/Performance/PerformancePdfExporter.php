<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Performance;

use App\Modules\Crm\Services\Kpi\BdKpiService;
use App\Modules\Crm\Support\PerformanceScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Str;

/**
 * Renders a performance scorecard as a PDF.
 *
 * The single implementation behind every performance Export PDF entry point — a BD exporting their
 * own My Performance and an Admin exporting one BD or All Reports all reach this, so the two
 * surfaces cannot drift.
 *
 * Follows the LeadPdfExporter pattern exactly (Pdf::loadView over a module-namespaced Blade
 * template), so the CRM introduces no second PDF mechanism and no new dependency —
 * barryvdh/laravel-dompdf is already installed and in production use.
 *
 * COMPUTES NOTHING. Every figure comes from PerformanceSummaryService (the same service the JSON
 * endpoint returns) and from BdKpiService for M-04 Engagement Coverage (the same metric the
 * Outreach Progress card reads). This class only arranges values that were already calculated
 * elsewhere; a formula added here would be exactly the drift the extraction was done to prevent.
 *
 * NO GLOBAL REPORTING PERIOD. The performance architecture has no date-range filter — the UI's
 * range selector is presentation-only — so the document never claims one. Metrics that genuinely
 * carry a window state their own ("Completed Follow-ups — This Month"); the rest are current-state
 * figures and are labelled with no date at all rather than an invented one.
 */
class PerformancePdfExporter
{
    public function __construct(
        private readonly PerformanceSummaryService $summary,
        private readonly BdKpiService $kpi,
    ) {}

    public function render(PerformanceScope $scope): DomPdf
    {
        $subject = $scope->subject;

        // M-04 Engagement Coverage — read from the KPI Engine rather than recomputed, matching what
        // the Outreach Progress card displays. `evaluable: false` (zero denominator) yields null,
        // which the view renders as "—" instead of a misleading 0%.
        $m04 = collect($this->kpi->forUser($subject)->metrics)
            ->firstWhere('id', 'M-04');

        return Pdf::loadView('crm::performance-report', [
            'isAllReports' => $scope->mode === 'all',
            // Name only — never the user id. An internal identifier is not reporting data.
            'subjectName' => $subject->name,
            'summary' => $this->summary->for($subject),
            'outreachCoverage' => $m04 !== null && $m04->evaluable ? $m04->value : null,
            // Rendered in the business timezone. Datetimes are stored UTC (platform rule), so the
            // PDF converts on the way out rather than showing UTC to a Manila reader.
            'generatedAt' => now(config('app.timezone_local'))->format('M j, Y g:i A'),
        ]);
    }

    /**
     * Download filename, e.g. `performance-report-all-reports.pdf` or
     * `performance-report-jhamire-aranda.pdf`. Slugged so the name is filesystem-safe whatever the
     * subject is called — the same treatment LeadPdfExporter gives a business name.
     */
    public function filename(PerformanceScope $scope): string
    {
        $subject = $scope->mode === 'all'
            ? 'all-reports'
            : (Str::slug($scope->subject->name) ?: 'report');

        return "performance-report-{$subject}.pdf";
    }
}
