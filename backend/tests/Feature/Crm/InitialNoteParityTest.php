<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Note;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Parity between the note captured by the Create Lead wizard and one added later from Lead Details.
 *
 * The wizard note is not a special kind of note — it is written by the same service, into the same
 * table, with the same author association. These tests pin that equivalence at the level that matters
 * to a user: the same list, the same edit/delete affordances, the same audit trail. If someone later
 * introduces an "initial note" concept, these fail.
 */
class InitialNoteParityTest extends TestCase
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

    /**
     * Creates a lead carrying an initial note, then adds a second note the normal way.
     *
     * @return array{0: string, 1: Note, 2: Note} [leadId, wizardNote, laterNote]
     */
    private function twoNotes(): array
    {
        $leadId = (string) $this->postJson('/api/v1/crm/leads', [
            'business_name' => 'Parity Co',
            'contact_name' => 'Ada Lovelace',
            'assigned_to' => $this->businessDeveloper()->id,
            'note' => 'Note A — created with the lead.',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/crm/leads/{$leadId}/notes", [
            'body' => 'Note B — added later from Lead Details.',
        ])->assertCreated();

        $wizard = Note::query()->where('body', 'like', 'Note A%')->sole();
        $later = Note::query()->where('body', 'like', 'Note B%')->sole();

        return [$leadId, $wizard, $later];
    }

    public function test_both_notes_come_back_from_the_same_endpoint_in_the_same_list(): void
    {
        $this->actingAsAdmin();
        [$leadId] = $this->twoNotes();

        $bodies = collect(
            $this->getJson("/api/v1/crm/leads/{$leadId}/notes")->assertOk()->json('data')
        )->pluck('body')->all();

        $this->assertSame([
            'Note A — created with the lead.',
            'Note B — added later from Lead Details.',
        ], $bodies);
    }

    public function test_both_notes_are_persisted_identically_apart_from_body_and_timestamps(): void
    {
        $this->actingAsAdmin();
        [$leadId, $wizard, $later] = $this->twoNotes();

        // Same lead, same author, same soft-delete state — nothing marks one as "initial".
        $this->assertSame((string) $leadId, (string) $wizard->lead_id);
        $this->assertSame((string) $leadId, (string) $later->lead_id);
        $this->assertSame($wizard->user_id, $later->user_id);
        $this->assertNull($wizard->deleted_at);
        $this->assertNull($later->deleted_at);
    }

    public function test_the_notes_table_has_no_column_that_could_distinguish_them(): void
    {
        // No `type`, `source`, `origin` or `is_initial` discriminator exists to branch on.
        foreach (['type', 'source', 'origin', 'is_initial', 'kind'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('crm_notes', $column),
                "crm_notes must not gain a '{$column}' discriminator",
            );
        }
    }

    public function test_both_notes_can_be_edited_through_the_existing_endpoint(): void
    {
        $this->actingAsAdmin();
        [$leadId, $wizard, $later] = $this->twoNotes();

        foreach ([$wizard, $later] as $note) {
            $this->patchJson("/api/v1/crm/leads/{$leadId}/notes/{$note->id}", [
                'body' => "edited {$note->id}",
            ])->assertOk();

            $this->assertSame("edited {$note->id}", $note->fresh()->body);
        }
    }

    public function test_both_notes_can_be_deleted_through_the_existing_endpoint(): void
    {
        $this->actingAsAdmin();
        [$leadId, $wizard, $later] = $this->twoNotes();

        foreach ([$wizard, $later] as $note) {
            $this->deleteJson("/api/v1/crm/leads/{$leadId}/notes/{$note->id}")->assertNoContent();
            $this->assertSoftDeleted('crm_notes', ['id' => $note->id]);
        }

        // Both disappear from the list the UI reads.
        $this->assertSame([], $this->getJson("/api/v1/crm/leads/{$leadId}/notes")->assertOk()->json('data'));
    }

    public function test_both_notes_produce_the_same_activity_events(): void
    {
        $this->actingAsAdmin();
        [$leadId, $wizard, $later] = $this->twoNotes();

        foreach ([$wizard, $later] as $note) {
            $this->patchJson("/api/v1/crm/leads/{$leadId}/notes/{$note->id}", ['body' => 'edited'])->assertOk();
            $this->deleteJson("/api/v1/crm/leads/{$leadId}/notes/{$note->id}")->assertNoContent();
        }

        // One created/updated/deleted per note — the wizard note is not missing any event, and is not
        // logged under a different one.
        foreach (['note.created', 'note.updated', 'note.deleted'] as $event) {
            $this->assertSame(
                2,
                Activity::query()
                    ->where('subject_type', Lead::class)
                    ->where('subject_id', $leadId)
                    ->where('event', $event)
                    ->count(),
                "expected both notes to log {$event}",
            );
        }
    }

    public function test_the_author_can_edit_their_own_wizard_note_without_being_a_manager(): void
    {
        // A BD creates their own lead + initial note, then edits it. Proves the wizard note carries a
        // real author for NotePolicy, rather than being an unowned system record.
        $bd = $this->businessDeveloper();
        Sanctum::actingAs($bd);

        $leadId = $this->postJson('/api/v1/crm/leads', [
            'business_name' => 'BD Owned Co',
            'contact_name' => 'Grace Hopper',
            'note' => 'My own initial note.',
        ])->assertCreated()->json('data.id');

        $note = Note::query()->sole();

        $this->patchJson("/api/v1/crm/leads/{$leadId}/notes/{$note->id}", [
            'body' => 'Edited by its author.',
        ])->assertOk();

        $this->assertSame('Edited by its author.', $note->fresh()->body);
    }
}
