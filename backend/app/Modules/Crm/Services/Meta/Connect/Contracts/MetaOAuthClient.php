<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect\Contracts;

use App\Modules\Crm\Services\Meta\Connect\MetaAssetDiscovery;
use App\Modules\Crm\Services\Meta\Connect\MetaBusinessVerification;
use App\Modules\Crm\Services\Meta\Connect\MetaExchangeResult;
use App\Modules\Crm\Services\Meta\Connect\MetaPageCredentials;
use App\Modules\Crm\Services\Meta\Connect\UnconfiguredMetaOAuthClient;
use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;

/**
 * Everything that would talk to Meta, behind one seam.
 *
 * WHY A SEAM AND NOT AN IMPLEMENTATION. Phase 3F.3A builds the flow around the network calls
 * without making any, so the parts that are hard to get right — tenancy across an unauthenticated
 * redirect, single-use state, refusing to claim someone else's Page — can be written and tested
 * now, while the parts that need a verified Meta app wait for the review that grants them. The
 * bound implementation is {@see UnconfiguredMetaOAuthClient},
 * which refuses; swapping it is the whole of Phase 3F.3B and is reviewed as its own change.
 *
 * THE TOKEN NEVER LEAVES AS A STRING. Both methods speak in
 * {@see ProviderCredential}, the wrapper that redacts itself in debug output and has no
 * `__toString()`, so a token cannot be interpolated into a log line by accident.
 */
interface MetaOAuthClient
{
    /**
     * Trade an authorization code for a long-lived user token.
     *
     * The redirect URI is passed rather than read from config so the value sent here is provably
     * the same one the authorization URL was built with — Meta compares them, and a mismatch is a
     * failure nobody can diagnose from the error alone.
     */
    public function exchangeCode(string $code, string $redirectUri): MetaExchangeResult;

    /**
     * List the Pages and Instagram accounts this grant covers.
     *
     * Takes the user credential and returns assets only — the per-Page tokens that come back with
     * them stay inside the implementation until a person has actually selected something, because
     * storing credentials for Pages nobody chose is holding secrets for no reason.
     */
    public function discoverAssets(ProviderCredential $userCredential): MetaAssetDiscovery;

    /**
     * Prove a credential really speaks for a named Business Portfolio.
     *
     * ONLY THE PLATFORM BOOTSTRAP PATH NEEDS THIS. Facebook Login for Business names the
     * authorizing business itself, so Flow B never asks. Flow A takes a portfolio id from an
     * operator's keyboard, and a typed id paired with a pasted token proves nothing on its own —
     * so the server reads the business back USING that token and accepts the id only if Meta
     * returns the same one.
     */
    public function verifyBusiness(ProviderCredential $credential, string $businessId): MetaBusinessVerification;

    /**
     * Fetch access tokens for Pages that have actually been selected.
     *
     * The second half of the promise made on `discoverAssets()`: assets come back without tokens
     * so a discovery list can be rendered safely, and the tokens are fetched here once somebody
     * has chosen. Pages nobody connected never have a credential stored for them.
     *
     * @param  list<string>  $pageExternalIds
     */
    public function pageCredentials(ProviderCredential $credential, array $pageExternalIds): MetaPageCredentials;
}
