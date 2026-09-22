<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console\Commands;

use App\Models\Org;
use App\Modules\Crm\Models\CallProviderSetting;
use App\Modules\Crm\Services\Calling\TwilioCallProvider;
use App\Modules\Crm\Services\Calling\TwilioConfig;
use Illuminate\Console\Command;

/**
 * `php artisan calling:doctor` — what calling is actually configured to do on this deployment, and
 * whether that configuration is sound.
 *
 * ── Why a command rather than a startup check ──
 * The condition it exists for (per-organization accounts switched on with no real central organization
 * named) is fail-closed but invisible: ViteSEO's own organization silently stops being recognised as
 * central and loses browser calling. {@see TwilioConfig::reportMisconfiguration()} logs that as
 * critical when it happens, but a log line is only read after someone already suspects a problem. This
 * is the thing you run BEFORE switching per-organization accounts on, and after changing the setting.
 *
 * ── It reads, and never writes ──
 * Nothing here saves a row, places a call, or contacts Twilio. Verifying credentials against Twilio is
 * the settings screen's "Test connection", which belongs to the organization that owns the account and
 * is authenticated and rate-limited; a console command that could spend a client's money or ring a real
 * number is not a diagnostic.
 *
 * ── It never prints a secret ──
 * Every organization line reports presence and verdicts only — enabled, complete, verified, the last
 * test status. Account SIDs and API key SIDs are identifiers Twilio itself displays, so the summary
 * counts them but does not need to print them; the Auth Token and API Key Secret are never read at all.
 *
 * Exit code 1 on a misconfiguration, so it can gate a deploy step unchanged.
 */
class CallingDoctorCommand extends Command
{
    protected $signature = 'calling:doctor';

    protected $description = 'Report how calling is configured on this deployment and whether that configuration is sound';

    public function handle(): int
    {
        // A fresh verdict every run: the once-per-process suppression exists for the hot resolution
        // path, and an operator running this command is explicitly asking.
        TwilioConfig::flushMisconfigurationReports();

        $this->line('');
        $this->components->info('Calling configuration');

        $perOrg = TwilioConfig::organizationCredentialsEnabled();

        $this->components->twoColumnDetail(
            'Transport selected (CALL_PROVIDER)',
            (string) config('calling.provider', 'manual'),
        );
        $this->components->twoColumnDetail(
            'Per-organization accounts',
            $perOrg ? '<fg=yellow>on</>' : 'off (default)',
        );

        $this->reportCentralAccount();

        if (! $perOrg) {
            // Mode off is the default and is always sound: every organization uses the centralized
            // account, and CALLING_CENTRAL_ORG_ID selects nothing at all.
            $this->line('');
            $this->components->twoColumnDetail(
                'Every organization uses',
                'the centralized ViteSEO account',
            );
            $this->newLine();
            $this->components->info('Configuration is sound.');

            return self::SUCCESS;
        }

        $this->reportCentralOrg();
        $this->reportOrganizations();

        $reason = TwilioConfig::misconfiguration();

        $this->newLine();

        if ($reason === null) {
            $this->components->info('Configuration is sound.');

            return self::SUCCESS;
        }

        $this->components->error(TwilioConfig::MISCONFIG_MESSAGES[$reason]);
        $this->line('  No organization is carried on the centralized ViteSEO Twilio account. Every');
        $this->line('  organization — ViteSEO\'s own included — is resolved against its own stored');
        $this->line('  credentials only, and one without them gets manual calling.');
        $this->newLine();
        $this->line('  Fix: set CALLING_CENTRAL_ORG_ID to the id of ViteSEO\'s own organization,');
        $this->line('  or set CALLING_ORGANIZATION_CREDENTIALS=false to return to the centralized default.');

        return self::FAILURE;
    }

    /**
     * The deployment's own TWILIO_* values, as presence only.
     *
     * An incomplete centralized account is NOT a misconfiguration: a deployment that has not bought a
     * Twilio account runs manual calling, which is the CRM's supported default and the state every
     * developer machine and CI run is in. It is reported because it explains a "manual calling" that
     * would otherwise look like a fault.
     */
    private function reportCentralAccount(): void
    {
        $config = TwilioConfig::fromDeployment();
        $missing = $config->missing();

        $this->components->twoColumnDetail(
            'Centralized ViteSEO account (TWILIO_*)',
            $missing === []
                ? '<fg=green>complete</>'
                : '<fg=gray>not configured</> — missing '.implode(', ', $missing),
        );
    }

    /** Which organization is designated central, and whether it exists. */
    private function reportCentralOrg(): void
    {
        $raw = config('calling.central_org_id');
        $central = TwilioConfig::centralOrgId();

        if (blank($raw)) {
            $this->components->twoColumnDetail('Central organization', '<fg=red>not set</>');

            return;
        }

        if ($central === null) {
            $this->components->twoColumnDetail(
                'Central organization',
                '<fg=red>invalid</> — CALLING_CENTRAL_ORG_ID is not a positive integer',
            );

            return;
        }

        $name = Org::query()->whereKey($central)->value('name');

        $this->components->twoColumnDetail(
            'Central organization',
            $name === null
                ? "<fg=red>#{$central} does not exist</>"
                : "<fg=green>#{$central}</> {$name}",
        );
    }

    /**
     * One line per organization: what it is carried on, and — for an organization on its own account —
     * the three conditions browser calling needs. Verdicts only; no credential is printed.
     */
    private function reportOrganizations(): void
    {
        $this->newLine();
        $this->components->info('Organizations');

        $rows = CallProviderSetting::query()
            ->withoutGlobalScope('org')
            ->where('provider', TwilioCallProvider::NAME)
            ->get()
            ->keyBy('org_id');

        foreach (Org::query()->orderBy('id')->get(['id', 'name']) as $org) {
            $orgId = (int) $org->getKey();

            if (TwilioConfig::isCentralOrg($orgId)) {
                $this->components->twoColumnDetail(
                    "#{$orgId} {$org->name}",
                    'centralized ViteSEO account'.($rows->has($orgId) ? ' <fg=gray>(stored row ignored)</>' : ''),
                );

                continue;
            }

            $row = $rows->get($orgId);

            if ($row === null) {
                $this->components->twoColumnDetail("#{$orgId} {$org->name}", '<fg=gray>manual calling — no account</>');

                continue;
            }

            $config = TwilioConfig::fromSettings($row);

            $this->components->twoColumnDetail(
                "#{$orgId} {$org->name}",
                $config->isUsable()
                    ? '<fg=green>browser calling</> — own account'
                    : '<fg=yellow>manual calling</> — own account: '.$this->why($config),
            );
        }
    }

    /** Why an organization's own account is not carrying browser calling, in the card's own terms. */
    private function why(TwilioConfig $config): string
    {
        $reasons = [];

        if (! $config->enabled) {
            $reasons[] = 'switched off';
        }

        if (! $config->isComplete()) {
            $reasons[] = 'missing '.implode(', ', $config->missing());
        }

        if (! $config->isVerified()) {
            $reasons[] = $config->lastTestStatus === null
                ? 'never tested'
                : "last test {$config->lastTestStatus}";
        }

        return implode('; ', $reasons);
    }
}
