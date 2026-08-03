<?php

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

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => false,

];
