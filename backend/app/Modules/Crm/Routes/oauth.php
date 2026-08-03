<?php

use App\Modules\Crm\Controllers\GmailOAuthController;
use Illuminate\Support\Facades\Route;

/*
 * ROOT-LEVEL OAuth callback — the one CRM route that is neither under /api/v1/crm nor behind
 * auth:sanctum. Mounted by CrmServiceProvider with no prefix so the path is exactly
 * /auth/google/callback, which is what GOOGLE_REDIRECT_URI is registered as on the OAuth client.
 * Google compares redirect URIs by exact string, so this path is not ours to tidy.
 *
 * WHY IT IS PUBLIC. Google sends the user's browser here as a top-level navigation: no Authorization
 * header, no cookie, nothing tying the request to a CRM session. Authentication is the single-use,
 * short-lived state nonce issued by the authenticated endpoint in api.php and verified in
 * GmailConsentFlow. Note also that the CRM deliberately exposes no other public endpoint — inbound
 * mail is pulled by the scheduled poll, not pushed to a webhook (see the note in api.php).
 *
 * Nothing is written before that nonce is validated and spent, so an unsolicited request here costs
 * one cache read and a 400.
 */
Route::get('/auth/google/callback', [GmailOAuthController::class, 'callback']);
