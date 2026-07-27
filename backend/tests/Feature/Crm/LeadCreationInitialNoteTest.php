<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Note;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Create Lead wizard's optional Notes field, and the Website field it previously discarded.
 *
 * The note is stored in `crm_notes` through the same service the Lead Details composer uses — there is
 * no note column on `crm_leads` — so it appears in Internal Notes and in the activity timeline with no
 * special handling. These tests pin that equivalence rather than just "a row exists".
 */
class LeadCreationInitialNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    private function businessDeveloper(): User
    {
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');

        return $bd;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Northwind Traders',
            'contact_name' => 'Ada Lovelace',
            'assigned_to' => $this->businessDeveloper()->id,
        ], $overrides);
    }

    public function test_initial_note_is_stored_in_crm_notes_with_the_creator_as_author(): void
    {
        $admin = $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/crm/leads', $this->payload([
            'note' => 'Met at the expo — asked for a proposal in May.',
        ]))->assertCreated();

        $leadId = $response->json('data.id');
        $note = Note::query()->sole();

        $this->assertSame((string) $leadId, (string) $note->lead_id);
        $this->assertSame('Met at the expo — asked for a proposal in May.', $note->body);
        // Author is the acting user, exactly as a note added later from Lead Details.
        $this->assertSame($admin->id, $note->user_id);
    }

    public function test_initial_note_is_returned_by_the_lead_notes_endpoint(): void
    {
        $this->actingAsAdmin();

        $leadId = $this->postJson('/api/v1/crm/leads', $this->payload([
            'note' => 'Prefers a call after 4pm.',
        ]))->assertCreated()->json('data.id');

        // The Lead Details → Internal Notes section reads this endpoint; no special handling needed.
        $this->getJson("/api/v1/crm/leads/{$leadId}/notes")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Prefers a call after 4pm.');
    }

    public function test_initial_note_logs_the_same_activity_as_a_note_added_later(): void
    {
        $this->actingAsAdmin();

        $leadId = $this->postJson('/api/v1/crm/leads', $this->payload([
            'note' => 'Introduced by a partner.',
        ]))->assertCreated()->json('data.id');

        // NoteObserver fires because the note is saved through the model — the wizard path gets the
        // timeline entry for free. Activities are polymorphic: the subject is the LEAD, not the note,
        // which is what puts them in the lead timeline and the ownership-scoped feeds.
        $this->assertDatabaseHas('crm_activities', [
            'subject_type' => Lead::class,
            'subject_id' => $leadId,
            'event' => 'note.created',
        ]);
    }

    public function test_no_note_is_created_when_the_field_is_omitted_or_blank(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/crm/leads', $this->payload())->assertCreated();
        $this->postJson('/api/v1/crm/leads', $this->payload([
            'business_name' => 'Blank Note Co',
            'note' => '   ',
        ]))->assertCreated();

        $this->assertSame(2, Lead::count());
        $this->assertSame(0, Note::count());
    }

    public function test_note_longer_than_the_composer_allows_is_rejected_and_no_lead_is_created(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/crm/leads', $this->payload([
            'note' => str_repeat('a', 5001),
        ]))->assertStatus(422)->assertJsonValidationErrors('note');

        // Validation runs before anything is written, so the lead is not created either.
        $this->assertSame(0, Lead::count());
        $this->assertSame(0, Note::count());
    }

    public function test_note_is_not_a_lead_column(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/crm/leads', $this->payload([
            'note' => 'Should not land on the lead row.',
        ]))->assertCreated();

        // Guards the architecture decision: the note lives only in crm_notes.
        $this->assertFalse(
            Schema::hasColumn('crm_leads', 'note'),
            'crm_leads must not gain a note column',
        );
        $this->assertFalse(
            Schema::hasColumn('crm_leads', 'notes'),
            'crm_leads must not gain a notes column',
        );
    }

    public function test_a_bd_creating_a_lead_authors_the_note_and_owns_the_lead(): void
    {
        $bd = $this->businessDeveloper();
        Sanctum::actingAs($bd);

        $leadId = $this->postJson('/api/v1/crm/leads', [
            'business_name' => 'Self Assigned Co',
            'contact_name' => 'Grace Hopper',
            'note' => 'Own lead, own note.',
        ])->assertCreated()->json('data.id');

        // The BD auto-assign rule moved to the service — assert it still holds.
        $this->assertDatabaseHas('crm_leads', ['id' => $leadId, 'assigned_to' => $bd->id]);
        $this->assertSame($bd->id, Note::query()->sole()->user_id);
    }

    public function test_website_is_persisted_from_create(): void
    {
        $this->actingAsAdmin();

        // Previously discarded: the column and validation rule already existed, but the wizard never
        // sent the field.
        $leadId = $this->postJson('/api/v1/crm/leads', $this->payload([
            'website' => 'https://northwind.example',
        ]))->assertCreated()->json('data.id');

        $this->assertDatabaseHas('crm_leads', [
            'id' => $leadId,
            'website' => 'https://northwind.example',
        ]);
    }
}
