<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Tests\TestCase;

/**
 * Credentials cross origins only to origins named on purpose.
 *
 * Production never needs CORS: nginx serves the frontend and the API from one
 * origin. Local development does -- Next on :3000 calling Laravel on :8000 -- and
 * once the browser signs in with a cookie, those calls must carry it, which CORS
 * allows only with supports_credentials.
 *
 * The trap is the default. allowed_origins falls back to '*', and a CORS package
 * asked to support credentials for '*' answers by echoing back whatever Origin
 * asked. Lax cookies ride same-site requests, so a page on any other viteseo.ph
 * subdomain -- n8n, erp, the portfolio -- could then read a signed-in user's API
 * responses. So credentials follow from naming the origins, never from a flag
 * someone could switch on beside the wildcard.
 */
class CorsCredentialsFollowNamedOriginsTest extends TestCase
{
    /** config/cors.php as it would load with CORS_ALLOWED_ORIGINS set to $origins. */
    private function corsConfigWith(?string $origins): array
    {
        $key = 'CORS_ALLOWED_ORIGINS';
        $before = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];

        try {
            if ($origins === null) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);
            } else {
                $_SERVER[$key] = $_ENV[$key] = $origins;
                putenv("{$key}={$origins}");
            }

            return require base_path('config/cors.php');
        } finally {
            [$server, $env, $fromGetenv] = $before;
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            putenv($fromGetenv === false ? $key : "{$key}={$fromGetenv}");
        }
    }

    public function test_the_wildcard_default_offers_no_credentials(): void
    {
        $cors = $this->corsConfigWith(null);

        $this->assertSame(['*'], $cors['allowed_origins']);
        $this->assertFalse($cors['supports_credentials']);
    }

    public function test_naming_the_local_frontend_lets_its_cookie_through(): void
    {
        $cors = $this->corsConfigWith('http://localhost:3000, http://192.168.1.20:3000');

        $this->assertSame(['http://localhost:3000', 'http://192.168.1.20:3000'], $cors['allowed_origins']);
        $this->assertTrue($cors['supports_credentials'],
            'Next on :3000 calling Laravel on :8000 cannot send the session cookie without it.');
    }

    public function test_a_wildcard_beside_named_origins_still_offers_none(): void
    {
        $this->assertFalse($this->corsConfigWith('http://localhost:3000,*')['supports_credentials']);
    }

    public function test_a_sibling_subdomain_is_not_answered_with_credentials(): void
    {
        // End to end, with the configuration this process actually loaded -- which,
        // like production, names no origins.
        $this->withHeaders([
            'Origin' => 'https://n8n.viteseo.ph',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/v1/auth/me')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }
}
