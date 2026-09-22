<?php

$origins = array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*')));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The Next.js frontend runs on http://localhost:3000 and calls this API on
    | http://localhost:8000. Browsers block cross-origin calls unless the API
    | explicitly allows the frontend's origin. We authenticate with Bearer
    | tokens (not cookies), so `supports_credentials` stays false.
    |
    */

    // `broadcasting/auth` is registered by withBroadcasting() at the ROOT, not under
    // /api, so it is not covered by `api/*`. Laravel Echo POSTs it from the browser to
    // authorize every private channel — without CORS headers the preflight passes but
    // the POST is blocked (net::ERR_FAILED) and no channel is ever subscribed.
    'paths' => ['api/*', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    // Comma-separated list in production (e.g. "https://erp.kiplo.com").
    // Defaults open for local dev where the frontend port moves around.
    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    // Credentials only to origins named on purpose. With '*' the CORS package
    // would echo back whatever Origin asked, and Lax cookies ride same-site
    // requests -- so any other viteseo.ph subdomain could read a signed-in user's
    // API responses. Production needs none (one origin); local dev names
    // http://localhost:3000 in CORS_ALLOWED_ORIGINS so the Next dev server's
    // requests carry the session cookie.
    'supports_credentials' => ! in_array('*', $origins, true),

];
