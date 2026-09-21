<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console\Commands;

use App\Models\Org;
use App\Models\User;
use App\Support\MachineTokens;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Provisions the integration identity that may call the inbound conversation ingest endpoint.
 *
 * ONE PROVIDER PER ORG, and the database already insists on it: `org_user` carries a unique index
 * on `user_id`, so a single "meta inbound" account could never serve two tenants. The address is
 * therefore derived from BOTH — `meta-inbound-org7@crm.local` — which is deterministic (re-running
 * this finds the same account), unique, and carries nothing about the customer beyond an internal
 * number that is already ours. A leaked credential is scoped to one provider in one tenant, and
 * revoking it touches nobody else.
 *
 * THE ORG IS ALWAYS EXPLICIT. There is no "current org" in a console context — CurrentOrg fails
 * closed outside a request precisely so a background path cannot guess a tenant — so `--org` is
 * required and resolved by id, or by an exact, unambiguous name.
 *
 * FAILS CLOSED, AND NEVER REPAIRS. If an account exists but belongs to another org, holds a human
 * role, or carries permissions beyond the single ingest ability, this reports the problem and
 * stops. It does not move the account, strip the role, or revoke the permissions: each of those
 * would be a security decision taken silently by a provisioning script, and the reason the account
 * looks wrong is exactly what a person needs to see.
 *
 * TOKENS STAY WITH THE EXISTING TOOLING. Issuing goes through MachineTokens (90 days, expiry
 * written into the token); rotation and revocation remain `php artisan tokens:issue <email> <name>
 * --rotate`. An existing, healthy account is NOT handed a new token unless --issue-token says so,
 * so re-running this to check an identity cannot quietly mint credentials.
 */
class ProvisionIntegrationAccountCommand extends Command
{
    protected $signature = 'crm:integration-account
        {--org= : The organisation this integration belongs to (id, or exact name)}
        {--provider= : Which provider it ingests for (see config/inbound.php)}
        {--days= : Token lifetime in days (default: config/inbound.php)}
        {--issue-token : Issue a token even when the account already exists}';

    protected $description = 'Provision (or inspect) the per-org, per-provider integration account for inbound conversation ingest';

    public function handle(): int
    {
        $provider = (string) $this->option('provider');
        $orgOption = (string) $this->option('org');

        if ($provider === '' || $orgOption === '') {
            $this->error('Both --org and --provider are required.');

            return self::FAILURE;
        }

        $providers = (array) config('inbound.providers');
        if (! in_array($provider, $providers, true)) {
            $this->error("Unsupported provider '{$provider}'. Allowed: ".implode(', ', $providers));

            return self::FAILURE;
        }

        $org = $this->resolveOrg($orgOption);
        if ($org === null) {
            return self::FAILURE;
        }

        $config = (array) config('inbound.service_account');
        $email = sprintf($config['email_pattern'], $provider, $org->id);
        $name = ucfirst(sprintf($config['name_pattern'], $provider, $org->id));
        $permissions = (array) $config['permissions'];

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && ! $this->accountIsSafe($existing, $org, $permissions)) {
            return self::FAILURE;
        }

        $user = $existing ?? $this->createAccount($email, $name);
        $isNew = $existing === null;

        $this->grantPermissions($user, $permissions);
        $this->attachToOrg($user, $org);

        $this->line('');
        $this->info('Integration account: '.$user->email);
        $this->line('  organisation: '.$org->name.' (id '.$org->id.')');
        $this->line('  provider:     '.$provider);
        $this->line('  roles:        '.($user->getRoleNames()->isEmpty() ? 'none (correct)' : $user->getRoleNames()->implode(', ')));
        $this->line('  permissions:  '.$user->getDirectPermissions()->pluck('name')->implode(', '));

        if ($isNew || $this->option('issue-token')) {
            $this->issueToken($user, $provider, $config);
        } else {
            $this->line('');
            $this->comment('Account already existed; no token issued. Rotate with:');
            $this->line(sprintf('  php artisan tokens:issue %s %s --rotate', $user->email, sprintf($config['token_name_pattern'], $provider)));
        }

        return self::SUCCESS;
    }

    private function resolveOrg(string $option): ?Org
    {
        if (ctype_digit($option)) {
            $org = Org::query()->find((int) $option);

            if ($org === null) {
                $this->error("No organisation with id {$option}.");
            }

            return $org;
        }

        $matches = Org::query()->where('name', $option)->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $this->error($matches->isEmpty()
            ? "No organisation named '{$option}'. Pass --org=<id> to be unambiguous."
            : "Several organisations are named '{$option}'. Pass --org=<id> to be unambiguous.");

        return null;
    }

    /**
     * Everything that would make this account something other than a narrow integration identity.
     * Reported, never repaired.
     *
     * @param  list<string>  $permissions
     */
    private function accountIsSafe(User $user, Org $org, array $permissions): bool
    {
        $membership = DB::table('org_user')->where('user_id', $user->id)->first();

        if ($membership !== null && (int) $membership->org_id !== (int) $org->id) {
            $this->error(sprintf(
                'Account %s already belongs to organisation id %d, not %d. Refusing to move it — provision a separate identity for this org, or investigate why this address is in use.',
                $user->email,
                $membership->org_id,
                $org->id,
            ));

            return false;
        }

        if ($user->getRoleNames()->isNotEmpty()) {
            $this->error(sprintf(
                'Account %s holds role(s): %s. An integration identity carries no role; refusing to strip them. Investigate before re-running.',
                $user->email,
                $user->getRoleNames()->implode(', '),
            ));

            return false;
        }

        $unexpected = $user->getDirectPermissions()->pluck('name')->diff($permissions);

        if ($unexpected->isNotEmpty()) {
            $this->error(sprintf(
                'Account %s holds permission(s) beyond the integration grant: %s. Refusing to revoke them. Investigate before re-running.',
                $user->email,
                $unexpected->implode(', '),
            ));

            return false;
        }

        return true;
    }

    private function createAccount(string $email, string $name): User
    {
        // No role, and a password nobody holds: this identity authenticates by token only. The
        // shared `status` column keeps its default, exactly as the n8n provisioning does.
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt(Str::random(48)),
        ]);
    }

    /** @param  list<string>  $permissions */
    private function grantPermissions(User $user, array $permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
            $user->givePermissionTo($permission);
        }
    }

    private function attachToOrg(User $user, Org $org): void
    {
        $already = DB::table('org_user')->where('user_id', $user->id)->exists();

        if ($already) {
            return;
        }

        $org->users()->attach($user->id, ['is_default' => true]);
    }

    /** @param  array<string, mixed>  $config */
    private function issueToken(User $user, string $provider, array $config): void
    {
        $days = (int) ($this->option('days') ?: $config['token_days']);
        $tokenName = sprintf($config['token_name_pattern'], $provider);

        $token = MachineTokens::issue($user, $tokenName, $days);

        $this->line('');
        $this->warn(sprintf(
            'Token "%s" — shown once, store it in the integration\'s own credential store. Expires %s:',
            $tokenName,
            now()->addDays($days)->toDateString(),
        ));
        $this->line($token);
        $this->line('');
        $this->comment(sprintf('Rotate or revoke later with: php artisan tokens:issue %s %s --rotate', $user->email, $tokenName));
    }
}
