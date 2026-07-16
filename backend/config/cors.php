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

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => false,

];
