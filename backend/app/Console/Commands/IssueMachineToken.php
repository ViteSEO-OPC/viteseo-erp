<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\MachineTokens;
use Illuminate\Console\Command;

/**
 * Issue (or rotate) a service account's token -- the Zerem scraper's, for one.
 *
 *   php8.4 artisan tokens:issue scraper-service@crm.local scraper --rotate
 *
 * The plain text is printed once. Put it in the service's own config
 * (/var/lib/vss-scraper/.env for the scraper) and it exists nowhere else.
 */
class IssueMachineToken extends Command
{
    protected $signature = 'tokens:issue
        {email : The service account}
        {name : What the token is for, e.g. scraper}
        {--days=90 : How many days it lives}
        {--rotate : Revoke the account\'s existing tokens of the same name first}';

    protected $description = "Issue or rotate a service account's API token, with its expiry written into it";

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        $name = (string) $this->argument('name');
        $days = (int) $this->option('days');

        if ($user === null) {
            $this->error('No account with that email.');

            return self::FAILURE;
        }

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        if ($this->option('rotate')) {
            $this->line('Revoked '.MachineTokens::revoke($user, $name)." existing '{$name}' token(s).");
        }

        $token = MachineTokens::issue($user, $name, $days);

        $this->info("Token for {$user->email} ({$name}), expires ".now()->addDays($days)->toDateString().' -- shown once:');
        $this->line($token);

        return self::SUCCESS;
    }
}
