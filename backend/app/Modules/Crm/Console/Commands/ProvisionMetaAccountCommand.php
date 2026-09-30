<?php

declare(strict_types=1);

namespace App\Modules\Crm\Console\Commands;

use App\Models\Org;
use App\Modules\Crm\Models\ProviderAccount;
use Illuminate\Console\Command;

/**
 * Connects one Meta resource — a Facebook Page or an Instagram business account — to one CRM org.
 *
 * This command is the ONLY way a public webhook delivery acquires a tenant. Nothing in a payload can
 * do it, which is why the failure modes here matter more than the happy path:
 *
 * NEVER MOVES OWNERSHIP. If another org already owns the resource, this reports which and stops. A
 * provisioning script that silently re-pointed a Page would migrate a customer's live conversations
 * to another tenant, and the only record of the decision would be its own success message.
 *
 * DEACTIVATES RATHER THAN DELETES. `--deactivate` stops a resource resolving on the very next
 * delivery while its row, and therefore the history of what used to be connected, survives for
 * audit.
 *
 * HOLDS NO CREDENTIALS. This phase has no outbound Graph API call, so there is no Page Access Token
 * to take, and the command deliberately offers nowhere to put one. When that phase arrives, tokens
 * belong in separate encrypted-at-rest storage — not beside the ownership mapping, which any future
 * admin screen will list.
 *
 * ONE RESOURCE PER INVOCATION, so a mistake affects exactly one mapping.
 */
class ProvisionMetaAccountCommand extends Command
{
    protected $signature = 'crm:meta-account
        {--org= : The organisation that owns this resource (id, or exact name)}
        {--page= : A Facebook Page ID}
        {--ig= : An Instagram business account ID}
        {--label= : Human-readable name, for the admin listing only}
        {--deactivate : Stop this resource resolving, keeping its row for audit}';

    protected $description = 'Connect (or disconnect) a Meta Page / Instagram account to a CRM organisation';

    public function handle(): int
    {
        $page = trim((string) $this->option('page'));
        $ig = trim((string) $this->option('ig'));

        if (($page === '') === ($ig === '')) {
            $this->error('Pass exactly one of --page or --ig.');

            return self::FAILURE;
        }

        [$resourceType, $externalId] = $page !== ''
            ? [(string) config('meta.resource_types.page', 'page'), $page]
            : [(string) config('meta.resource_types.instagram', 'instagram_business_account'), $ig];

        $existing = ProviderAccount::query()
            ->where('provider', 'meta')
            ->where('resource_type', $resourceType)
            ->where('external_id', $externalId)
            ->first();

        if ($this->option('deactivate')) {
            return $this->deactivate($existing, $resourceType, $externalId);
        }

        $orgOption = trim((string) $this->option('org'));

        if ($orgOption === '') {
            $this->error('--org is required.');

            return self::FAILURE;
        }

        $org = $this->resolveOrg($orgOption);

        if ($org === null) {
            return self::FAILURE;
        }

        if ($existing !== null && (int) $existing->org_id !== (int) $org->id) {
            $this->error(sprintf(
                'That %s is already connected to organisation id %d, not %d. Refusing to move it — disconnect it there first, or investigate why two tenants claim the same resource.',
                $resourceType,
                $existing->org_id,
                $org->id,
            ));

            return self::FAILURE;
        }

        $account = $existing ?? new ProviderAccount([
            'provider' => 'meta',
            'resource_type' => $resourceType,
            'external_id' => $externalId,
        ]);

        // org_id is set explicitly, never mass-assigned: moving a resource between tenants must
        // never be something a fillable array can do incidentally.
        $account->org_id = $org->id;
        $account->is_active = true;

        $label = trim((string) $this->option('label'));
        if ($label !== '') {
            $account->label = $label;
        }

        $account->save();

        $this->info(sprintf(
            '%s %s → %s (org %d)%s',
            $existing === null ? 'Connected' : 'Updated',
            $resourceType,
            $org->name,
            $org->id,
            $account->label === null ? '' : ' — '.$account->label,
        ));

        return self::SUCCESS;
    }

    private function deactivate(?ProviderAccount $existing, string $resourceType, string $externalId): int
    {
        if ($existing === null) {
            $this->error(sprintf('No %s is connected with that id.', $resourceType));

            return self::FAILURE;
        }

        $existing->is_active = false;
        $existing->save();

        $this->info(sprintf('Deactivated %s (org %d). Its row is kept for audit.', $resourceType, $existing->org_id));

        return self::SUCCESS;
    }

    /**
     * By id, or by an exact and UNAMBIGUOUS name.
     *
     * `orgs.name` carries no unique index, so two tenants really can share a name — and resolving
     * an ambiguous one with `->first()` would connect a customer's Page to whichever org happened
     * to sort first, with nothing in the output saying so.
     */
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
}
