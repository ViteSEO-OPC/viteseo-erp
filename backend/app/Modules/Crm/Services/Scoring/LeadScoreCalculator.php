<?php

namespace App\Modules\Crm\Services\Scoring;

use Carbon\CarbonInterface;

/**
 * Pure, deterministic Personal Lead Score calculator (BD-PERF-COMP-SPEC §4 + Amendment A/B).
 *
 * A **fixed additive points model** — Fit and Engagement signals add whole points, Negative signals
 * subtract; category subtotals are capped, then the total is clamped to [0, 100]. No weighting,
 * averaging, normalization, nested calculation, AI, or inference (R-3). Given the same inputs and
 * evaluation instant it always returns the same result — so it takes primitives (not a model or the
 * DB), which also makes every signal unit-testable in isolation. Orchestration (resolving target
 * location, recency, and persistence) lives in {@see LeadScorer}; this class only applies the rules.
 *
 * **Every point value, window, and cap comes from {@see ScoringConfig} (`config/scoring.php`)** — the
 * calculator hard-codes no number, so a re-weight approved by the PO is a config edit, not a code
 * change. Only the signal *conditions* (which enum values count, mutual exclusivity, the real-contact
 * rule) live here, since they are business logic rather than tunable parameters.
 *
 * Signal set (Amendment B additions/clarifications marked):
 *   Fit:        Website  no-website / needs-redesign (Amdt B) ; Target Location ;
 *               Contact Person (only a REAL contact, contact_name != business_name — Amdt B)
 *   Engagement: Interested Reply ; Call Completed XOR Call Scheduled (R-2) ;
 *               Email Opened (email_outreach ∈ {opened, replied}, hierarchical — Amdt B) ;
 *               Awaiting Reply ; Recent Activity (≤ recent window)
 *   Negative:   Has Website ; No Activity (> stale window) ; Invalid/Bounced Email (NOT evaluable
 *               until Gmail — contributes 0, surfaced honestly, never guessed — §10 / Amdt B B-4)
 */
class LeadScoreCalculator
{
    public function __construct(private readonly ScoringConfig $config) {}

    public function calculate(
        string $websiteStatus,
        string $businessName,
        ?string $contactName,
        string $status,
        string $coldCalling,
        string $emailOutreach,
        bool $inTargetLocation,
        ?CarbonInterface $lastActivityAt,
        CarbonInterface $now,
    ): LeadScoreResult {
        $c = $this->config;
        $signals = [];

        // ── Fit signals ──────────────────────────────────────────────────────────────────────
        // Website: three-state digital-sales-opportunity gradient (Amendment B). Mutually exclusive;
        // has-website is the Negative side of the same gradient and is emitted below.
        $signals[] = new ScoreSignal(
            'website-no-website', 'No Website', ScoreSignal::FIT,
            $websiteStatus === 'no-website' ? $c->point('no_website') : 0, $websiteStatus === 'no-website',
        );
        $signals[] = new ScoreSignal(
            'website-needs-redesign', 'Needs Redesign', ScoreSignal::FIT,
            $websiteStatus === 'needs-redesign' ? $c->point('needs_redesign') : 0, $websiteStatus === 'needs-redesign',
        );

        $signals[] = new ScoreSignal(
            'target-location', 'Target Location', ScoreSignal::FIT,
            $inTargetLocation ? $c->point('target_location') : 0, $inTargetLocation,
        );

        // Contact Person: only a REAL, named contact (not the import fallback that copies the
        // business name into contact_name) — Amendment B B-2.
        $hasRealContact = $contactName !== null
            && trim($contactName) !== ''
            && $contactName !== $businessName;
        $signals[] = new ScoreSignal(
            'contact-person', 'Contact Person', ScoreSignal::FIT,
            $hasRealContact ? $c->point('contact_person') : 0, $hasRealContact,
        );

        // ── Engagement signals ───────────────────────────────────────────────────────────────
        $signals[] = new ScoreSignal(
            'interested-reply', 'Interested Reply', ScoreSignal::ENGAGEMENT,
            $status === 'interested' ? $c->point('interested_reply') : 0, $status === 'interested',
        );

        // Call Completed and Call Scheduled are mutually exclusive — cold_calling is single-valued,
        // so at most one fires (R-2).
        $signals[] = new ScoreSignal(
            'call-completed', 'Call Completed', ScoreSignal::ENGAGEMENT,
            $coldCalling === 'called' ? $c->point('call_completed') : 0, $coldCalling === 'called',
        );
        $signals[] = new ScoreSignal(
            'call-scheduled', 'Call Scheduled', ScoreSignal::ENGAGEMENT,
            $coldCalling === 'scheduled' ? $c->point('call_scheduled') : 0, $coldCalling === 'scheduled',
        );

        // Email Opened: hierarchical — `replied` satisfies "opened" (Amendment B B-3).
        $emailOpened = in_array($emailOutreach, ['opened', 'replied'], true);
        $signals[] = new ScoreSignal(
            'email-opened', 'Email Opened', ScoreSignal::ENGAGEMENT,
            $emailOpened ? $c->point('email_opened') : 0, $emailOpened,
        );

        $signals[] = new ScoreSignal(
            'awaiting-reply', 'Awaiting Reply', ScoreSignal::ENGAGEMENT,
            $status === 'awaiting-reply' ? $c->point('awaiting_reply') : 0, $status === 'awaiting-reply',
        );

        // Recent Activity (≤ recent window). last_activity = NULL → does NOT apply (a never-actioned
        // lead is new, not recently-active) — frozen §4.5 edge rule.
        $recent = $lastActivityAt !== null
            && $lastActivityAt->greaterThanOrEqualTo($now->copy()->subDays($c->recentDays));
        $signals[] = new ScoreSignal(
            'recent-activity', "Recent Activity (≤{$c->recentDays} days)", ScoreSignal::ENGAGEMENT,
            $recent ? $c->point('recent_activity') : 0, $recent,
        );

        // ── Negative signals ─────────────────────────────────────────────────────────────────
        $signals[] = new ScoreSignal(
            'has-website', 'Has Website', ScoreSignal::NEGATIVE,
            $websiteStatus === 'has-website' ? -$c->point('has_website') : 0, $websiteStatus === 'has-website',
        );

        // No Activity (> stale window). last_activity = NULL → does NOT apply (never-actioned = new,
        // not stale) — frozen §4.5 edge rule.
        $stale = $lastActivityAt !== null
            && $lastActivityAt->lessThan($now->copy()->subDays($c->staleDays));
        $signals[] = new ScoreSignal(
            'no-activity-30', "No Activity > {$c->staleDays} days", ScoreSignal::NEGATIVE,
            $stale ? -$c->point('no_activity') : 0, $stale,
        );

        // Invalid / Bounced Email: NOT evaluable until Gmail integration lands — there is no
        // lead-level email-validity/bounce flag today. Contributes 0, surfaced honestly (§10 / B-4).
        $signals[] = new ScoreSignal(
            'invalid-email', 'Invalid / Bounced Email', ScoreSignal::NEGATIVE,
            0, false, evaluable: false,
        );

        // Category subtotals, with the structural caps enforced as hard ceilings (§4.2/§4.3): a
        // future weight change can never push Fit past fit_max or Engagement past engagement_max.
        $fit = min($this->sum($signals, ScoreSignal::FIT), $c->fitMax);
        $engagement = min($this->sum($signals, ScoreSignal::ENGAGEMENT), $c->engagementMax);
        // Negative subtotal as a positive magnitude (matches the §4.3 presentation); no cap — the
        // floor is handled by the final clamp.
        $negative = -$this->sum($signals, ScoreSignal::NEGATIVE);

        $raw = $fit + $engagement - $negative;
        $score = max($c->scoreMin, min($c->scoreMax, $raw));

        return new LeadScoreResult(
            score: $score,
            fit: $fit,
            engagement: $engagement,
            negative: $negative,
            fitMax: $c->fitMax,
            engagementMax: $c->engagementMax,
            scoreMax: $c->scoreMax,
            signals: $signals,
        );
    }

    /** @param list<ScoreSignal> $signals */
    private function sum(array $signals, string $category): int
    {
        return array_reduce(
            array_filter($signals, fn (ScoreSignal $s): bool => $s->category === $category),
            fn (int $carry, ScoreSignal $s): int => $carry + $s->points,
            0,
        );
    }
}
