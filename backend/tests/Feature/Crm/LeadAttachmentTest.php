<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\LeadAttachment;
use App\Modules\Crm\Services\LeadAttachmentStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lead Attachments — upload, list, download and delete.
 *
 * The load-bearing guarantee is per-lead isolation: an attachment must never be reachable through a
 * lead that does not own it, and a BD must never reach a file on a lead they cannot see. Those are
 * asserted directly rather than inferred from the happy path.
 */
class LeadAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake(LeadAttachmentStore::DISK);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        return $admin;
    }

    private function businessDeveloper(): User
    {
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');

        return $bd;
    }

    private function upload(Lead $lead, string $name = 'proposal.pdf'): LeadAttachment
    {
        $this->postJson("/api/v1/crm/leads/{$lead->id}/attachments", [
            'file' => UploadedFile::fake()->create($name, 12, 'application/pdf'),
        ])->assertCreated();

        return LeadAttachment::where('original_name', $name)->latest('id')->firstOrFail();
    }

    public function test_it_uploads_a_file_against_a_lead(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        $response = $this->postJson("/api/v1/crm/leads/{$lead->id}/attachments", [
            'file' => UploadedFile::fake()->create('proposal.pdf', 317, 'application/pdf'),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'proposal.pdf');
        $response->assertJsonPath('data.leadId', (string) $lead->id);

        $attachment = LeadAttachment::firstOrFail();
        $this->assertSame($lead->id, $attachment->lead_id);
        Storage::disk(LeadAttachmentStore::DISK)->assertExists($attachment->path);
    }

    public function test_it_lists_a_leads_attachments_newest_first(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        $older = $this->upload($lead, 'older.pdf');
        $newer = $this->upload($lead, 'newer.pdf');
        // Same-second creation would make the ordering ambiguous; separate them explicitly.
        $older->forceFill(['created_at' => now()->subDay()])->save();

        $response = $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments");

        $response->assertOk();
        $response->assertJsonPath('data.0.name', 'newer.pdf');
        $response->assertJsonPath('data.1.name', 'older.pdf');
        $this->assertSame($newer->id, (int) $response->json('data.0.id'));
    }

    public function test_a_lead_never_lists_another_leads_attachments(): void
    {
        Sanctum::actingAs($this->admin());
        $leadA = Lead::factory()->create();
        $leadB = Lead::factory()->create();

        $this->upload($leadA, 'proposal.pdf');
        $this->upload($leadA, 'quotation.pdf');
        $this->upload($leadB, 'permit.pdf');

        $this->getJson("/api/v1/crm/leads/{$leadB->id}/attachments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'permit.pdf');

        $this->getJson("/api/v1/crm/leads/{$leadA->id}/attachments")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_an_attachment_is_not_reachable_through_a_different_lead(): void
    {
        Sanctum::actingAs($this->admin());
        $leadA = Lead::factory()->create();
        $leadB = Lead::factory()->create();
        $onA = $this->upload($leadA, 'proposal.pdf');

        // scopeBindings: {attachment} must belong to {lead} or the binding fails to resolve.
        $this->get("/api/v1/crm/leads/{$leadB->id}/attachments/{$onA->id}/download")
            ->assertNotFound();

        $this->deleteJson("/api/v1/crm/leads/{$leadB->id}/attachments/{$onA->id}")
            ->assertNotFound();
    }

    public function test_it_downloads_an_attachment_under_its_original_name(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->upload($lead, 'proposal.pdf');

        $response = $this->get("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/download");

        $response->assertOk();
        $response->assertDownload('proposal.pdf');
    }

    public function test_deleting_removes_the_file_and_soft_deletes_the_row(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();
        $attachment = $this->upload($lead);
        $path = $attachment->path;

        $this->deleteJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}")
            ->assertNoContent();

        // Row retained for audit; bytes gone, so a "deleted" document cannot be recovered.
        $this->assertSoftDeleted('crm_lead_attachments', ['id' => $attachment->id]);
        Storage::disk(LeadAttachmentStore::DISK)->assertMissing($path);

        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_business_developer_cannot_touch_attachments_on_a_lead_they_do_not_own(): void
    {
        $owner = $this->businessDeveloper();
        $other = $this->businessDeveloper();

        Sanctum::actingAs($owner);
        $lead = Lead::factory()->create(['assigned_to' => $owner->id]);
        $attachment = $this->upload($lead);

        // The ownership boundary is LeadPolicy@view — the same one that governs opening the lead.
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/crm/leads/{$lead->id}/attachments")->assertForbidden();
        $this->get("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}/download")->assertForbidden();
        $this->postJson("/api/v1/crm/leads/{$lead->id}/attachments", [
            'file' => UploadedFile::fake()->create('sneaky.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_a_business_developer_may_delete_an_attachment_they_did_not_upload(): void
    {
        $owner = $this->businessDeveloper();
        $admin = $this->admin();

        Sanctum::actingAs($admin);
        $lead = Lead::factory()->create(['assigned_to' => $owner->id]);
        $adminUpload = $this->upload($lead, 'admin-file.pdf');

        // Attachments are shared working documents, not personal statements: anyone who can open
        // the lead manages them equally, so delete is NOT author-scoped (unlike notes).
        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/crm/leads/{$lead->id}/attachments/{$adminUpload->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('crm_lead_attachments', ['id' => $adminUpload->id]);
    }

    public function test_a_business_developer_cannot_delete_an_attachment_on_a_lead_they_cannot_see(): void
    {
        $owner = $this->businessDeveloper();
        $other = $this->businessDeveloper();

        Sanctum::actingAs($owner);
        $lead = Lead::factory()->create(['assigned_to' => $owner->id]);
        $attachment = $this->upload($lead);

        // The delete route authorizes through LeadAttachmentPolicy ALONE, so the policy's
        // lead-visibility check is the only thing standing between a BD and another BD's files.
        Sanctum::actingAs($other);
        $this->deleteJson("/api/v1/crm/leads/{$lead->id}/attachments/{$attachment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('crm_lead_attachments', [
            'id' => $attachment->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_rejects_an_oversized_file(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        $this->postJson("/api/v1/crm/leads/{$lead->id}/attachments", [
            'file' => UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_it_rejects_a_disallowed_file_type(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        $this->postJson("/api/v1/crm/leads/{$lead->id}/attachments", [
            'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_it_requires_a_file(): void
    {
        Sanctum::actingAs($this->admin());
        $lead = Lead::factory()->create();

        $this->postJson("/api/v1/crm/leads/{$lead->id}/attachments", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }
}
