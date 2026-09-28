<?php

namespace App\Modules\Crm\Services\Website;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Discovery\AiChatEndpoint;
use App\Modules\Crm\Services\Discovery\AiStrategyCapability;
use App\Modules\Crm\Services\Discovery\NerbyProviderSettingService;
use App\Modules\Crm\Services\Discovery\Prompts\NerbyTrustBoundary;
use App\Modules\Crm\Services\Discovery\UntrustedText;
use App\Modules\Crm\Services\WebsiteIdentityVerdict;
use App\Modules\Crm\Services\WebsiteIdentityVerifier;
use App\Support\CurrentOrg;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI-assisted adjudication of an AMBIGUOUS website-identity verdict.
 *
 * WHAT PROBLEM THIS SOLVES. {@see WebsiteIdentityVerifier} decides ownership by lexical overlap —
 * does the domain, title, phone, or email domain echo the business name? That is exact and cheap and
 * it is right most of the time, but it cannot read. A rebrand ("Kopi Roasters" trading as
 * `bluemountain.ph`), a parent company's site, a franchise page, or a name written in a different
 * script all fire ONE strong signal and then fail to corroborate — landing at `website-unknown`,
 * which is indistinguishable from "never checked". This class asks a language model the question the
 * token matcher cannot: does this page plausibly belong to this business?
 *
 * ADVISORY ONLY — THE RETURN TYPE IS THE GUARANTEE. {@see evidenceFor()} returns a plain evidence
 * array, never a {@see WebsiteIdentityVerdict}. It is therefore not possible for this class to hand
 * back something the pipeline could mistake for a decision: there is no code path by which its output
 * can reach `identity_verified`, `identity_confidence`, `website_status`, `score`, or `priority`.
 * That is deliberate, and it is why the method is not called `adjudicate` — an earlier draft returned
 * a verdict, and a verdict is exactly the thing an advisory layer must not be able to produce.
 *
 * WHY IT MATTERS THAT THE OUTPUT IS TERMINAL. `website_status` is a FROZEN Lead Score input: change
 * it and LeadObserver refreshes `leads.score` under the approved formula. A layer that could promote
 * an identity would therefore be moving scores — not by rewriting a rule, but by feeding a rule a
 * different input, which for a frozen pipeline is the same thing. So the model's opinion is recorded
 * as evidence a human can act on, and nothing consumes it automatically.
 *
 * THE DETERMINISTIC RESULT IS NEVER TOUCHED. {@see WebsiteIdentityVerifier} decides identity;
 * {@see WebsiteStatusDeriver} owns `website_status`; `no-website` stays unreachable from any
 * automated path. This class reads the verdict only to decide whether asking is worthwhile.
 *
 * ── WHAT CROSSES THE BOUNDARY, AND WHAT CANNOT ────────────────────────────────────────────────
 *
 * OUTBOUND, the packet is an ALLOWLIST built in {@see evidence()} and pinned by
 * WebsiteAiAuditSecurityTest as a closed set. It carries the business name, city, industry and
 * email DOMAIN; the page's public URLs, title, description, structural booleans, published email
 * DOMAINS and social links; and the deterministic verdict. It carries NO credential, no token, no
 * full email address, NO PHONE NUMBER, no raw HTML or response body, no place id, no coordinates,
 * no owner, score, status or priority, and no private, loopback or metadata address
 * ({@see publicUrl()}).
 *
 * THIS CLASS MAKES EXACTLY ONE OUTBOUND REQUEST, to the chat endpoint, and it is the only one it
 * can make. It fetches no URL, follows no redirect, resolves no name and touches no filesystem —
 * including for {@see publicUrl()}, which is a literal-address check precisely so that guarding a
 * disclosure does not introduce a network operation of its own. Nothing downstream consumes the
 * model's answer as a URL, a crawler target, an internal endpoint, a query or markup: the answer is
 * five words, a bounded integer and one cleaned sentence, stored and read by nobody.
 *
 * THE AUTHORITATIVE NETWORK-FETCH BOUNDARY IS ELSEWHERE and stays there: scraper/net_guard.py
 * (public addresses only, ports 80/443, per-hop redirect revalidation, pinned-IP connect). This
 * class never fetches, so it is not a second implementation of that guard and must not become one.
 *
 * ── AN EXTERNAL PROCESSOR, ON TERMS WE HAVE NOT VERIFIED ──────────────────────────────────────
 *
 * OpenRouter receives real business names, cities and page text. NOTHING HERE MAY BE READ AS A
 * CLAIM THAT THE PROVIDER IS PRIVACY-SAFE OR ZERO-RETENTION — that is a contractual property, not a
 * technical one, and it has not been established. BEFORE ENABLING THIS IN PRODUCTION, VERIFY THE
 * CURRENT OPENROUTER PRIVACY, DATA-RETENTION AND TRAINING / DATA-USE TERMS APPLICABLE TO API
 * TRAFFIC. The switch defaults false so that check cannot be skipped by accident.
 *
 * FAILURE IS ALWAYS THE STATUS QUO. Disabled, unconfigured, timed out, rate-limited, non-2xx,
 * malformed JSON, unparseable answer: every path returns null and the caller persists exactly what
 * it would have persisted without this class existing.
 */
class LlmIdentityAdjudicator
{
    /**
     * The floor of the ambiguous band, derived from the verifier's own weights rather than picked.
     *
     * The verifier is built so that no SINGLE signal reaches its threshold — every acceptance needs
     * corroboration. 30 is the smallest strong-signal weight (`title_matches_name`), so
     * `[30, THRESHOLD)` is precisely the set of verdicts meaning "one strong signal fired and nothing
     * backed it up". That is the population worth a second look.
     *
     * Below 30 there is no strong signal at all — at most a city name, which every business in that
     * city shares. Asking a model to adjudicate on that is asking it to guess, and a guess recorded
     * as evidence is worse than an honest `website-unknown`.
     */
    public const AMBIGUOUS_MIN = 30;

    /**
     * THE FIVE ANSWERS, and the reason the contract is not a boolean.
     *
     * It was `{"match": true|false}` with "when unsure, answer false" in the prompt, which made
     * "this page belongs to someone else" and "I cannot tell from this" the SAME answer. They demand
     * opposite handling: one is a finding, the other is a request for more evidence, and collapsing
     * them is how an absence gets asserted over a question nobody answered.
     */
    public const VERDICTS = [
        'verified',
        'rejected',
        'ambiguous',
        'insufficient_evidence',
        'needs_verification',
    ];

    /** Bumped whenever the prompt or the contract moves — it is part of the reuse key. */
    public const PROMPT_VERSION = 2;

    /** Bounds on untrusted page text entering the packet. Long enough to reason on, short enough to bill. */
    private const MAX_TITLE = 200;

    private const MAX_DESCRIPTION = 600;

    private const MAX_REASON = 300;

    /**
     * Ceiling on the raw provider body before anything decodes it. `max_tokens` bounds a provider
     * that is behaving; this bounds one that is not.
     */
    private const MAX_RESPONSE_BYTES = 65536;

    public function __construct(
        private readonly NerbyProviderSettingService $settings,
        private readonly AiStrategyCapability $capability,
    ) {}

    /**
     * The model's opinion about an ambiguous verdict, or null when there is nothing to say.
     *
     * The verdict parameter is READ ONLY — it decides whether asking is worth the call, and is never
     * modified, copied, or returned. The caller persists it exactly as the deterministic verifier
     * produced it, whatever comes back from here.
     *
     * A negative answer is returned too, not discarded. "We asked and the model said no" is why a
     * borderline-looking lead stayed unconfirmed, and an absent record would make it look as though
     * nothing had been checked at all.
     *
     * @param  array<string, mixed>  $findings  the submitted audit payload
     * @return array<string, mixed>|null  evidence for `identity_signals['ai_adjudication']`
     */
    public function evidenceFor(Lead $lead, array $findings, WebsiteIdentityVerdict $verdict): ?array
    {
        if (! $this->shouldAsk($verdict)) {
            return null;
        }

        return $this->ask($lead, $findings, $verdict);
    }

    /**
     * The gate. Everything that makes this layer optional lives here, in one place.
     *
     * Order matters only for cost: the free checks (flag, key, verdict shape) run before anything
     * that could reach the network.
     */
    private function shouldAsk(WebsiteIdentityVerdict $verdict): bool
    {
        // ITS OWN SWITCH, then the SHARED credential. The switch is checked first because it is
        // free; the credential check reads one row and never the network. `credential()` rather
        // than `status()` deliberately: which provider executes a SEARCH has nothing to do with
        // whether a page can be reasoned about, and gating on it would make website auditing
        // impossible whenever discovery runs on the OSM baseline.
        if (! config('discovery.website_audit.enabled')) {
            return false;
        }

        if ($this->capability->credential() !== AiStrategyCapability::AVAILABLE) {
            return false;
        }

        // A verified verdict is settled — there is nothing for an advisory opinion to add, and asking
        // would only spend money to produce a record nobody reads.
        if ($verdict->verified) {
            return false;
        }

        return $verdict->confidence >= self::AMBIGUOUS_MIN
            && $verdict->confidence < WebsiteIdentityVerifier::THRESHOLD;
    }

    /**
     * Ask the model, and return its answer only if it is well-formed. Null means "no usable answer",
     * which every caller treats as "keep the deterministic verdict".
     *
     * @param  array<string, mixed>  $findings
     * @return array<string, mixed>|null
     */
    private function ask(Lead $lead, array $findings, WebsiteIdentityVerdict $verdict): ?array
    {
        $row = $this->settings->for(NerbyProvider::OPENAI);
        $model = (string) ($row->option('model') ?: config('openai.model', 'gpt-4o-mini'));
        $endpoint = AiChatEndpoint::for((string) $row->api_key, $model);

        $packet = $this->evidence($lead, $findings, $verdict);
        $fingerprint = hash('sha256', $packet);

        // ONE ANSWER PER (ORG, PROMPT, EVIDENCE). The org is in the key because a cached opinion
        // about one tenant's lead must never be served as evidence about another's — the same rule
        // the strategist's cache already follows. The evidence is in the key because a page that
        // changed is a different question, which is what makes reuse safe rather than stale.
        //
        // NO ORG MEANS NO CACHE, NOT A SHARED BUCKET. An `?? 'none'` fallback would file every
        // unpinned context — a console run, a job that lost its context — into ONE namespace that
        // every tenant reads from, which is a cross-tenant oracle built out of a convenience
        // default. Losing reuse costs money; sharing it costs isolation.
        $org = CurrentOrg::id();
        $key = $org === null
            ? null
            : 'crm:website-audit:'.self::PROMPT_VERSION.'|org:'.$org.'|'.$fingerprint;

        if ($key !== null) {
        // CACHED ADVICE IS RE-VALIDATED, NOT TRUSTED. The cache is shared infrastructure that
        // outlives a deploy: an entry could have been written by an older contract, corrupted, or —
        // on a shared store — tampered with. Reading it back through the same shape check the live
        // path uses means a bad entry is a cache MISS, never a record with arbitrary keys landing in
        // `identity_signals`.
            $cached = Cache::get($key);
            if ($this->isWellFormedAdvice($cached)) {
                return $cached;
            }
        }

        try {
            $response = Http::withToken((string) $row->api_key)
                ->timeout((float) config('discovery.website_audit.timeout', 25))
                ->post($endpoint->chatCompletionsUrl(), [
                    'model' => $endpoint->model,
                    // Deterministic sampling: the same evidence must not produce different verdicts
                    // on two runs, or the persisted audit trail stops meaning anything.
                    'temperature' => 0,
                    'max_tokens' => (int) config('discovery.website_audit.max_tokens', 200),
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $packet],
                    ],
                ]);
        } catch (ConnectionException) {
            // Timeout or DNS/socket failure. Expected under load; not worth an error-level log.
            return null;
        } catch (Throwable $e) {
            // The CLASS only. The request carried the credential and an exception message can quote
            // the request — the same rule NerbyProviderSettingService::probe() already follows.
            Log::warning('Website identity adjudication failed', [
                'lead_id' => $lead->id,
                'class' => $e::class,
            ]);

            return null;
        }

        if ($response->failed()) {
            // A REFUSAL FOR CREDIT IS REPORTED WHERE EVERY OTHER LANE REPORTS IT, so the settings
            // card and /discovery/config learn the account is empty without anyone re-testing. The
            // shared classifier owns the status→reason mapping; this call site does not re-derive it.
            if (AiChatEndpoint::classify($response->status()) === AiChatEndpoint::FAILURE_INSUFFICIENT_CREDIT) {
                $this->capability->recordInsufficientCredit();
            }

            return null;
        }

        // A COOPERATIVE PROVIDER IS BOUNDED BY `max_tokens`; A HOSTILE OR BROKEN ONE IS NOT. The
        // body is measured before anything decodes it, so a multi-megabyte answer costs one
        // strlen rather than a JSON parse and the memory behind it.
        $body = (string) $response->body();
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            Log::warning('Website identity adjudication answer discarded: oversized', [
                'lead_id' => $lead->id,
                'bytes' => strlen($body),
            ]);

            return null;
        }

        $parsed = $this->parse((string) $response->json('choices.0.message.content', ''));
        if ($parsed === null) {
            return null;
        }

        $advice = [
            ...$parsed,
            // ENOUGH TO REPRODUCE THE DECISION AND NOTHING MORE. The fingerprint identifies the
            // evidence without reproducing it, so the trail cannot become a second copy of a page.
            'model' => $endpoint->model,
            'prompt_version' => self::PROMPT_VERSION,
            'evidence_fingerprint' => $fingerprint,
            'org_id' => $org,
            'decided_at' => now()->toIso8601String(),
            'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
        ];

        if ($key !== null) {
            Cache::put($key, $advice, (int) config('discovery.website_audit.cache_ttl', 604800));
        }

        return $advice;
    }

    /**
     * @return array{classification: string, match: bool, ai_confidence: int, reason: string}|null
     */
    private function parse(string $content): ?array
    {
        $data = json_decode($content, true);
        if (! is_array($data)) {
            return null;
        }

        // AN UNRECOGNISED WORD IS NOT A SIXTH STATE. Anything outside the contract is discarded
        // whole rather than coerced to the nearest member: a verdict nobody defined is not evidence.
        $classification = $data['classification'] ?? null;
        if (! is_string($classification) || ! in_array($classification, self::VERDICTS, true)) {
            return null;
        }

        // SCALARS ONLY. `is_numeric` refuses an array or object, and the cast below can therefore
        // never meet one — `(int) []` and `(string) []` are exactly the kind of "Array to string
        // conversion" that turns a hostile answer into a 500 on an audit that should have shrugged.
        $confidence = is_numeric($data['confidence'] ?? null) ? (int) $data['confidence'] : 0;
        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';

        return [
            'classification' => $classification,
            // KEPT, DERIVED, NEVER AUTHORITATIVE. The boolean predates the five states and the
            // stored trail is read by things written against it; deriving it means the two can
            // never disagree, and "not verified" stays the honest reading of every other state.
            'match' => $classification === 'verified',
            // The model's OWN confidence, named apart from the verifier's so the two can never be
            // mistaken for one another in the stored record. It is descriptive metadata only.
            'ai_confidence' => max(0, min(100, $confidence)),
            // Cleaned through the shared boundary: a reason is a value, never a layout.
            'reason' => UntrustedText::clean($reason, self::MAX_REASON),
        ];
    }

    /**
     * STATIC TEXT, NOTHING INTERPOLATED — the whole injection defence rests on that.
     *
     * The instructions are a compile-time constant, so no value from a page, a provider or a
     * business can become part of them. Every runtime value travels separately in the user message
     * under `untrusted_target_data`. The shared {@see NerbyTrustBoundary} is appended so this lane
     * holds exactly the line the three discovery lanes hold.
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You judge whether a web page belongs to a specific real-world business.

        You are given facts a crawler extracted from a page and a CRM's record of a business, under
        "untrusted_target_data". Decide what the EVIDENCE supports — not what is probably true.

        You are one opinion inside a system that has already decided this case deterministically.
        Your answer is recorded as evidence for a human and changes nothing by itself. So the useful
        answer is an honest one, including when it is "I cannot tell".

        Classify into exactly one of:
        - "verified" — the evidence positively ties this page to THIS business.
        - "rejected" — the evidence positively shows it belongs to someone else, or to nobody.
        - "ambiguous" — real evidence points both ways, or one signal fires and nothing supports it.
        - "insufficient_evidence" — there is too little here to judge at all.
        - "needs_verification" — it could be settled, but only by a check that has not been made.

        "rejected" AND "insufficient_evidence" ARE NOT THE SAME ANSWER. The first says the page is
        not theirs; the second says you do not know. Never use one for the other, and never resolve
        doubt by picking the negative — an honest "insufficient_evidence" is more useful than a
        confident guess in either direction.

        A brand's official site DOES belong to a branch of that brand: a corporate page legitimately
        names the brand and never the branch, and that is not evidence against it.

        A directory listing, marketplace profile, social page or parked domain is not the business's
        own website. A rebrand, a parent or franchise company, or a differently-transliterated name
        CAN still be their site when other evidence agrees.

        Never assert a fact the evidence does not contain. You have no knowledge of this business
        beyond what is given: do not supply a URL, a phone number, an address, an identifier or any
        other detail, and do not guess one.

        Reply with JSON only:
        {"classification": "<one of the five>", "confidence": 0-100, "reason": "<one short sentence>"}
        PROMPT.NerbyTrustBoundary::TEXT;
    }

    /**
     * The evidence packet — EXTRACTED FIELDS ONLY, never raw page HTML, wrapped in the untrusted
     * envelope every NERBY model call uses.
     *
     * THREE REASONS THE MARKUP NEVER TRAVELS. Injection: a hostile or careless page cannot smuggle
     * instructions into a prompt it is never part of. Cost: a packet is a few hundred tokens where a
     * page is tens of thousands. Privacy: nothing the crawler saw beyond these fields leaves the CRM.
     *
     * EVERY FREE-TEXT VALUE IS CLEANED AND BOUNDED through the shared {@see UntrustedText}, so a
     * title cannot lay itself out as a new instruction block with newlines, control characters or
     * zero-width padding. Only the lead fields the judgement actually needs are sent — no owner, no
     * score, no status, no identifiers, no credential.
     *
     * @param  array<string, mixed>  $findings
     */
    private function evidence(Lead $lead, array $findings, WebsiteIdentityVerdict $verdict): string
    {
        $packet = [
            'untrusted_target_data' => [
                'business' => array_filter([
                    'name' => UntrustedText::clean((string) $lead->business_name, 200),
                    'city' => $lead->city === null ? null : UntrustedText::clean((string) $lead->city, 100),
                    // NO PHONE, DELIBERATELY — neither the lead's nor the page's. Whether the two
                    // agree is a question the deterministic verifier has ALREADY answered, exactly,
                    // on the last seven digits; its `phone_match` signal travels below and carries
                    // the whole reasoning value. Sending the numbers as well would hand a third
                    // party a contact detail to re-derive a conclusion it is already given.
                    // DATA MINIMIZATION FIRST: if a field's answer is already in the packet, the
                    // field is not necessary.
                    // DOMAIN ONLY — see emailDomains(). The identity question is whose domain the
                    // address sits on; the local part is a person's contact detail and stays here.
                    'email_domain' => $this->emailDomains([$lead->email], 1)[0] ?? null,
                    'industry' => $lead->industry === null ? null : UntrustedText::clean((string) $lead->industry, 100),
                ]),
                'page' => array_filter([
                    'requested_url' => $this->publicUrl($lead->website),
                    'final_url' => $this->publicUrl(is_string($findings['final_url'] ?? null) ? $findings['final_url'] : null),
                    'canonical_url' => $this->publicUrl(is_string($findings['canonical_url'] ?? null) ? $findings['canonical_url'] : null),
                    'https' => (bool) ($findings['https_enabled'] ?? false),
                    'title' => UntrustedText::clean((string) ($findings['website_title'] ?? ''), self::MAX_TITLE),
                    'description' => UntrustedText::clean((string) ($findings['website_description'] ?? ''), self::MAX_DESCRIPTION),
                    'has_about_page' => (bool) ($findings['about_page'] ?? false),
                    'has_contact_page' => (bool) ($findings['contact_page'] ?? false),
                    'has_services_page' => (bool) ($findings['services_page'] ?? false),
                    'email_domains_on_page' => $this->emailDomains($findings['emails_found'] ?? [], 5),
                    'social_links' => array_values(array_filter(array_map(
                        fn (mixed $u): ?string => $this->publicUrl(is_string($u) ? $u : null),
                        [
                            $findings['facebook_url'] ?? null,
                            $findings['instagram_url'] ?? null,
                            $findings['linkedin_url'] ?? null,
                        ],
                    ))),
                ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []),
            ],
            // WHAT THE DETERMINISTIC LAYER ALREADY CONCLUDED, so the model adds to a judgement rather
            // than repeating it. A TRUSTED sibling of the envelope, never inside it — it did not come
            // from the page.
            'deterministic_verdict' => [
                'confidence' => $verdict->confidence,
                'signals' => array_keys(array_filter($verdict->signals, static fn (mixed $v): bool => $v === true)),
            ],
        ];

        return json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ?: '{}';
    }

    /**
     * Is this a record this class would have produced — checked on the way OUT of the cache.
     *
     * The live path validates a model's answer; without this the cache is a second, unvalidated
     * entrance to the same field. Shape only: it cannot tell a true opinion from a false one, and is
     * not trying to.
     */
    private function isWellFormedAdvice(mixed $advice): bool
    {
        return is_array($advice)
            && is_string($advice['classification'] ?? null)
            && in_array($advice['classification'], self::VERDICTS, true)
            && is_int($advice['ai_confidence'] ?? null)
            && is_string($advice['reason'] ?? null)
            && is_int($advice['prompt_version'] ?? null)
            && $advice['prompt_version'] === self::PROMPT_VERSION;
    }

    /**
     * A URL fit to leave our infrastructure, or null.
     *
     * TWO SEPARATE REASONS, AND THE SECOND IS THE SECURITY ONE. `crm_leads.website` is free text
     * that arrives from a provider or a spreadsheet, so it can hold `http://10.0.0.5/admin`,
     * `http://localhost:8001`, or the cloud metadata address. Sending such a value to an external
     * processor describes our internal network to a third party for no benefit whatsoever — the
     * host is also worthless for deciding who a business is. Anything not plainly a public
     * http(s) address is dropped rather than repaired.
     *
     * This is a DISCLOSURE guard, not an SSRF guard: nothing in this class fetches anything. The
     * fetching path has its own, in scraper/net_guard.py.
     */
    private function publicUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        // Names that never describe a customer's website, and resolve inside our own perimeter.
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            return null;
        }

        // A literal address is only allowed when it is unambiguously public. A hostname is left
        // alone: resolving it here would be a DNS lookup this class has no business making.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $public = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );

            if ($public === false) {
                return null;
            }
        }

        return $url;
    }

    /**
     * The DOMAINS of the addresses a page published, never the addresses themselves.
     *
     * The only thing an identity judgement does with an email is ask whose domain it sits on — that
     * is exactly what the deterministic `email_domain_match` signal asks. The local part adds
     * nothing to that question and is a named individual's contact detail, so it does not leave the
     * CRM. The same reduction is applied to the lead's own address.
     *
     * @return list<string>
     */
    private function emailDomains(mixed $emails, int $max): array
    {
        $domains = [];

        foreach (array_slice((array) $emails, 0, $max) as $email) {
            if (! is_string($email) || ! str_contains($email, '@')) {
                continue;
            }

            $domain = UntrustedText::clean(mb_strtolower((string) substr(strrchr($email, '@') ?: '', 1)), 100);
            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }
}
