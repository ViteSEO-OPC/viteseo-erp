<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Twilio Voice — outbound browser calling (ZEREM'S CENTRALIZED ACCOUNT)
|--------------------------------------------------------------------------
|
| Credentials for the Calling Workspace's first real CallProvider. Every value
| comes from the environment; nothing here may ever be hardcoded.
|
| THIS IS ZEREM'S ONE CENTRALIZED VITESEO TWILIO ACCOUNT — INTENTIONALLY. Every
| authorized CRM user (the call permission plus access to the lead) places
| browser calls through this one account and caller ID. It is not an accidental
| fallback, and it must not be split into per-employee accounts without a
| product decision. The secrets stay on the server: the browser only ever gets a
| short-lived Access Token.
|
| Per-organization accounts (`crm_call_provider_settings`, Settings →
| Integrations → Calling) exist in the code but are OFF unless a deployment sets
| `calling.organization_credentials`; while off, stored rows never replace this
| account.
|
| {@see \App\Modules\Crm\Services\Calling\TwilioConfig} is what reads this and
| answers `isUsable()`; nothing else should read this file directly.
|
| The feature is OPTIONAL by design. The CRM answers "calling is not configured"
| rather than throwing when it is not — so a developer, a CI run and an org that
| has not bought a Twilio account all keep working exactly as they did before this
| feature existed.
|
| Which credential does what:
|
|   account_sid   AC…  identifies the account; used for BOTH token issuing and
|                      webhook signature validation.
|   auth_token         the account's master secret. Used ONLY to validate the
|                      X-Twilio-Signature header (Twilio signs with it) and NEVER
|                      sent to the browser.
|   api_key/secret SK… a scoped key pair used to SIGN the browser's Access Token.
|                      Twilio requires a key pair rather than the auth token for
|                      this, which is why both exist.
|   twiml_app_sid  AP… the TwiML Application whose Voice URL points at our
|                      /api/v1/twilio/voice endpoint. The browser Device dials
|                      "into" this app; the app calls us back for instructions.
|   caller_id      +63…the verified Twilio number shown to the person being
|                      called. Must be a number the account owns.
|   webhook_base_url   the PUBLIC https origin Twilio can reach. Local dev needs a
|                      tunnel; there is no way around this — Twilio dials our
|                      webhook from the internet, not from this machine.
|
*/

return [
    'account_sid' => env('TWILIO_ACCOUNT_SID'),
    'auth_token' => env('TWILIO_AUTH_TOKEN'),
    'api_key' => env('TWILIO_API_KEY_SID'),
    'api_secret' => env('TWILIO_API_KEY_SECRET'),
    'twiml_app_sid' => env('TWILIO_TWIML_APP_SID'),
    'caller_id' => env('TWILIO_CALLER_ID'),
    'webhook_base_url' => env('TWILIO_WEBHOOK_BASE_URL'),

    /*
     | How long a browser Access Token stays valid. Short by intent: the token is
     | fetched immediately before a call and is useless afterwards, so a leaked one
     | has a very small window. Twilio's own ceiling is 24 hours.
     */
    'token_ttl' => (int) env('TWILIO_TOKEN_TTL', 600),

    /*
     | Seconds Twilio lets the PSTN leg ring before giving up with `no-answer`.
     | Twilio's own default is 60; 30 matches how long a BD actually waits.
     */
    'dial_timeout' => (int) env('TWILIO_DIAL_TIMEOUT', 30),

    /*
     | Twilio's REST origin, used ONLY by the settings screen's "Test connection"
     | action to verify stored credentials. Configurable so a test suite can point
     | it somewhere fake; there is no reason to change it in production.
     */
    'api_base_url' => env('TWILIO_API_BASE_URL', 'https://api.twilio.com'),

    /*
     | How long that verification waits. Short: an admin is watching a button.
     */
    'test_timeout' => (float) env('TWILIO_TEST_TIMEOUT', 8),
];
