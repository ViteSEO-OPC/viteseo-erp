<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Website\WebsiteUrlClassifier;

/**
 * Decides whether an audited page actually belongs to the lead's business.
 *
 * A reachable page is NOT evidence that a business has a website — the URL may have come from a
 * mis-tagged OSM node, a parked domain, a directory listing, or a redirect to an unrelated host.
 * Before Website Intelligence may report `has-website`, the page has to be tied back to the business
 * we hold: its name, its phone, its email domain, its city.
 *
 * Deliberately in the CRM, not the scraper. Matching a page against a Lead is business logic and the
 * scraper is a strict data provider that "never runs CRM business logic" (scraper README) — it also
 * does not hold the lead's phone/email/city. So the provider reports what it SAW (title, description,
 * phones, emails, the final and canonical URLs) and the CRM, which owns the Lead, decides what it MEANS.
 *
 * Identity is judged against the CANONICAL (else final, else requested) URL, so a redirect away from
 * the discovered domain is judged on where it actually landed. Weak signals cannot pass alone: the
 * threshold is set so at least one strong signal (domain, title, phone, or email domain) plus
 * corroboration is required. Anything short of that leaves the lead `website-unknown` — unconfirmed,
 * never silently downgraded to "no website".
 */
class WebsiteIdentityVerifier
{
    public function __construct(private readonly WebsiteUrlClassifier $classifier = new WebsiteUrlClassifier) {}

    /** Confidence needed to call a page the business's own site. */
    public const THRESHOLD = 50;

    /**
     * Signal weights. Chosen so that no SINGLE signal reaches the threshold — every acceptance needs
     * corroboration — while any two strong signals clear it comfortably.
     */
    private const WEIGHTS = [
        'domain_matches_name' => 40,
        'title_matches_name' => 30,
        'phone_match' => 35,
        'email_domain_match' => 40,
        'city_match' => 10,
    ];

    /**
     * Words that carry no identity and must not be matched on. Without this, "ABC Trading Inc." and
     * "XYZ Trading Inc." would look alike, and every Philippine business would "match" a `.ph` host.
     */
    private const STOPWORDS = [
        'inc', 'incorporated', 'corp', 'corporation', 'company', 'ltd', 'llc', 'plc',
        'the', 'and', 'for', 'philippines', 'pilipinas', 'store', 'shop', 'branch',
        'enterprises', 'enterprise', 'trading', 'services', 'service', 'group', 'holdings',
    ];

    /**
     * @param  array<string, mixed>  $findings  the submitted audit payload (see StoreWebsiteEnrichmentRequest)
     * @param  bool  $domainDerivedFromName  see {@see WebsiteIdentityVerdict} and the block below —
     *                                       true when the host being judged was CONSTRUCTED from the
     *                                       business name rather than asserted by anyone.
     */
    public function verify(Lead $lead, array $findings, bool $domainDerivedFromName = false): WebsiteIdentityVerdict
    {
        // Nothing was fetched — there is no page to attribute to anyone.
        if (! ($findings['website_reachable'] ?? false)) {
            return WebsiteIdentityVerdict::notEvaluated();
        }

        $requested = $this->host((string) ($lead->website ?? ''));
        $landed = $this->host((string) ($findings['canonical_url'] ?? ''))
            ?? $this->host((string) ($findings['final_url'] ?? ''))
            ?? $requested;

        // A PLATFORM PAGE CAN NEVER BE THE BUSINESS'S OWN SITE, however well it matches — and it will
        // match well, which is exactly why this gate sits above the signal weights rather than among
        // them. A Facebook page for "Cafe Bobs" carries the name in its title (30) and the shop's
        // phone (35): 65 against a threshold of 50, verified, and the lead is recorded as having a
        // website. It has a Facebook page. A business whose only web presence is somebody else's
        // platform is the PROSPECT this CRM exists to find, so calling that a website inverts the
        // signal it was discovered for.
        //
        // Judged on where the request LANDED, so a redirect into a platform is caught too: a domain
        // that now forwards to a Facebook page is a business that no longer has its own site.
        $landedKind = $this->classifier->classify($landed);
        if ($landedKind !== WebsiteUrlClassifier::KIND_OWN) {
            return new WebsiteIdentityVerdict(
                verified: false,
                confidence: 0,
                // Recorded, not silent: a BD looking at this lead can see the URL was examined and
                // why it was refused, which is a different thing from never having been checked.
                signals: ['platform_hosted' => true, 'platform_kind_'.$landedKind => true],
            );
        }

        $tokens = $this->nameTokens((string) $lead->business_name);
        $text = mb_strtolower(trim(
            ((string) ($findings['website_title'] ?? '')).' '.((string) ($findings['website_description'] ?? ''))
        ));

        // THE BRAND THE DOMAIN MATCHED, when it matched one. A corporate site legitimately titles
        // itself after the BRAND, never after the branch: "Mercury Drug Corporation" cannot contain
        // "Ayala Center The Residences", and requiring it to left every chain branch one signal short
        // of the threshold — measured on live data, Mercury Drug, Southstar Drug and Wilcon Depot all
        // sat at exactly 40. So once the domain has established WHICH brand this is, the title is
        // judged against that brand rather than against the branch address bolted onto it. Narrowing
        // only: a title that matches nothing still matches nothing.
        $brandTokens = $landed !== null ? $this->matchedBrandTokens($landed, $tokens) : null;

        $signals = [
            'domain_matches_name' => $brandTokens !== null,
            'title_matches_name' => $text !== '' && (
                $this->textMatchesName($text, $tokens)
                || ($brandTokens !== null && $this->textMatchesName($text, $brandTokens))
            ),
            'phone_match' => $this->phoneMatches($lead->phone, (array) ($findings['phones_found'] ?? [])),
            'email_domain_match' => $landed !== null && $this->emailDomainMatches($lead->email, $landed),
            'city_match' => $text !== '' && $this->cityMatches($lead->city, $text),
        ];

        $confidence = 0;
        foreach ($signals as $name => $matched) {
            if (! $matched) {
                continue;
            }

            // A DERIVED DOMAIN IS NOT EVIDENCE ABOUT ITSELF. When a candidate's host was built FROM
            // the business name — the probe tier spells the name as a domain and keeps whatever
            // resolves — then "the domain matches the name" is guaranteed true by construction and
            // proves nothing at all. Counting it let `newyork.com` reach 70 for a bakery in Naga
            // called "New York Bakery": 40 for a domain the probe had just written, plus 30 for a
            // title that unsurprisingly says "NewYork.com". The signal is still RECORDED, so the
            // trail shows what was examined; it simply carries no weight.
            //
            // Nothing here changes a weight or the threshold. It changes which evidence is
            // independent enough to be counted, which is the question the weights assume has already
            // been answered.
            if ($name === 'domain_matches_name' && $domainDerivedFromName) {
                continue;
            }

            $confidence += self::WEIGHTS[$name];
        }
        $confidence = min(100, $confidence);

        // Recorded as EVIDENCE, not scored. A redirect off the discovered domain is normal (a rebrand,
        // a `.ph` → `.com.ph` move) and must not by itself condemn a site; it matters only because the
        // checks above already ran against where the request LANDED rather than where it was aimed.
        $signals['redirected_off_domain'] = $requested !== null && $landed !== null && $requested !== $landed;
        $signals['canonical_url_present'] = ($findings['canonical_url'] ?? null) !== null;

        return new WebsiteIdentityVerdict(
            verified: $confidence >= self::THRESHOLD,
            confidence: $confidence,
            signals: $signals,
        );
    }

    /** Lower-cased hostname without `www.`, or null when there is no usable host. */
    private function host(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // The CRM stores `website` as free text, so a bare hostname is normal here (see the scraper's
        // urls.py for the same accommodation) — parse_url needs a scheme to find a host.
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', mb_strtolower($host));
    }

    /**
     * Identity-bearing words in a business name, lower-cased, de-duplicated, stopwords removed.
     *
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true),
        )));
    }

    /**
     * WHICH PART OF THE NAME THE HOST SPELLS — the brand tokens that matched, or null for no match.
     *
     * Was `domainMatchesName(): bool`. It now returns the tokens rather than a yes/no because the
     * caller needs to know WHICH brand the domain established, not merely that one did: a corporate
     * page titles itself after the brand and never after the branch, so the title check is judged
     * against these tokens (see {@see verify()}). The matching itself is unchanged.
     *
     * Compared against the host with every separator stripped, so `mercurydrug` is found in
     * `mercurydrug.com.ph`. Discovery names often carry a branch suffix ("Mercury Drug Vigan"), which
     * no domain contains, so the leading words are tried too — narrowing, never widening: a single
     * leading word must be long enough to be distinctive on its own.
     *
     * @param  list<string>  $tokens
     * @return list<string>|null
     */
    private function matchedBrandTokens(string $host, array $tokens): ?array
    {
        if ($tokens === []) {
            return null;
        }

        $haystack = preg_replace('/[^a-z0-9]/', '', $host) ?? '';

        // Widest first, so the brand reported back is the most specific spelling that matched.
        $candidates = [[implode('', $tokens), $tokens]];
        if (count($tokens) >= 2) {
            $candidates[] = [$tokens[0].$tokens[1], [$tokens[0], $tokens[1]]];
        }
        if (mb_strlen($tokens[0]) >= 6) {
            $candidates[] = [$tokens[0], [$tokens[0]]];
        }

        foreach ($candidates as [$needle, $matched]) {
            if (mb_strlen($needle) >= 4 && str_contains($haystack, $needle)) {
                return $matched;
            }
        }

        return null;
    }

    /**
     * Most of the business name appears in the page's own title/description.
     *
     * A majority rather than an exact match: real titles append taglines, locations, and separators
     * ("Mercury Drug | Philippines' Leading Drugstore"), and discovery names append branches.
     *
     * @param  list<string>  $tokens
     */
    private function textMatchesName(string $text, array $tokens): bool
    {
        if ($tokens === []) {
            return false;
        }

        $found = 0;
        foreach ($tokens as $token) {
            if (str_contains($text, $token)) {
                $found++;
            }
        }

        return $found / count($tokens) >= 0.6;
    }

    /**
     * The lead's phone appears among the phones the page publishes.
     *
     * Compared on the last 7 digits — the subscriber number — so formatting, spacing, and the
     * +63 / 0 country-prefix pair all compare equal.
     *
     * @param  array<int, mixed>  $found
     */
    private function phoneMatches(?string $phone, array $found): bool
    {
        $needle = $this->subscriberDigits($phone);
        if ($needle === null) {
            return false;
        }

        foreach ($found as $candidate) {
            if (is_string($candidate) && $this->subscriberDigits($candidate) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function subscriberDigits(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return mb_strlen($digits) >= 7 ? mb_substr($digits, -7) : null;
    }

    /** The lead's email is hosted on (or under) the same domain as the page. */
    private function emailDomainMatches(?string $email, string $host): bool
    {
        $email = trim((string) $email);
        if ($email === '' || ! str_contains($email, '@')) {
            return false;
        }

        $domain = preg_replace('/^www\./', '', mb_strtolower(substr(strrchr($email, '@') ?: '', 1)));
        if ($domain === null || $domain === '') {
            return false;
        }

        // Either direction, so `mail.mercurydrug.com.ph` and `mercurydrug.com.ph` agree.
        return $domain === $host || str_contains($host, $domain) || str_contains($domain, $host);
    }

    /** The lead's city is named on the page. Weak on its own — a corroborating signal only. */
    private function cityMatches(?string $city, string $text): bool
    {
        $city = mb_strtolower(trim((string) $city));

        return $city !== '' && mb_strlen($city) >= 4 && str_contains($text, $city);
    }
}
