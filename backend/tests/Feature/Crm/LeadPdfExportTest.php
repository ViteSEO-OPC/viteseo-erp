<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Single-lead PDF export — the one implementation behind both entry points (Lead Details header and
 * the BD table row action). Both hit this route, so these tests cover both surfaces.
 *
 * Authorization is `view` on the lead: the same boundary that governs opening the lead at all.
 */
class LeadPdfExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function businessDeveloper(): User
    {
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');

        return $bd;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        return $admin;
    }

    public function test_it_returns_a_pdf_download_for_a_lead(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create(['business_name' => 'Acme Industries']);

        $response = $this->get("/api/v1/crm/leads/{$lead->id}/export-pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        // Filename is slugged and carries the id so two leads sharing a name never collide.
        $response->assertDownload("lead-acme-industries-{$lead->id}.pdf");
    }

    public function test_the_response_is_a_real_pdf_document(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        // DomPDF's download() returns a normal response with the rendered bytes, not a streamed one.
        $content = $this->get("/api/v1/crm/leads/{$lead->id}/export-pdf")->assertOk()->getContent();

        // %PDF- magic bytes: proves DomPDF actually rendered rather than returning an error page.
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertGreaterThan(1000, strlen($content), 'PDF looks truncated');
    }

    public function test_a_bd_can_export_their_own_lead(): void
    {
        $bd = $this->businessDeveloper();
        Sanctum::actingAs($bd);
        $lead = Lead::factory()->create(['assigned_to' => $bd->id]);

        // The BD table's row action depends on this.
        $this->get("/api/v1/crm/leads/{$lead->id}/export-pdf")->assertOk();
    }

    public function test_a_bd_cannot_export_a_lead_they_cannot_see(): void
    {
        $bd = $this->businessDeveloper();
        Sanctum::actingAs($bd);
        $foreign = Lead::factory()->create(['assigned_to' => $this->businessDeveloper()->id]);

        // Export must not become a way around the ownership boundary.
        $this->get("/api/v1/crm/leads/{$foreign->id}/export-pdf")->assertForbidden();
    }

    public function test_it_requires_authentication(): void
    {
        // The factory references user rows for assigned_to/created_by, so seed one before creating the
        // lead — this test deliberately makes the REQUEST unauthenticated, not the fixture.
        $lead = Lead::factory()->create(['assigned_to' => $this->businessDeveloper()->id]);

        $this->getJson("/api/v1/crm/leads/{$lead->id}/export-pdf")->assertUnauthorized();
    }

    public function test_an_unassigned_lead_exports_without_error(): void
    {
        Sanctum::actingAs($this->admin());
        // Null relations must render as placeholders, not blow up the template.
        $lead = Lead::factory()->create([
            'assigned_to' => null,
            'email' => null,
            'phone' => null,
            'website' => null,
            'industry' => null,
        ]);

        $this->get("/api/v1/crm/leads/{$lead->id}/export-pdf")->assertOk();
    }
}
