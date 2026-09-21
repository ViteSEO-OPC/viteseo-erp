<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Models\User;
use App\Support\ServiceTokens;
use DateTimeInterface;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The NERBY scraper's identity: one dedicated, least-privilege service account.
 *
 * WHAT IT REPLACES. The engine used to call back into the CRM with a long-lived, wildcard (`*`)
 * token belonging to a platform super_admin with no org — so every callback resolved to the first
 * org (the provider tenant) whichever tenant started the run, and the token in the child process's
 * environment was full platform administrator.
 *
 * WHAT IT IS NOW
 *  - one user, role `nerby_service`, holding exactly `crm.leads.import` and nothing else, and a
 *    member of NO org — so it appears in no Team list and uses no seat;
 *  - SHORT-LIVED tokens, one per discovery run (or per enrichment tick per org), each bound to one
 *    org (`org:<id>`) and carrying only the service callback ability; revoked when the work ends;
 *  - confined by ServiceTokens/RestrictServiceTokens to the scraper callback routes.
 *
 * Provisioning is idempotent and self-healing: every issue() re-asserts the role, its single
 * permission and the absence of any other role, so a drifted account is corrected rather than used.
 */
class NerbyServiceIdentity
{
    public const ROLE = 'nerby_service';

    public const PERMISSION = 'crm.leads.import';

    public function user(): User
    {
        $email = (string) config('discovery.service_account.email', 'nerby-service@crm.local');

        /** @var User $user */
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            // No `status`: the DB default is `active`. The password is random and never shown — the
            // account authenticates by token only and cannot sign in through the login form.
            [
                'name' => (string) config('discovery.service_account.name', 'NERBY Discovery Service'),
                'password' => bcrypt(Str::random(64)),
            ],
        );

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');
        $role = Role::findOrCreate(self::ROLE, 'web');
        if ($role->permissions()->pluck('name')->all() !== [self::PERMISSION]) {
            $role->syncPermissions([$permission]);
        }

        // Exactly this role, and no direct permissions: least privilege is re-asserted, not assumed.
        if ($user->getRoleNames()->all() !== [self::ROLE]) {
            $user->syncRoles([self::ROLE]);
        }
        if ($user->getDirectPermissions()->isNotEmpty()) {
            $user->syncPermissions([]);
        }

        return $user;
    }

    /**
     * A token that acts for `$orgId` only, expiring at `$expiresAt`.
     *
     * `$purpose` names the token (e.g. `run:<uuid>`, `enrichment`) so an operator can see what issued
     * it; it grants nothing.
     */
    public function issue(int $orgId, string $purpose, DateTimeInterface $expiresAt): NewAccessToken
    {
        return $this->user()->createToken(
            'nerby:'.$purpose,
            [ServiceTokens::CALLBACK_ABILITY, ServiceTokens::ORG_ABILITY_PREFIX.$orgId],
            $expiresAt,
        );
    }

    public function revoke(NewAccessToken $token): void
    {
        $token->accessToken->delete();
    }

    /**
     * The scraper callback routes this identity may call — and the only ones.
     *
     * @return list<string>
     */
    public static function callbackRoutes(): array
    {
        return [
            'GET api/v1/crm/discovery/config',
            'POST api/v1/crm/discovery/strategy',
            'POST api/v1/crm/discovery/places',
            'POST api/v1/crm/discovery/proposals',
            'POST api/v1/crm/discovery/tag-expansions',
            'POST api/v1/crm/leads/discovered',
            'GET api/v1/crm/website-enrichment/queue',
            'PUT api/v1/crm/leads/{lead}/website-enrichment',
            'GET api/v1/crm/website-evidence/queue',
            'POST api/v1/crm/leads/{lead}/website-candidates',
            'PUT api/v1/crm/leads/{lead}/website-candidates/{candidate}',
        ];
    }
}
