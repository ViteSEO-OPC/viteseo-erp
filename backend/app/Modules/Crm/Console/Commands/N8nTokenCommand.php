<?php

namespace App\Modules\Crm\Console\Commands;

use App\Models\User;
use App\Support\MachineTokens;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * RC3 Phase 5 — provisions (or rotates) the dedicated scoped Sanctum service account for n8n → CRM
 * calls. The account holds only the minimal outreach/planner permissions from config('n8n.service_account')
 * and is subject to the existing policies (no bypass). It reuses the existing endpoints — n8n never sends
 * email directly and never owns a schedule.
 */
class N8nTokenCommand extends Command
{
    protected $signature = 'n8n:token {--rotate : Revoke existing tokens before issuing a new one}';

    protected $description = 'Provision/rotate the scoped Sanctum service token for the n8n service account';

    public function handle(): int
    {
        /** @var array{email: string, name: string, permissions: list<string>} $cfg */
        $cfg = config('n8n.service_account');

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
            $user->tokens()->delete();
        }

        $token = MachineTokens::issue($user, 'n8n');

        $this->info('n8n service account: '.$user->email);
        $this->line('Scoped permissions: '.implode(', ', $cfg['permissions']));
        $this->warn('Token (shown once — store it securely in the n8n credential store), expires '
            .now()->addDays(MachineTokens::DEFAULT_DAYS)->toDateString().':');
        $this->line($token);
        $this->newLine();
        $this->comment('Note: cross-lead actions also require lead visibility per your authorization policy (assign a management role or scope to owned leads). n8n never sends email directly and never owns a schedule.');

        return self::SUCCESS;
    }
}
