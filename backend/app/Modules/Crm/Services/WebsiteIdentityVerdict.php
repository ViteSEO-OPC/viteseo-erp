<?php

namespace App\Modules\Crm\Services;

/**
 * The outcome of checking whether a fetched page actually belongs to a lead's business
 * ({@see WebsiteIdentityVerifier}).
 *
 * Evidence, not interpretation: `signals` records every check that was evaluated and how it landed,
 * so a BD (or a later reviewer) can see WHY a URL was accepted or left unconfirmed rather than
 * trusting an opaque boolean. `confidence` is the summed weight of the matched signals; `verified`
 * is that total measured against the approved threshold.
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
