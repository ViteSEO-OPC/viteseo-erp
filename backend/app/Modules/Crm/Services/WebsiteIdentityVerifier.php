<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\Lead;

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
     */
    public function verify(Lead $lead, array $findings): WebsiteIdentityVerdict
    {
        // Nothing was fetched — there is no page to attribute to anyone.
        if (! ($findings['website_reachable'] ?? false)) {
            return WebsiteIdentityVerdict::notEvaluated();
        }

        $requested = $this->host((string) ($lead->website ?? ''));
        $landed = $this->host((string) ($findings['canonical_url'] ?? ''))
            ?? $this->host((string) ($findings['final_url'] ?? ''))
            ?? $requested;

        $tokens = $this->nameTokens((string) $lead->business_name);
        $text = mb_strtolower(trim(
            ((string) ($findings['website_title'] ?? '')).' '.((string) ($findings['website_description'] ?? ''))
        ));

        $signals = [
            'domain_matches_name' => $landed !== null && $this->domainMatchesName($landed, $tokens),
            'title_matches_name' => $text !== '' && $this->textMatchesName($text, $tokens),
            'phone_match' => $this->phoneMatches($lead->phone, (array) ($findings['phones_found'] ?? [])),
            'email_domain_match' => $landed !== null && $this->emailDomainMatches($lead->email, $landed),
            'city_match' => $text !== '' && $this->cityMatches($lead->city, $text),
        ];

        $confidence = 0;
        foreach ($signals as $name => $matched) {
            if ($matched) {
                $confidence += self::WEIGHTS[$name];
            }
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
     * The business name, as a domain would spell it, appearing in the host.
     *
     * Compared against the host with every separator stripped, so `mercurydrug` is found in
     * `mercurydrug.com.ph`. Discovery names often carry a branch suffix ("Mercury Drug Vigan"), which
     * no domain contains, so the leading words are tried too — narrowing, never widening: a single
     * leading word must be long enough to be distinctive on its own.
     *
     * @param  list<string>  $tokens
     */
    private function domainMatchesName(string $host, array $tokens): bool
    {
        if ($tokens === []) {
            return false;
        }

        $haystack = preg_replace('/[^a-z0-9]/', '', $host) ?? '';

        $candidates = [implode('', $tokens)];
        if (count($tokens) >= 2) {
            $candidates[] = $tokens[0].$tokens[1];
        }
        if (mb_strlen($tokens[0]) >= 6) {
            $candidates[] = $tokens[0];
        }

        foreach (array_unique($candidates) as $candidate) {
            if (mb_strlen($candidate) >= 4 && str_contains($haystack, $candidate)) {
                return true;
            }
        }

        return false;
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
