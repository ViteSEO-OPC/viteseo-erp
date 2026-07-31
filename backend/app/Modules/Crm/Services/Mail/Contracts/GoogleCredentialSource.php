<?php

namespace App\Modules\Crm\Services\Mail\Contracts;

use Google\Client as GoogleClient;

/**
 * How a Google API client is authenticated to act as a given mailbox.
 *
 * Extracted from GmailMailboxSync so that "which Gmail messages do we want" and "how do we prove
 * we may read them" are separate decisions. The sync provider builds a client and hands it here;
 * it never learns whether the credential came from a service account, a downloadable key, or a
 * per-tenant OAuth grant.
 *
 * WHY THIS SEAM EXISTS. Domain-wide delegation from a single Workspace service account is correct
 * for one company syncing its own mailboxes (ViteSEO Enterprise today). It cannot work for tenants
 * on someone else's Workspace or on consumer Gmail: those require the tenant to grant access via
 * OAuth, and the credential is then a stored per-tenant refresh token, not an org-wide assertion.
 * Both are "a Google credential for this mailbox", which is exactly what this interface names.
 * Service-account delegation becomes one implementation rather than the only possible shape.
 *
 * Implementations own their scopes: the keyless path has to embed them in the signed delegation
 * assertion, so splitting scope ownership between the caller and the credential would duplicate it.
 */
interface GoogleCredentialSource
{
    /**
     * Apply scopes and credentials to $client so that API calls act as $mailbox.
     *
     * Mutates the client rather than returning one: the caller owns client construction (and, in
     * future, the API service wrapped around it), the credential source owns only authentication.
     *
     * @throws \RuntimeException when the credential cannot be obtained (misconfiguration, or the
     *                           token exchange being refused) — a sync failure the queue retries.
     */
    public function authorize(GoogleClient $client, string $mailbox): void;
}
