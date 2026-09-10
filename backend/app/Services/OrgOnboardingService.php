<?php

declare(strict_types=1);

namespace App\Services;

use App\Modules\Crm\Support\CrmProvisioner;
use App\Modules\Hr\Support\HrProvisioner;
use App\Models\Org;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Accounting\Services\ChartOfAccountsProvisioner;
use App\Support\Entitlements;
use App\Support\Seats;
use App\Support\SystemRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Provisions a new multi-product tenant end-to-end (sales-assisted onboarding):
 * create the org → provision only what's availed (chart of accounts + Finance
 * Admin for Accounting; Inventory Manager for Inventory; Booking Manager for
 * Booking) → enable each availed
 * system's tier functions (+ add-ons, deps resolved) → create its first admin
 * (attached as default) → start ONE trialing subscription (14-day free trial,
 * immediate access) whose price is the SUM of the availed systems' tiers.
 *
 * No invoice/receivable is raised here; revenue is booked only when a payment is
 * recorded via SubscriptionService::activate(), and access lapses to suspended
 * when the trial/period ends unpaid.
 */
class OrgOnboardingService
{
    /**
     * The administrator role a client's first account gets, per availed system.
     *
     * This is the account the platform hands over at onboarding -- what Julius
     * calls the client's super admin. It is per-SYSTEM rather than one platform
     * role because a client that bought only CRM has no business administering
     * an HR module it does not have.
     *
     * @var array<string, string>
     */
    private const ADMIN_ROLES = [
        'hr' => 'hr_officer',
        'crm' => 'crm_admin',
        'pms' => 'pm_apm',
        'accounting' => 'acc_admin',
        'inventory' => 'inv_admin',
        'booking' => 'booking_admin',
    ];

    public function __construct(
    ) {}

    /**
     * Provision a tenant availing one or more systems. Each system prices on its
     * own tier; the org carries a single subscription = the sum.
     *
     * @param  array<string, string>  $systems  system key => tier key (e.g. ['accounting' => 'basic', 'inventory' => 'pro'])
     * @param  list<string>  $extraFeatures  Add-on feature keys on top of the tiers.
     * @param  User|null  $actor  Reserved for future onboarding hooks (unused).
     * @return array{org: Org, admin: User, features: list<string>, subscription: Subscription}
     */
    public function createOrg(
        string $orgName,
        string $adminName,
        string $adminEmail,
        string $adminPassword,
        array $systems,
        array $extraFeatures = [],
        ?User $actor = null,
    ): array {
        // Keep only known systems with a valid tier; never provision nothing.
        $systems = array_filter(
            $systems,
            static fn ($tier, $system): bool => is_string($tier) && SystemRegistry::tierExists($system, $tier),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($systems === []) {
            // A default that must be a system this repo actually sells. It used to be
            // 'accounting', which now provisions an org for a product that is not here.
            $systems = ['crm' => 'basic'];
        }

        $result = DB::transaction(function () use ($orgName, $adminName, $adminEmail, $adminPassword, $systems, $extraFeatures): array {
            $org = Org::create(['name' => $orgName, 'is_active' => true]);

            /*
             * Per-system provisioning. HRIS is the one that must not be skipped.
             *
             * Payroll resolves everything from DATED, ORG-SCOPED rows: the BIR
             * withholding table, the SSS/PhilHealth/Pag-IBIG schedules, the payroll
             * settings versions, the attendance policy, the work schedules. The
             * provider org got all of those from migrations and seeders that ran once,
             * pre-tenancy, and were adopted by the org_id backfill. An org created
             * AFTER that has none of them -- and nearly every gap fails SILENTLY: no
             * tax table means PHP 0 withheld, no contribution version means PHP 0
             * SSS/PhilHealth/Pag-IBIG, no work schedule means zero scheduled workdays.
             * The run completes and every payslip looks entirely ordinary.
             *
             * Inventory's default location used to be provisioned here too; that
             * module left this repo on 2026-09-03 and has not come back.
             */
            if (array_key_exists('hr', $systems)) {
                app(HrProvisioner::class)->provisionFor($org);
            }

            if (array_key_exists('crm', $systems)) {
                app(CrmProvisioner::class)->provisionFor($org);
            }

            /*
             * A ledger with no accounts is not an empty ledger, it is a broken one:
             * there is nothing to debit and nothing to credit, so the first journal
             * entry a bookkeeper tries to write has no accounts to pick from and the
             * screen dead-ends on an empty select.
             *
             * This is the silent kind of gap. The module answers 200 with `data: []`
             * -- indistinguishable from a real org that has simply not posted
             * anything yet -- which is exactly how it read as working when Accounting
             * came back on 2026-09-05 and this line had not.
             */
            if (array_key_exists('accounting', $systems)) {
                app(ChartOfAccountsProvisioner::class)->seedFor($org);
            }

            // Union of every availed tier's features + explicit add-ons (deps resolved).
            $features = $extraFeatures;
            foreach ($systems as $system => $tier) {
                $tierDef = SystemRegistry::registry($system)::get($tier);
                $features = [...$features, ...($tierDef['features'] ?? [])];
            }
            $features = array_values(array_unique($features));
            $enabled = Entitlements::enable($org->id, $features);

            // First admin (password hashed by the User cast). A role per availed
            // system so they land in the right portal(s).
            $admin = User::create([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => $adminPassword,
            ]);
            /*
             * The client's OWN administrator, one role per availed system.
             *
             * Until 2026-09-03 this named three systems and only three. An org that
             * availed HRIS, CRM or Projects was created with an admin holding NO
             * role at all -- it could not manage its own staff, could not build a
             * role, could not reach its own module. The three ViteSEO actually
             * sells were the three that came out unusable.
             *
             * Driven off a map rather than a run of ifs so the next system to be
             * sold is one line, not another branch somebody forgets.
             */
            foreach (self::ADMIN_ROLES as $system => $role) {
                if (array_key_exists($system, $systems)) {
                    $admin->assignRole($role);
                }
            }

            // Membership — the user's default (login) org.
            $org->users()->attach($admin->id, ['is_default' => true]);

            // Billing source of truth. New tenants start on a free trial so they
            // can explore immediately; access lapses when the trial ends unless a
            // payment is recorded (SubscriptionService::activate). Price = the sum
            // of each availed system's tier (+ its own add-on functions).
            $price = 0.0;
            foreach ($systems as $system => $tier) {
                $price += SystemRegistry::priceFor($system, $tier, $enabled);
            }

            $subscription = Subscription::create([
                'org_id' => $org->id,
                'plan' => $this->planLabel($systems),
                'monthly_price' => round($price, 2),
                'seat_limit' => Seats::BASE, // 10 seats included; more availed later
                'status' => Subscription::TRIALING,
                'current_period_end' => now()->addDays(Subscription::TRIAL_DAYS),
            ]);

            return ['org' => $org, 'admin' => $admin, 'features' => $enabled, 'subscription' => $subscription];
        });

        return $result;
    }

    /**
     * Backward-compatible accounting-only onboarding (public self-signup). Kept as
     * a thin wrapper so existing callers and stored plan strings are unchanged.
     *
     * @param  list<string>  $extraFeatures  Add-on feature keys on top of the plan.
     * @param  User|null  $actor  Reserved for future onboarding hooks (unused).
     * @return array{org: Org, admin: User, features: list<string>, subscription: Subscription}
     */
    public function createAccountingOrg(
        string $orgName,
        string $adminName,
        string $adminEmail,
        string $adminPassword,
        string $plan = 'basic',
        array $extraFeatures = [],
        ?User $actor = null,
    ): array {
        return $this->createOrg(
            $orgName,
            $adminName,
            $adminEmail,
            $adminPassword,
            ['accounting' => $plan],
            $extraFeatures,
            $actor,
        );
    }

    /**
     * The stored `plan` label. Accounting-only keeps the bare tier key (byte-for-
     * byte backward compatible with existing rows + notification copy); a
     * multi-product org reads e.g. "accounting:basic + inventory:pro".
     *
     * @param  array<string, string>  $systems
     */
    private function planLabel(array $systems): string
    {
        if (array_keys($systems) === ['accounting']) {
            return $systems['accounting'];
        }

        $parts = [];
        foreach ($systems as $system => $tier) {
            $parts[] = $system.':'.$tier;
        }

        return implode(' + ', $parts);
    }
}
