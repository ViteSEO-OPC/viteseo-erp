<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Crm\Models\EmailTemplate;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Outreach with no templates is a product that looks broken on first use.
 *
 * The email catalogue arrives from a seeder that ran ONCE, before tenancy. The
 * provider org therefore has fourteen templates and every client onboarded after it
 * had none — not an error, not a warning, just an empty Outreach screen on the day
 * they start paying.
 *
 * This is the CRM half of the same shape as HR's missing tax table, and it is
 * deliberately the only half: niches are empty ON PURPOSE (NicheSeeder::BUILT_INS is
 * awaiting approval) and the business-rules baseline falls back to config('scoring')
 * and logs it, so neither is a hole. See CrmProvisioner for why this class is short.
 */
class ANewCrmOrgHasSomethingToSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_crm_org_starts_with_the_email_catalogue(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = app(OrgOnboardingService::class)->createOrg(
            'Outreach Client', 'Owner', 'outreach@client.ph', 'password-123', ['crm' => 'basic'],
        )['org'];

        $count = CurrentOrg::runAs($org->id, fn (): int => EmailTemplate::query()->count());

        $this->assertGreaterThan(0, $count, 'A paying CRM tenant opened Outreach with nothing to send.');
    }

    public function test_the_catalogue_is_the_orgs_own_copy(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $svc = app(OrgOnboardingService::class);
        $a = $svc->createOrg('A', 'Owner', 'a-out@client.ph', 'password-123', ['crm' => 'basic'])['org'];
        $b = $svc->createOrg('B', 'Owner', 'b-out@client.ph', 'password-123', ['crm' => 'basic'])['org'];

        $count = fn (int $id): int => CurrentOrg::runAs($id, fn (): int => EmailTemplate::query()->count());

        // Editing one tenant's templates must not touch another's -- they are copies
        // provisioned from the catalogue, not a shared view onto it.
        CurrentOrg::runAs($a->id, fn () => EmailTemplate::query()->delete());

        $this->assertSame(0, $count($a->id));
        $this->assertGreaterThan(0, $count($b->id), "Emptying A's templates emptied B's.");
    }
}
