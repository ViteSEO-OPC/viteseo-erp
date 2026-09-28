<?php

namespace App\Modules\Crm\Services\Website;

/**
 * WHAT KIND OF URL IS THIS — a business's own website, or a page about it on somebody else's platform?
 *
 * THE DISTINCTION THE REST OF THE SYSTEM WAS MISSING. Google Places returns one `websiteUri` per
 * place and it is whatever the owner put there: often their own domain, very often a Facebook page,
 * sometimes a TripAdvisor listing or a Linktree. Measured on the live pool, 79 of 237 discovered URLs
 * (33%) were social profiles stored as if they were the business's website.
 *
 * WHY {@see \App\Modules\Crm\Services\WebsiteIdentityVerifier} CANNOT CATCH THIS ON ITS OWN, and why
 * this class has to exist beside it rather than inside it. That verifier asks "does this page belong
 * to this business?" — and for a Facebook page the honest answer is YES. The page is about the
 * business, carries its name in the title, its phone, its city. It clears the threshold on title +
 * phone alone. The question it never asks is the one that matters here: "is this the business's OWN
 * SITE, or a profile it rents on a platform?" A business with only a Facebook page is a PROSPECT —
 * precisely the prospect this CRM exists to find — and recording that page as its website inverts
 * the signal it was discovered for.
 *
 * SO OWNERSHIP IS DECIDED BY THE HOST, NOT BY THE CONTENT. A platform host is never the business's
 * own site no matter how well the page matches, because the host belongs to Meta, or Tripadvisor, or
 * Linktree. That is a fact about the domain, checkable without fetching anything, and it is why this
 * runs at ingest rather than waiting for an audit.
 *
 * WHAT THIS IS NOT. It is not a judgement about whether the URL is USEFUL — a Facebook page is very
 * useful and is kept, in the `facebook` column the Lead already has. It is not an allowlist of good
 * domains: anything not recognised as a platform is treated as a candidate for the business's own
 * site, and still has to survive the identity verifier before it counts as one. Being unable to name
 * a platform is never evidence that a domain is owned.
 */
class WebsiteUrlClassifier
{
    /** A domain that could be the business's own site. Still unproven — the verifier decides. */
    public const KIND_OWN = 'own';

    /** A profile on a social network. Useful contact data; never the business's website. */
    public const KIND_SOCIAL = 'social';

    /** A listing on a directory, marketplace or booking platform, written about the business. */
    public const KIND_DIRECTORY = 'directory';

    /** A link-in-bio hub. A page of links, owned by the hub, never the business's site. */
    public const KIND_LINK_IN_BIO = 'link_in_bio';

    /** Not a usable URL at all. */
    public const KIND_INVALID = 'invalid';

    /**
     * Social platforms, host suffix → the Lead column that already exists for it (null = no column).
     *
     * The column mapping is what makes "classify, don't delete" real: a Facebook URL is not thrown
     * away, it is filed where a Facebook URL belongs. Platforms with no column are still recognised
     * as social — knowing a URL is NOT a website matters even when there is nowhere to file it.
     */
    private const SOCIAL_HOSTS = [
        'facebook.com' => 'facebook',
        'fb.com' => 'facebook',
        'fb.me' => 'facebook',
        'messenger.com' => 'facebook',
        // LINK SHIMS, not profiles. `l.facebook.com/l.php?u=…` is Facebook's outbound redirector
        // wrapped around somebody else's URL — one in this pool wraps a Google Form. It is neither a
        // website nor a Facebook page, so it is recognised as social (to keep it out of `website`)
        // with no column to file it in: writing a redirect wrapper into `facebook` would record a
        // link to a form as the business's Facebook page.
        'l.facebook.com' => null,
        'lm.facebook.com' => null,
        'instagram.com' => 'instagram',
        'instagr.am' => 'instagram',
        'tiktok.com' => null,
        'linkedin.com' => null,
        'youtube.com' => null,
        'youtu.be' => null,
        'twitter.com' => null,
        'x.com' => null,
        'threads.net' => null,
        'pinterest.com' => null,
        'pinterest.ph' => null,
        'snapchat.com' => null,
        'wa.me' => null,
        'whatsapp.com' => null,
        'telegram.me' => null,
        't.me' => null,
    ];

    /**
     * Directories, marketplaces and booking platforms.
     *
     * These are the dangerous ones, because a listing page reads exactly like a business's homepage
     * to any content-based check: the right name, the right address, the right phone. A TripAdvisor
     * page for "Louise Cakes and Pastries" would pass identity verification comfortably — and it is
     * Tripadvisor's page, not the bakery's.
     */
    private const DIRECTORY_HOSTS = [
        'tripadvisor.com', 'tripadvisor.com.ph', 'tripadvisor.co.uk',
        'yelp.com', 'foursquare.com', 'zomato.com', 'opentable.com',
        'booking.com', 'agoda.com', 'expedia.com', 'hotels.com', 'airbnb.com',
        'bluepillow.com', 'trivago.com', 'kayak.com', 'traveloka.com',
        'grab.com', 'foodpanda.com', 'foodpanda.ph', 'ubereats.com',
        'shopee.ph', 'lazada.com.ph', 'carousell.ph', 'etsy.com', 'amazon.com',
        'google.com', 'goo.gl', 'maps.app.goo.gl', 'business.site', 'sites.google.com',
        'yellowpages.com.ph', 'yellow-pages.ph', 'bizdirectory.ph',
        'wikipedia.org', 'wikidata.org',
    ];

    /** Link-in-bio hubs — a page of links, hosted and owned by the hub. */
    private const LINK_IN_BIO_HOSTS = [
        'linktr.ee', 'lnk.bio', 'beacons.ai', 'bio.link', 'campsite.bio',
        'tap.bio', 'linkin.bio', 'msha.ke', 'solo.to', 'carrd.co',
    ];

    /**
     * How much of a social URL the Lead's `facebook` / `instagram` columns can hold.
     *
     * A DATA CONTRACT, not a preference: those columns are `varchar(255)` while `website` is
     * `varchar(2048)`, so a long URL that is legitimately social still cannot be filed as one. See
     * {@see fitsSocialColumn()} — callers check before moving, because a truncated link is a broken
     * link and an overflowing one is a failed write.
     */
    public const SOCIAL_COLUMN_MAX_LENGTH = 255;

    /** Can this URL actually be stored in the social column it belongs to? */
    public function fitsSocialColumn(?string $url): bool
    {
        return mb_strlen(trim((string) $url)) <= self::SOCIAL_COLUMN_MAX_LENGTH;
    }

    /** @return self::KIND_* */
    public function classify(?string $url): string
    {
        $host = $this->host($url);
        if ($host === null) {
            return self::KIND_INVALID;
        }

        if ($this->matches($host, array_keys(self::SOCIAL_HOSTS))) {
            return self::KIND_SOCIAL;
        }
        if ($this->matches($host, self::LINK_IN_BIO_HOSTS)) {
            return self::KIND_LINK_IN_BIO;
        }
        if ($this->matches($host, self::DIRECTORY_HOSTS)) {
            return self::KIND_DIRECTORY;
        }

        // UNRECOGNISED MEANS CANDIDATE, NOT OWNED. Everything the platform lists above do not cover
        // is allowed through to the identity verifier, which is where ownership is actually decided.
        return self::KIND_OWN;
    }

    /** Could this URL be the business's own website? The only question the ingest needs answered. */
    public function isOwnWebsiteCandidate(?string $url): bool
    {
        return $this->classify($url) === self::KIND_OWN;
    }

    /**
     * The Lead column a social URL belongs in (`facebook` / `instagram`), or null when this build has
     * no column for that platform — or when the URL is not social at all.
     */
    public function socialColumn(?string $url): ?string
    {
        $host = $this->host($url);
        if ($host === null) {
            return null;
        }

        foreach (self::SOCIAL_HOSTS as $platform => $column) {
            if ($column !== null && $this->matches($host, [$platform])) {
                // A column that cannot hold the value is not a home for it.
                return $this->fitsSocialColumn($url) ? $column : null;
            }
        }

        return null;
    }

    /**
     * Lower-cased hostname without `www.`, or null when there is no usable host.
     *
     * Deliberately the same accommodation {@see WebsiteIdentityVerifier::host()} makes: `website` is
     * stored as free text, so a bare hostname with no scheme is normal and must still parse.
     */
    private function host(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        // A SCHEME THAT IS NOT THE WEB IS NOT A WEBSITE. Without this, `mailto:hi@example.com` is
        // prefixed with `https://`, parses as user `mailto` / password `hi` / host `example.com`,
        // and an email address is classified as the business's own domain.
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $m) && ! in_array(mb_strtolower($m[1]), ['http', 'https'], true)) {
            return null;
        }

        if (! preg_match('#^https?://#i', $url)) {
            // A bare `name@host` is an email address, not a host — and prefixing a scheme would turn
            // it into credentials plus a host, silently inventing a domain the record never carried.
            if (str_contains(explode('/', $url)[0], '@')) {
                return null;
            }
            $url = 'https://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || ! str_contains($host, '.')) {
            return null;
        }

        return preg_replace('/^www\./', '', mb_strtolower($host));
    }

    /**
     * Host-suffix match, anchored on a dot boundary.
     *
     * `web.facebook.com` and `m.facebook.com` are Facebook; `myfacebook.com` and
     * `notfacebook.com.ph` are not, and a substring test would wrongly claim both. The boundary is
     * the entire difference between recognising a platform and seizing somebody's domain name.
     *
     * @param  list<string>  $needles
     */
    private function matches(string $host, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($host === $needle || str_ends_with($host, '.'.$needle)) {
                return true;
            }
        }

        return false;
    }
}
