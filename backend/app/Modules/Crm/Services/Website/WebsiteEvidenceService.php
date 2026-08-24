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

        $this->applyMargin($lead);

        return $candidate->refresh();
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
