<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ORG-BOUND, ROUTE-ALLOWLISTED MACHINE TOKENS.
 *
 * A machine caller (the NERBY scraper today) must act for exactly ONE tenant and reach exactly the
 * endpoints it exists to call — nothing that a human account's roles would otherwise open.
 *
 *  - The service USER belongs to no org. It never appears in a Team list, never uses a seat, and can
 *    never fall back to "the first org": a non-super_admin with no membership resolves to no org at
 *    all (fail closed, see CurrentOrg::id()).
 *  - Its TOKEN names the org it acts for (`org:<id>`) beside the callback ability. `SetCurrentOrg`
 *    pins that org, and ONLY for users holding a registered service role — a human's token can never
 *    choose its tenant this way.
 *  - The token may only reach the routes its role registered (`RestrictServiceTokens`). Everything
 *    else — profile, notifications, other modules — is refused before it runs.
 *
 * Modules register their service role and its callback routes (see CrmServiceProvider); this class
 * knows nothing about CRM routes itself.
 */
final class ServiceTokens
{
    /** Every service token carries this ability; a token without it is not a service token. */
    public const CALLBACK_ABILITY = 'service:callback';

    public const ORG_ABILITY_PREFIX = 'org:';

    /** @var array<string, list<string>> role => list of "METHOD route-uri" */
    private static array $routes = [];

    /** @param list<string> $routes  e.g. "POST api/v1/crm/leads/discovered" (route URI, not path) */
    public static function register(string $role, array $routes): void
    {
        self::$routes[$role] = array_values(array_unique([...(self::$routes[$role] ?? []), ...$routes]));
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return array_keys(self::$routes);
    }

    public static function isServiceUser(User $user): bool
    {
        $roles = self::roles();

        return $roles !== [] && $user->hasAnyRole($roles);
    }

    /** The token the request authenticated with, when it is a real personal access token. */
    public static function token(User $user): ?PersonalAccessToken
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->exists ? $token : null;
    }

    /**
     * The single org a service token is bound to, or null when it is not a valid service token.
     *
     * Exactly one `org:<id>` ability, plus the callback ability, and never the wildcard: a `*` token
     * would grant every ability, including any org, so it is refused outright rather than parsed.
     */
    public static function boundOrg(User $user): ?int
    {
        $token = self::token($user);
        if ($token === null) {
            return null;
        }

        $abilities = array_values(array_filter((array) $token->abilities, 'is_string'));
        if (in_array('*', $abilities, true) || ! in_array(self::CALLBACK_ABILITY, $abilities, true)) {
            return null;
        }

        $orgs = [];
        foreach ($abilities as $ability) {
            if (preg_match('/^'.preg_quote(self::ORG_ABILITY_PREFIX, '/').'([1-9][0-9]{0,18})$/', $ability, $m) === 1) {
                $orgs[] = (int) $m[1];
            }
        }

        return count($orgs) === 1 ? $orgs[0] : null;
    }

    /** May this service user's token reach the route this request matched? */
    public static function allows(User $user, Request $request): bool
    {
        $route = $request->route();
        if ($route === null) {
            return false;
        }

        $signature = strtoupper($request->method()).' '.ltrim($route->uri(), '/');
        foreach (self::$routes as $role => $routes) {
            if ($user->hasRole($role) && in_array($signature, $routes, true)) {
                return true;
            }
        }

        return false;
    }
}
