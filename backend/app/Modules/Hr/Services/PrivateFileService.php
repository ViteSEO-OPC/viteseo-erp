<?php

namespace App\Modules\Hr\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Where HR evidence files live, and how a client is given access to one (FIN-004).
 *
 * WHAT WAS WRONG. Every upload in this module went to the `public` disk: receipts, employment
 * contracts, government IDs, payroll-inquiry attachments, résumés, whistle-blower concern
 * attachments, clock-in selfies, office-expense invoices. That disk is symlinked into `public/`
 * and served by the webserver before PHP is reached — so the file had no authentication, no
 * ownership check, no expiry and, crucially, no revocation. A URL that escapes once via an email,
 * a Referer header, browser history or a backup is disclosed permanently.
 *
 * WHAT IT IS NOW. Uploads go to the `local` disk (storage/app/private), which is not symlinked
 * anywhere. Laravel serves it through `Illuminate\Filesystem\ServeFile`, which refuses any request
 * without a valid relative signature because that disk's visibility is not `public`. Clients get a
 * short-lived signed URL, minted only inside API resources that already sit behind `auth:sanctum`
 * and this module's role checks.
 *
 * WHY SIGNED URLS AND NOT TOKEN-AUTHENTICATED ENDPOINTS. The audit's first preference was
 * controller endpoints checking ownership per file, with signed URLs "where a direct link is
 * genuinely needed". Every one of these files is rendered by the frontend as an `<img src>` or a
 * download link, and a browser attaches no Authorization header to either — so token endpoints
 * would mean fetching every receipt and avatar as a blob through Axios. The signature carries the
 * grant in the URL instead. The exposure changes from permanent-and-anonymous to a link that
 * expires, handed only to someone already authorised to see the record it belongs to.
 *
 * THE RESIDUAL RISK, stated plainly: within the TTL, anyone holding the link can fetch the file.
 * That is inherent to signed URLs. Shorten the TTL to shrink the window; move to per-file
 * authenticated endpoints (and blob fetching in the UI) if the threat model ever needs the link
 * itself to be useless without a session.
 */
final class PrivateFileService
{
    /** Not symlinked into public/, so nothing here is reachable without a signature. */
    public const DISK = 'local';

    /**
     * How long an issued link stays valid.
     *
     * Long enough to open a PDF or load a page full of avatars on a slow connection; short enough
     * that a link copied out of a browser's history or an access log is dead by the time anyone
     * tries it. Stated once here so the whole module expires on the same clock.
     *
     * WHY 30 AND NOT 15 — checked against how the frontend actually caches these (Slice 4d).
     *
     * Every link is minted inside a list payload, and the UI caches those with TanStack Query under
     * `core/query-provider.tsx`, which overrides nothing but `retry`. So `staleTime` is 0 and
     * `refetchOnWindowFocus` is true: each mount refetches, and so does every return to the tab.
     * In ordinary use a link is therefore seconds old when it is clicked, not minutes.
     *
     * The window that survives that is narrow but real: a page left MOUNTED AND FOCUSED, untouched
     * past the TTL, whose links then expire in place. It is worth noting what the user sees, because
     * the original concern was a broken image and that is not the failure — no HR surface renders a
     * signed URL as an `<img>`. They are all anchors with `target="_blank"`, so an expired link is a
     * new tab showing 403 rather than anything that looks like a bug in the page. Recoverable by a
     * refresh, and it never touches the file's protection.
     *
     * 30 minutes puts the TTL past any plausible focused-idle stretch while staying firmly in
     * "short-lived": a leaked link is still dead long before it reaches anyone. The alternative
     * fixes were both worse — polling refetches on six surfaces to keep links warm, or swapping
     * anchors for authenticated blob fetches, which is the real answer only if the threat model ever
     * says the link itself must be useless without a session (see the class docblock).
     */
    public const LINK_TTL_MINUTES = 30;

    /**
     * A short-lived signed URL for a stored file, or null when there is no file.
     *
     * Callers pass the stored path straight from the model. Null-tolerant on purpose: almost every
     * call site is an optional attachment, and making each one repeat the same ternary is how one
     * of them ends up returning a bare unsigned path.
     */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk(self::DISK)->temporaryUrl(
            $path,
            now()->addMinutes(self::LINK_TTL_MINUTES),
        );
    }
}
