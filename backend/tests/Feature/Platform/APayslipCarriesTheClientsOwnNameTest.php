<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Hr\Services\CompanyProfileService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The vendor's legal entity was printing on the client's payslips.
 *
 * `CompanyProfileService::DEFAULTS` held the PROVIDER's legal name and Makati
 * address, and `get()` creates the row for whichever org is current. Correct
 * while this was one company's HRIS; a tenancy leak the moment it was not.
 * Onboarding a client handed them a company profile carrying somebody else's
 * legal entity, and every payslip they issued printed it — measured on a demo
 * client org, whose `legal_name` read VITE SEO DIGITAL ADVERTISING OPC.
 *
 * A payslip is a legal document. This is not cosmetic.
 */
class APayslipCarriesTheClientsOwnNameTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER = 'VITE SEO DIGITAL ADVERTISING OPC';

    public function test_a_new_client_gets_its_own_name_on_the_letterhead(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $org = app(OrgOnboardingService::class)->createOrg(
            'Sinag Digital Marketing', 'Maria', 'maria@sinag.ph', 'password-123', ['hr' => 'full'],
        )['org'];

        $profile = CurrentOrg::runAs($org->id, fn () => app(CompanyProfileService::class)->get());

        $this->assertSame('Sinag Digital Marketing', $profile->legal_name);
        $this->assertNotSame(self::PROVIDER, $profile->legal_name);

        // Blank beats somebody else's address: theirs to enter, nobody else's to assume.
        $this->assertNull($profile->address);
    }

    public function test_two_clients_do_not_share_a_letterhead(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $service = app(OrgOnboardingService::class);
        $a = $service->createOrg('Sinag Digital', 'A', 'a@sinag.ph', 'password-123', ['hr' => 'full'])['org'];
        $b = $service->createOrg('Bituin Studio', 'B', 'b@bituin.ph', 'password-123', ['hr' => 'full'])['org'];

        // A fresh service per org: the memo is per-request, and reusing one would
        // prove the memo works rather than the scoping.
        $nameA = CurrentOrg::runAs($a->id, fn () => app()->make(CompanyProfileService::class)->get()->legal_name);
        $nameB = CurrentOrg::runAs($b->id, fn () => app()->make(CompanyProfileService::class)->get()->legal_name);

        $this->assertSame('Sinag Digital', $nameA);
        $this->assertSame('Bituin Studio', $nameB);
    }
}
