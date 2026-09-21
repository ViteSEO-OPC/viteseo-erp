<?php

namespace App\Modules\Crm\Console\Commands;

use App\Models\Org;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use Illuminate\Console\Command;

/**
 * Issue (or revoke) an org-bound NERBY service token for OPERATOR use.
 *
 * CRM-initiated discovery runs and the enrichment loop mint and revoke their own tokens; nothing
 * needs a standing credential for them. This exists for the two things an operator runs by hand —
 * the standalone `python -m scraper discover` CLI and the curated `discover-all` rotation — which
 * read `CRM_TOKEN` from the scraper's own `.env`.
 *
 * The token is shown once, acts for exactly the named org, reaches only the scraper callback routes,
 * and expires. `--revoke-all` deletes every NERBY service token (rotation / incident response).
 */
class NerbyServiceTokenCommand extends Command
{
    protected $signature = 'crm:nerby-service-token
        {org? : The org id the token acts for}
        {--hours=24 : Lifetime in hours (1-720)}
        {--revoke-all : Revoke every NERBY service token instead of issuing one}';

    protected $description = 'Issue an org-bound, route-confined NERBY service token (shown once), or revoke them all';

    public function handle(NerbyServiceIdentity $service): int
    {
        if ($this->option('revoke-all')) {
            $count = $service->user()->tokens()->delete();
            $this->info("Revoked {$count} NERBY service token(s).");

            return self::SUCCESS;
        }

        $orgId = (int) $this->argument('org');
        if ($orgId <= 0 || ! Org::query()->whereKey($orgId)->exists()) {
            $this->error('Give an existing org id: crm:nerby-service-token <org>');

            return self::INVALID;
        }

        $hours = (int) $this->option('hours');
        if ($hours < 1 || $hours > 720) {
            $this->error('--hours must be between 1 and 720.');

            return self::INVALID;
        }

        $expires = now()->addHours($hours);
        $token = $service->issue($orgId, 'operator', $expires);

        $this->info('NERBY service token for org '.$orgId.' — expires '.$expires->toDateTimeString().' (shown once):');
        $this->line($token->plainTextToken);
        $this->comment('Reaches only the scraper callback routes, for this org only. Store it in scraper/.env as CRM_TOKEN.');

        return self::SUCCESS;
    }
}
