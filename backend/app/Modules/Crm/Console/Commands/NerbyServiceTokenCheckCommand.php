<?php

namespace App\Modules\Crm\Console\Commands;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use App\Support\Entitlements;
use App\Support\ServiceTokens;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Verify that a scraper token would actually be AUTHORIZED for the scraper's callbacks — read-only.
 *
 * WHY THIS EXISTS. The old daily check asked `GET /auth/me` whether the token worked. That answers
 * "is this a valid login", not "may this token do the scraper's job", and the two diverge in both
 * directions: a plain user's token passes `/me` yet is refused by the discovery callbacks (a false
 * OK), while a correct NERBY token is confined to its callbacks and is refused by `/me` (a false
 * alarm). This command checks the things authorization is actually made of: whose token it is, what
 * that identity holds, what the token is scoped to, which org it acts for, and when it expires.
 *
 * READ-ONLY BY CONSTRUCTION. It never calls {@see NerbyServiceIdentity::user()}, which provisions
 * and heals the account; a check must report drift, not repair it. Nothing is written — not even
 * `last_used_at`.
 *
 * THE TOKEN NEVER TOUCHES THE COMMAND LINE. It is read from stdin (or a 0600 file), because argv is
 * visible in the process list and shell history. Output names the token only by its numeric id.
 *
 *   php artisan crm:nerby-service-token-check < token-file
 *
 * Exit codes: 0 authorized, 10 authorized but expiring within --warn-days, 11 not authorized. They are
 * deliberately NOT 1 or 2, which artisan itself returns for an exception or a missing command — a caller
 * must be able to tell "the token is bad" from "the check could not run".
 *
 * It mirrors what the callback routes demand (auth:sanctum incl. `sanctum.expiration`, subscription-active,
 * feature:crm, the service role + one-org binding) but is still no substitute for probing a real callback.
 */
class NerbyServiceTokenCheckCommand extends Command
{
    protected $signature = 'crm:nerby-service-token-check
        {--token-file= : Read the token from this file instead of stdin}
        {--warn-days=7 : Report "expiring" (exit 1) when fewer whole days than this remain}';

    protected $description = 'Read-only: verify a NERBY scraper token is authorized for the scraper callbacks (token via stdin)';

    public const EXPIRING = 10;

    public const UNAUTHORIZED = 11;

    public function handle(): int
    {
        $warnDays = (int) $this->option('warn-days');
        if ($warnDays < 0 || $warnDays > 365) {
            $this->error('--warn-days must be between 0 and 365.');

            return self::INVALID;
        }

        if (! is_string($this->option('token-file')) && stream_isatty(STDIN)) {
            $this->error('Pipe the token on stdin or pass --token-file; it is never read from the terminal or argv.');

            return self::INVALID;
        }

        $plain = $this->readToken();
        if ($plain === null) {
            return $this->verdict(null, null, null, 'no_token');
        }

        // A database error here could carry the token's hash in its message: report a code, nothing else.
        try {
            $token = PersonalAccessToken::findToken($plain);
        } catch (Throwable) {
            $this->error('nerby token check could not run (lookup failed).');

            return self::FAILURE;
        } finally {
            unset($plain);
        }
        if ($token === null) {
            return $this->verdict(null, null, null, 'unknown_or_revoked');
        }

        $id = (int) $token->getKey();
        $owner = $token->tokenable;

        if (! $owner instanceof User
            || strcasecmp((string) $owner->email, (string) config('discovery.service_account.email', 'nerby-service@crm.local')) !== 0) {
            return $this->verdict($id, null, $token, 'not_the_service_identity');
        }
        if (! $owner->isActive()) {
            return $this->verdict($id, null, $token, 'service_identity_inactive');
        }
        if ($owner->getRoleNames()->all() !== [NerbyServiceIdentity::ROLE]) {
            return $this->verdict($id, null, $token, 'service_identity_roles_not_least_privilege');
        }
        if ($owner->getDirectPermissions()->isNotEmpty()) {
            return $this->verdict($id, null, $token, 'service_identity_has_direct_permissions');
        }

        try {
            $role = Role::findByName(NerbyServiceIdentity::ROLE, 'web');
        } catch (RoleDoesNotExist) {
            return $this->verdict($id, null, $token, 'service_role_missing');
        }
        if ($role->permissions()->pluck('name')->all() !== [NerbyServiceIdentity::PERMISSION]) {
            return $this->verdict($id, null, $token, 'service_role_not_least_privilege');
        }
        if (! in_array(NerbyServiceIdentity::ROLE, ServiceTokens::roles(), true)) {
            return $this->verdict($id, null, $token, 'callback_routes_not_registered');
        }

        // The SAME rule the middleware applies (no wildcard, the callback ability, exactly one org),
        // evaluated on this token without authenticating anyone.
        $orgId = ServiceTokens::boundOrg($owner->withAccessToken($token));
        if ($orgId === null) {
            return $this->verdict($id, null, $token, 'token_not_scoped_to_one_org');
        }
        $org = Org::query()->find($orgId);
        if ($org === null) {
            return $this->verdict($id, $orgId, $token, 'org_missing');
        }
        if (! $org->is_active) {
            return $this->verdict($id, $orgId, $token, 'org_inactive');
        }
        if (! Entitlements::has($orgId, 'crm')) {
            return $this->verdict($id, $orgId, $token, 'org_has_no_crm');
        }
        $subscription = Subscription::query()->where('org_id', $orgId)->first();
        // isCurrentlyActive(), not SubscriptionService::hasAccess(): the same verdict, but hasAccess() SUSPENDS an
        // overdue subscription as a side effect, and a check must never write.
        if ($subscription === null || ! $subscription->isCurrentlyActive()) {
            return $this->verdict($id, $orgId, $token, 'org_subscription_inactive');
        }

        if ($token->expires_at === null) {
            return $this->verdict($id, $orgId, $token, 'token_never_expires');
        }
        if ($token->expires_at->isPast()) {
            return $this->verdict($id, $orgId, $token, 'expired');
        }
        // The global Sanctum lifetime applies on top of the token's own expiry (Sanctum's Guard).
        $globalMinutes = config('sanctum.expiration');
        if ($globalMinutes !== null && $token->created_at !== null && $token->created_at->lte(now()->subMinutes((int) $globalMinutes))) {
            return $this->verdict($id, $orgId, $token, 'expired');
        }

        if ($this->daysLeft($token) < $warnDays) {
            return $this->verdict($id, $orgId, $token, 'expiring_soon');
        }

        return $this->verdict($id, $orgId, $token, null);
    }

    private function readToken(): ?string
    {
        $path = $this->option('token-file');
        $raw = is_string($path) && $path !== ''
            ? (is_readable($path) ? file_get_contents($path) : false)
            : stream_get_contents(STDIN);

        $plain = is_string($raw) ? trim($raw) : '';

        return $plain === '' ? null : $plain;
    }

    private function daysLeft(PersonalAccessToken $token): int
    {
        return (int) floor(now()->diffInSeconds($token->expires_at, false) / 86400);
    }

    /** One line, ids and codes only — never the token, never its hash. */
    private function verdict(?int $id, ?int $orgId, ?PersonalAccessToken $token, ?string $problem): int
    {
        $subject = 'nerby token '.($id !== null ? '#'.$id : '(unidentified)')
            .($orgId !== null ? ' org '.$orgId : '')
            .($token?->expires_at !== null
                ? ' expires '.$token->expires_at->toDateTimeString().' UTC ('.max(0, $this->daysLeft($token)).' day(s) left)'
                : '');

        if ($problem === null) {
            $this->line($subject.': OK');

            return self::SUCCESS;
        }

        if ($problem === 'expiring_soon') {
            $this->warn($subject.': EXPIRING — rotate it');

            return self::EXPIRING;
        }

        $this->error($subject.': NOT AUTHORIZED ('.$problem.')');

        return self::UNAUTHORIZED;
    }
}
