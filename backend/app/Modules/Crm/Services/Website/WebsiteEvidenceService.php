<?php

namespace App\Modules\Crm\Services\Website;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use App\Modules\Crm\Models\LeadWebsiteSearch;
use App\Modules\Crm\Services\WebsiteIdentityVerifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single WRITE boundary for website-candidate evidence, and the one place `no-website` can be
 * reached.
 *
 * WHY `no-website` HAS BEEN UNREACHABLE. {@see \App\Modules\Crm\Services\WebsiteStatusDeriver} has
 * five branches and none returns it, deliberately: proving a website does not exist requires having
 * LOOKED for one, and until now nothing looked. Every untagged OSM business therefore sat at
 * `website-unknown` forever. That is not a defect in the deriver — it is the deriver correctly
 * refusing to assert something no evidence supported.
 *
 * WHAT CHANGES HERE, AND WHAT DOES NOT. This class records the looking: which candidates were
 * proposed, by which tier, and why each was refused. It never sets `website_status` on its own.
 * {@see confirmAbsent()} does, and only when a human has read that evidence and accepted it — which
 * is the same authority a person already had through the lead-edit endpoint, now exercised against a
 * documented trail instead of a guess. The deriver, the verifier, LeadImportService and every scoring
 * file are untouched.
 *
 * EXHAUSTION IS EVIDENCE, NOT A VERDICT. A search that finds nothing is weaker than a human check —
 * a business may hold a site no tier surfaced — so {@see markExhausted()} sets a timestamp and stops.
 * Nothing downstream treats it as a status, and there is deliberately no code path from exhaustion
 * to `no-website` that does not pass through a person.
 *
 * AND EXHAUSTION IS RELATIVE TO WHERE THERE WAS TO LOOK. {@see REQUIRED_TIERS} is the second half of
 * that claim: a search may only call itself exhausted once every required tier has actually run.
 * Without it "found nothing" and "barely looked" were recorded identically, which is the one way an
 * honest human confirmation can still produce a wrong answer — the person is shown a complete trail
 * and has no way to tell it was never filled in.
 */
class WebsiteEvidenceService
{
    /**
     * The bar a DISCOVERED candidate must clear, deliberately stricter than the audit's threshold of
     * {@see WebsiteIdentityVerifier::THRESHOLD} (50).
     *
     * The audit judges a URL a human or a provider ASSERTED; this judges one we went looking for. A
     * guess should have to prove more than an assertion, because accepting a wrong candidate writes
     * `has-website` and silently removes a genuine opportunity from the board — the one error here
     * that is not recoverable. A missed site merely leaves the lead where it already was.
     */
    public const ACCEPT_CONFIDENCE = 65;

    /**
     * How far ahead of the runner-up the winner must be.
     *
     * Two candidates scoring 66 and 65 is not a confident answer, it is a coin toss between two
     * plausible pages. Requiring a margin means ambiguity resolves to "keep looking" rather than to
     * whichever candidate happened to sort first.
     */
    public const ACCEPT_MARGIN = 15;

    /**
     * The tiers a search must have run before it may call itself exhausted.
     *
     * The tier names are the candidate SOURCE values because a tier is identified by the kind of
     * evidence it produces — the two vocabularies are deliberately the same one, so a tier cannot be
     * recorded as run under a name no candidate could ever carry.
     *
     * Adding a tier here makes every existing exhausted search incomplete BY DESIGN: the claim
     * "we looked everywhere" is relative to where there was to look, and widening that set must
     * reopen the question rather than grandfather an answer given before the new place existed.
     * Existing rows are not rewritten — {@see markExhausted()} simply stops re-confirming them, and
     * a lead re-enters the queue the moment its stale `exhausted_at` is cleared.
     *
     * @var list<string>
     */
    public const REQUIRED_TIERS = [
        LeadWebsiteCandidate::SOURCE_OSM_TAG,
        LeadWebsiteCandidate::SOURCE_WIKIDATA,
        LeadWebsiteCandidate::SOURCE_PROBE,
    ];

    public function __construct(private readonly WebsiteIdentityVerifier $verifier) {}

    /** The lead's search record, or an unsaved one carrying the defaults. Never writes. */
    public function searchFor(Lead $lead): LeadWebsiteSearch
    {
        return LeadWebsiteSearch::query()->where('lead_id', $lead->id)->first()
            ?? new LeadWebsiteSearch(['lead_id' => $lead->id]);
    }

    /** @return Collection<int, LeadWebsiteCandidate> */
    public function candidatesFor(Lead $lead): Collection
    {
        return LeadWebsiteCandidate::query()
            ->where('lead_id', $lead->id)
            ->orderByDesc('confidence')
            ->orderBy('id')
            ->get();
    }

    /**
     * Record proposed candidates for a lead.
     *
     * Idempotent per (lead, url, source): re-running a tier updates the existing row rather than
     * adding another. Without that, repeatedly running the same search would inflate
     * `candidates_found` and make a weak evidence set look thorough.
     *
     * Recording a NEW candidate reopens a previously exhausted search — a lead cannot remain
     * "exhausted" while an unchecked candidate sits against it.
     *
     * @param  list<array{url: string, source: string, source_detail?: string|null}>  $candidates
     * @param  list<string>  $tiersRun
     */
    public function recordCandidates(Lead $lead, array $candidates, array $tiersRun): LeadWebsiteSearch
    {
        return DB::transaction(function () use ($lead, $candidates, $tiersRun): LeadWebsiteSearch {
            foreach ($candidates as $candidate) {
                $url = trim((string) ($candidate['url'] ?? ''));
                if ($url === '') {
                    continue;
                }

                LeadWebsiteCandidate::query()->updateOrCreate(
                    [
                        'lead_id' => $lead->id,
                        'url_hash' => LeadWebsiteCandidate::hashFor($url),
                        'source' => $candidate['source'],
                    ],
                    ['url' => $url, 'source_detail' => $candidate['source_detail'] ?? null],
                );
            }

            $search = $this->searchFor($lead);
            $search->lead_id = $lead->id;
            // Union, not replace: a later run of one tier must not erase the record that another
            // tier already ran, or an exhaustion claim would understate what was actually tried.
            $search->tiers_run = array_values(array_unique([...($search->tiers_run ?? []), ...$tiersRun]));
            $search->last_searched_at = now();

            if ($candidates !== []) {
                // New material to check — the previous exhaustion no longer describes reality.
                $search->exhausted_at = null;
            }

            $search->save();

            return $this->refreshCounts($search->refresh());
        });
    }

    /**
     * Adjudicate one candidate against the audit findings the discovery service observed.
     *
     * The division mirrors the existing audit exactly: the crawler reports what it SAW (title,
     * description, phones, emails, final/canonical URL) and the CRM decides what it MEANS, using the
     * unmodified {@see WebsiteIdentityVerifier}. Nothing about identity matching is reimplemented
     * here; only the acceptance bar is raised, for the reason given on the constants.
     *
     * @param  array<string, mixed>  $findings
     */
    public function recordVerification(LeadWebsiteCandidate $candidate, array $findings): LeadWebsiteCandidate
    {
        $lead = $candidate->lead;
        $verdict = $this->verifier->verify($lead, $findings);

        $reachable = (bool) ($findings['website_reachable'] ?? false);

        $candidate->normalized_url = $findings['canonical_url'] ?? $findings['final_url'] ?? $candidate->url;
        $candidate->confidence = $verdict->confidence;
        $candidate->signals = $verdict->signals;
        $candidate->checked_at = now();

        if (! $reachable) {
            $candidate->verified = false;
            $candidate->rejection_reason = LeadWebsiteCandidate::REJECTED_UNREACHABLE;
        } elseif ($verdict->confidence < self::ACCEPT_CONFIDENCE) {
            // Note this refuses values the AUDIT would accept (50–64). A discovered candidate that
            // only just clears the ordinary bar is not good enough to act on unprompted.
            $candidate->verified = false;
            $candidate->rejection_reason = LeadWebsiteCandidate::REJECTED_IDENTITY;
        } else {
            $candidate->verified = true;
            $candidate->rejection_reason = null;
        }

        $candidate->save();

        // Order matters: the margin rule can DEMOTE a candidate that just verified, so everything
        // after it has to ask the table what survived — not assume the candidate in hand is still
        // verified. Adjudicate, then unwind any claim the result contradicts, then publish it.
        $this->applyMargin($lead);
        $this->reconcileConfirmedAbsence($lead);
        $this->promoteVerifiedWebsite($lead);

        return $candidate->refresh();
    }

    /**
     * A verified candidate becomes the lead's website, so the ordinary audit can take it from here.
     *
     * THE STATE THIS FIXES. `crm_leads.website` is what ROUTES a lead: the audit queue selects rows
     * where it is set, the evidence queue selects rows where it is null. A lead holding a verified
     * candidate satisfied neither — it left the evidence queue (its search is answered) and could not
     * enter the audit queue (the column was still null). It sat permanently at `website-unknown` and
     * therefore Medium, while the evidence beneath it held a URL proven to belong to the business.
     * In Vigan that is not an edge case: no business there carries a website tag at all, so this is
     * the path every chain we discover takes.
     *
     * THIS WRITES A URL, NOT A VERDICT. `website_status` is untouched and stays whatever it was;
     * only the audit may change it, and only from real page findings (HTTPS, About, Contact,
     * Services) that this chain never collects. Promotion moves the lead to where those findings get
     * made — it does not anticipate them. `website` is deliberately absent from `LeadObserver`'s
     * scoring inputs, so this cannot move a Lead Score even on a qualified lead.
     *
     * WHY THIS IS NOT A NEW KIND OF WRITE. `LeadImportService::ingestDiscovered()` already backfills
     * this column from a raw provider tag with no identity check at all. The URL here cleared
     * {@see ACCEPT_CONFIDENCE} against the business's own name, phone and domain, and beat its
     * runner-up by {@see ACCEPT_MARGIN}. Refusing the stricter source while accepting the looser one
     * was the inconsistency, not this.
     *
     * NEVER OVERWRITES. Only a null column is filled, so a URL a person entered — or one an earlier
     * ingest supplied — always wins. There is no path here that replaces an existing answer.
     *
     * AND IT UNWINDS ITSELF. Candidates are verified ONE AT A TIME, so a lead can pass through a
     * moment where a single candidate is verified — and promoted — before a second arrives and
     * {@see applyMargin()} refuses both. Left alone the lead would keep a URL its own evidence had
     * just rejected, and would be audited on it. So when no verified candidate remains, a promotion
     * this method can PROVE it made is withdrawn: the stored URL must match a candidate on this lead
     * that the margin rule refused. That proof is what keeps the withdrawal away from a human's URL
     * or an ingest's. Unlike the absence-confirmation path, retreating here IS the neutral direction
     * — the lead returns to `website` null and to the evidence queue that was already searching it.
     */
    private function promoteVerifiedWebsite(Lead $lead): void
    {
        // Re-read rather than trust the candidate in hand: applyMargin may have just demoted it.
        $verified = LeadWebsiteCandidate::query()
            ->where('lead_id', $lead->id)->where('verified', true)->first();

        if (! $verified) {
            $this->withdrawPromotedWebsite($lead);

            return;
        }

        // A column that already holds an answer is somebody else's decision — a human's, or an
        // earlier ingest's. Promotion fills a gap; it never settles a disagreement.
        if ($lead->website !== null && trim((string) $lead->website) !== '') {
            return;
        }

        $url = $verified->normalized_url ?: $verified->url;
        if ($url === null || trim($url) === '') {
            return;
        }

        // The evidence row is left exactly as it is — url, normalized_url, confidence, signals and
        // verified all stay put. This copies the URL onward; it never becomes the record of why.
        $lead->update(['website' => $url]);

        Log::info('Verified website candidate promoted to the lead', [
            'lead_id' => $lead->id,
            'candidate_id' => $verified->id,
            'url' => $url,
            'confidence' => $verified->confidence,
        ]);
    }

    /**
     * Take back a promotion this class made, once the candidate behind it stopped being verified.
     *
     * ONLY WHAT IT CAN PROVE IT WROTE. The stored URL must match a candidate on this lead that the
     * margin rule refused — the single situation in which a promotion is known to have been
     * invalidated after the fact. A URL a person typed, or one an ingest supplied, matches no such
     * candidate and is left exactly where it is.
     */
    private function withdrawPromotedWebsite(Lead $lead): void
    {
        $website = trim((string) $lead->website);
        if ($website === '') {
            return;
        }

        $ours = LeadWebsiteCandidate::query()
            ->where('lead_id', $lead->id)
            ->where('rejection_reason', LeadWebsiteCandidate::REJECTED_MARGIN)
            ->where(fn ($q) => $q->where('url', $website)->orWhere('normalized_url', $website))
            ->exists();

        if (! $ours) {
            return;
        }

        $lead->update(['website' => null]);

        Log::info('Promoted website withdrawn — no candidate remains verified', [
            'lead_id' => $lead->id,
            'url' => $website,
        ]);
    }

    /**
     * A verified website contradicts an earlier confirmation of absence — withdraw the confirmation.
     *
     * THE STATE THIS EXISTS TO PREVENT. A human confirms a business has no website; a later search
     * deterministically verifies one. Without this, the lead keeps `no-website` and therefore keeps
     * High Priority — the strongest signal on the Nearby board — while the evidence beneath it says a
     * website exists at high confidence. The board would be pointing BDs at a greenfield opportunity
     * that provably is not one.
     *
     * DIRECTION IS THE WHOLE SAFETY ARGUMENT. This reverts `no-website` to `website-unknown` and
     * stops there. It never writes `no-website`, never guesses `has-website`, and never touches a
     * status a human has since set to something else. Retreating to neutral when the evidence stops
     * supporting a strong claim is safe; asserting a strong claim automatically is exactly what the
     * rest of this class refuses to do. `website-unknown` then leaves the existing verification and
     * Website Intelligence flow to determine the real state, which is its job, not this method's.
     *
     * The confirmation itself is invalidated rather than erased — see the migration for why.
     */
    private function reconcileConfirmedAbsence(Lead $lead): void
    {
        $verified = LeadWebsiteCandidate::query()
            ->where('lead_id', $lead->id)->where('verified', true)->first();

        if (! $verified) {
            return;
        }

        $search = $this->searchFor($lead);

        // An exhausted search that holds a verified candidate is self-contradictory: markExhausted()
        // refuses that combination outright, so it can only arise from a verification recorded after
        // the fact. Clear it for the same reason the confirmation goes — the search plainly did not
        // come up empty.
        if ($search->exists && $search->isExhausted()) {
            $search->exhausted_at = null;
            $search->save();
        }

        if (! $search->exists || ! $search->isConfirmedAbsent()) {
            return;
        }

        DB::transaction(function () use ($lead, $search, $verified): void {
            $search->confirmation_invalidated_at = now();
            $search->confirmation_invalidated_reason = 'A website was verified for this business after the confirmation.';
            $search->save();

            // Only ever unwind OUR OWN claim. If a person has since moved the lead to some other
            // status, that is a more recent human decision than the one being withdrawn.
            if ($lead->website_status === 'no-website') {
                $lead->update(['website_status' => 'website-unknown']);
            }

            Log::warning('Confirmed website absence withdrawn — a website was verified', [
                'lead_id' => $lead->id,
                'candidate_id' => $verified->id,
                'url' => $verified->normalized_url ?? $verified->url,
                'confidence' => $verified->confidence,
                'originally_confirmed_by' => $search->confirmed_by,
            ]);
        });
    }

    /**
     * Enforce the margin rule across a lead's checked candidates.
     *
     * Run after every verification rather than at read time, so the stored rows always agree with
     * the rule. A winner that fails to beat its runner-up by {@see ACCEPT_MARGIN} is demoted to
     * refused with `insufficient_margin` — the evidence records that it was close, not that it was
     * right.
     */
    private function applyMargin(Lead $lead): void
    {
        $checked = LeadWebsiteCandidate::query()
            ->where('lead_id', $lead->id)
            ->whereNotNull('checked_at')
            ->orderByDesc('confidence')
            ->get();

        $accepted = $checked->where('confidence', '>=', self::ACCEPT_CONFIDENCE)->values();
        if ($accepted->count() < 2) {
            return;
        }

        $winner = $accepted[0];
        $runnerUp = $accepted[1];

        // Same domain proposed by two tiers is corroboration, not a tie — it should strengthen the
        // case, never weaken it.
        if ($this->host($winner->normalized_url ?? $winner->url) === $this->host($runnerUp->normalized_url ?? $runnerUp->url)) {
            return;
        }

        if (($winner->confidence - $runnerUp->confidence) >= self::ACCEPT_MARGIN) {
            return;
        }

        foreach ($accepted as $candidate) {
            $candidate->verified = false;
            $candidate->rejection_reason = LeadWebsiteCandidate::REJECTED_MARGIN;
            $candidate->save();
        }
    }

    /**
     * Mark the search exhausted: every listed tier ran and no candidate survived.
     *
     * Refuses while an unchecked candidate remains — an exhaustion claim resting on candidates
     * nobody tested would be worthless — and refuses while one is verified, since the lead has a
     * website and the ordinary audit path owns it from here.
     */
    public function markExhausted(Lead $lead): LeadWebsiteSearch
    {
        $search = $this->refreshCounts($this->searchFor($lead));

        $pending = LeadWebsiteCandidate::query()
            ->where('lead_id', $lead->id)->whereNull('checked_at')->exists();
        $verified = LeadWebsiteCandidate::query()
            ->where('lead_id', $lead->id)->where('verified', true)->exists();

        if ($pending || $verified) {
            return $search;
        }

        // EVERY REQUIRED TIER MUST HAVE RUN. `tiers_run` was recorded from the start but nothing
        // enforced it, so a run that only read OSM tags and Wikidata could claim exhaustion — and
        // those two tiers are LOOKUPS, not searches: measured, `brand:wikidata` covers 0% of
        // dentists, clinics and salons, which is exactly where genuine absences live. Letting that
        // count as "we looked everywhere" is what put a human in front of an empty trail and invited
        // them to confirm `no-website` on the strength of a search that never happened.
        //
        // Refused silently, like the guards above: the caller re-declares exhaustion on its next
        // pass, and a lead that is not yet exhausted simply stays in the queue.
        if (array_diff(self::REQUIRED_TIERS, $search->tiers_run ?? []) !== []) {
            return $search;
        }

        $search->lead_id = $lead->id;
        $search->exhausted_at = now();
        $search->save();

        return $search->refresh();
    }

    /**
     * A HUMAN accepts the evidence: no credible website exists.
     *
     * THE ONLY PATH TO `no-website`, and the only place in the codebase that writes it outside the
     * ordinary lead-edit endpoint. Guarded on the evidence actually existing — a confirmation
     * without an exhausted search would be the same unfounded assertion the old behaviour made, just
     * with a person's name attached.
     *
     * Writes `website_status` directly, exactly as a human edit always has. The deriver is not
     * involved and is not modified; it still cannot return this value, and nothing automated can.
     *
     * @throws \RuntimeException when the evidence does not support the claim
     */
    public function confirmAbsent(Lead $lead, User $actor, ?string $note = null): LeadWebsiteSearch
    {
        $search = $this->searchFor($lead);

        if (! $search->exists || ! $search->isExhausted()) {
            throw new \RuntimeException('Cannot confirm absence before the website search is exhausted.');
        }

        if (LeadWebsiteCandidate::query()->where('lead_id', $lead->id)->where('verified', true)->exists()) {
            throw new \RuntimeException('A verified website exists for this lead.');
        }

        return DB::transaction(function () use ($lead, $actor, $note, $search): LeadWebsiteSearch {
            $search->confirmed_absent_at = now();
            $search->confirmed_by = $actor->id;
            $search->confirmation_note = $note;
            $search->save();

            // The human decision, applied. `update` (not updateQuietly) on purpose: LeadObserver
            // should see it exactly as it sees any other human edit to website_status — that is the
            // existing, frozen behaviour and this must not be a special case.
            $lead->update(['website_status' => 'no-website']);

            Log::info('Website absence confirmed', [
                'lead_id' => $lead->id,
                'actor_id' => $actor->id,
                'candidates_rejected' => $search->candidates_rejected,
            ]);

            return $search->refresh();
        });
    }

    /** Recount from the candidate rows, so the summary can never drift from the evidence. */
    private function refreshCounts(LeadWebsiteSearch $search): LeadWebsiteSearch
    {
        if (! $search->exists) {
            return $search;
        }

        $search->candidates_found = LeadWebsiteCandidate::query()->where('lead_id', $search->lead_id)->count();
        $search->candidates_rejected = LeadWebsiteCandidate::query()
            ->where('lead_id', $search->lead_id)->where('verified', false)->count();
        $search->save();

        return $search;
    }

    private function host(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', mb_strtolower($host)) : null;
    }
}
