<?php

namespace App\Modules\Crm\Console\Commands;

use App\Models\Org;
use App\Models\User;
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
 * and expires. `--revoke-all` deletes every NERBY service token — INCIDENT RESPONSE ONLY: it also kills
 * the short-lived tokens that in-flight discovery runs and enrichment ticks are using.
 *
 * ROTATION uses `--revoke-operator-except=<id>` instead: after the new operator token is proven and
 * installed, it deletes the OTHER `nerby:operator` tokens and nothing else, so a rotation can never
 * cut off a run that is mid-flight.
 */
class NerbyServiceTokenCommand extends Command
{
    /** {@see NerbyServiceIdentity::issue()} names tokens `nerby:<purpose>`; operator tokens use `operator`. */
    public const OPERATOR_TOKEN_NAME = 'nerby:operator';

    protected $signature = 'crm:nerby-service-token
        {org? : The org id the token acts for}
        {--hours=24 : Lifetime in hours (1-720)}
        {--revoke-all : Revoke every NERBY service token instead of issuing one (incident response)}
        {--revoke-operator-except= : Revoke every OTHER operator token, keeping this token id (rotation)}';

    protected $description = 'Issue an org-bound, route-confined NERBY service token (shown once), or revoke them all';

    public function handle(NerbyServiceIdentity $service): int
    {
        if ($this->option('revoke-operator-except') !== null && $this->option('revoke-all')) {
            $this->error('Choose one: --revoke-all (incident) or --revoke-operator-except (rotation).');

            return self::INVALID;
        }

        if ($this->option('revoke-all')) {
            $count = $service->user()->tokens()->delete();
            $this->info("Revoked {$count} NERBY service token(s).");

            return self::SUCCESS;
        }

        if ($this->option('revoke-operator-except') !== null) {
            return $this->revokeOtherOperatorTokens((string) $this->option('revoke-operator-except'));
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

    /**
     * Rotation's last step. Read-only lookup of the account — never {@see NerbyServiceIdentity::user()},
     * which would provision one — and the kept id must be a live operator token of that account, so a
     * typo cannot revoke every operator token and leave the scraper with nothing.
     */
    private function revokeOtherOperatorTokens(string $keep): int
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/', $keep) !== 1) {
            $this->error('--revoke-operator-except needs the numeric id of the token to keep.');

            return self::INVALID;
        }

        $user = User::query()
            ->where('email', (string) config('discovery.service_account.email', 'nerby-service@crm.local'))
            ->first();
        $operator = $user?->tokens()->where('name', self::OPERATOR_TOKEN_NAME);

        $kept = $operator === null ? null : (clone $operator)->whereKey((int) $keep)->first();
        if ($kept === null || ($kept->expires_at !== null && $kept->expires_at->isPast())) {
            $this->error('Token #'.$keep.' is not a live NERBY operator token; nothing was revoked.');

            return self::FAILURE;
        }

        $count = (clone $operator)->whereKeyNot((int) $keep)->delete();
        $this->info("Revoked {$count} other NERBY operator token(s); kept #{$keep}.");

        return self::SUCCESS;
    }
}
