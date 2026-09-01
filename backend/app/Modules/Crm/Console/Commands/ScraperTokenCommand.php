<?php

namespace App\Modules\Crm\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * Provisions (or rotates) the scoped Sanctum service token the Python scraper authenticates with.
 *
 * WHY THIS EXISTS AS A COMMAND RATHER THAN A NOTE IN A README. Sanctum tokens are per-database and a
 * `migrate:fresh` truncates `personal_access_tokens`, so the scraper's credential does not survive a
 * reseed. That is what silently happened: the token in `scraper/.env` matched no row at all, while its
 * id had since been reassigned to an unrelated login session — so the id resolved, the hash did not,
 * and every call returned 401 with nothing to indicate why. A manual `tinker` step is exactly the kind
 * of tribal knowledge that gets lost; a command makes recovery one line and repeatable.
 *
 * LEAST PRIVILEGE, AND THE ABSENCES ARE THE POINT. The account holds `crm.leads.import` and no role.
 * That authorizes the whole deterministic pipeline — enrichment ingest, the website-evidence queue,
 * candidate submission and verification — and stops there. It deliberately CANNOT reach
 * `/discovery/proposals` or `/discovery/tag-expansions`, which gate on `role:super_admin|crm_admin`
 * because they spend money at a paid provider, and it deliberately cannot confirm a website's absence:
 * `no-website` remains reachable only by a person through POST /leads/{lead}/website-absence.
 *
 * @see N8nTokenCommand the same pattern for the n8n integration
 */
class ScraperTokenCommand extends Command
{
    protected $signature = 'crm:scraper-token {--rotate : Revoke this account\'s existing tokens before issuing a new one}';

    protected $description = 'Provision/rotate the scoped Sanctum service token for the Python scraper service account';

    public function handle(): int
    {
        /** @var array{email: string, name: string, permissions: list<string>} $cfg */
        $cfg = config('scraper.service_account');

        $user = User::query()->firstOrCreate(
            ['email' => $cfg['email']],
            // No `status`: the CRM writes nothing to that shared column. The DB default is `active`.
            ['name' => $cfg['name'], 'password' => bcrypt(Str::random(48))],
        );

        foreach ($cfg['permissions'] as $permission) {
            Permission::findOrCreate($permission);
            $user->givePermissionTo($permission);
        }

        if ($this->option('rotate')) {
            // Scoped to THIS account by construction — the service account is dedicated, so its tokens
            // are exactly the scraper's prior tokens. No human's session is ever revoked here.
            $user->tokens()->delete();
        }

        $token = $user->createToken('scraper')->plainTextToken;

        $this->info('Scraper service account: '.$user->email);
        $this->line('Scoped permissions: '.implode(', ', $cfg['permissions']));
        $this->warn('Token (shown once — copy it into CRM_TOKEN in scraper/.env):');
        $this->line($token);
        $this->newLine();
        $this->comment('Tokens are per-database: this one authenticates only against the deployment that issued it, and a `migrate:fresh` invalidates it. Re-run this command after a reseed.');
        $this->comment('This account cannot confirm a website absence — `no-website` stays a human decision.');

        return self::SUCCESS;
    }
}
