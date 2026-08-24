<?php

namespace App\Modules\Crm\Services;

/**
 * The outcome of checking whether a fetched page actually belongs to a lead's business
 * ({@see WebsiteIdentityVerifier}).
 *
 * Evidence, not interpretation: `signals` records every check that was evaluated and how it landed,
 * so a BD (or a later reviewer) can see WHY a URL was accepted or left unconfirmed rather than
 * trusting an opaque boolean. `confidence` is the summed weight of the matched signals; `verified`
 * is that total measured against the approved threshold — and nothing else. This verdict is produced
 * only by {@see WebsiteIdentityVerifier} and is never rewritten downstream.
 *
 * The optional AI second opinion
 * ({@see \App\Modules\Crm\Services\Website\LlmIdentityAdjudicator}) deliberately does NOT participate
 * in this object. It is advisory evidence persisted beside the verdict, under
 * `identity_signals['ai_adjudication']`, precisely so that a model's opinion can never reach
 * `website_status` — a frozen Lead Score input — and move a score.
 */
final readonly class WebsiteIdentityVerdict
{
    /** @param array<string, bool> $signals check name → whether it matched */
    public function __construct(
        public bool $verified,
        public int $confidence,
        public array $signals,
    ) {}

    /** No page to judge (no URL, or the site never responded) — absence of evidence, not a refusal. */
    public static function notEvaluated(): self
    {
        return new self(verified: false, confidence: 0, signals: []);
    }
}
