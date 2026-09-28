<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Note;
use App\Support\TiptapDocument;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rich-text internal notes: the two-column contract, and the guarantees that make it safe.
 *
 * A note stores `body` (canonical readable text, never null) and `body_doc` (the Tiptap document, or
 * NULL for a legacy/imported note). The tests below pin the three things that must never regress:
 *
 *  1. `body` is DERIVED server-side from the document. The client's copy is a hint and is discarded,
 *     so the stored text can never contradict the stored document.
 *  2. Validation rejects hostile documents in the order bytes → depth → types, and the character
 *     limit is measured on the extracted text rather than the JSON blob.
 *  3. A note with `body_doc = NULL` keeps behaving exactly as it did before rich text existed — no
 *     migration, no backfill, no special-casing.
 */
class NoteRichTextTest extends TestCase
{
    use RefreshDatabase;

    private User $bd;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->bd = User::factory()->create(['status' => 'active']);
        $this->bd->assignRole('crm_bd');
        $this->lead = Lead::factory()->create(['assigned_to' => $this->bd->id]);

        Sanctum::actingAs($this->bd);
    }

    private function notesUrl(): string
    {
        return "/api/v1/crm/leads/{$this->lead->id}/notes";
    }

    /** A document: one bold paragraph and a two-item bullet list. */
    private function richDoc(): array
    {
        return ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Called the owner', 'marks' => [['type' => 'bold']]],
            ]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Wants a quote']]],
                ]],
                ['type' => 'listItem', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Call back Friday']]],
                ]],
            ]],
        ]];
    }

    private function nested(int $depth): array
    {
        $node = ['type' => 'paragraph'];
        for ($i = 1; $i < $depth; $i++) {
            $node = ['type' => 'paragraph', 'content' => [$node]];
        }

        return $node;
    }

    // ── Persistence ─────────────────────────────────────────────────────────────────────────────

    public function test_a_valid_rich_document_is_persisted(): void
    {
        $doc = $this->richDoc();

        $response = $this->postJson($this->notesUrl(), [
            'body' => 'Called the owner',
            'body_doc' => $doc,
        ])->assertCreated();

        $note = Note::query()->latest('id')->firstOrFail();

        $this->assertSame($doc, $note->body_doc);
        $this->assertSame($doc, $response->json('data.bodyDoc'));
    }

    public function test_the_stored_body_is_the_servers_projection_of_the_document(): void
    {
        $doc = $this->richDoc();

        $this->postJson($this->notesUrl(), ['body' => 'Called the owner', 'body_doc' => $doc])
            ->assertCreated();

        $note = Note::query()->latest('id')->firstOrFail();

        // Exactly what extractText() produces — not the client's shorter string.
        $this->assertSame(TiptapDocument::extractText($doc), $note->body);
        $this->assertStringContainsString('Wants a quote', $note->body);
        $this->assertStringContainsString('Call back Friday', $note->body);
    }

    public function test_a_client_body_that_disagrees_with_the_document_cannot_bypass_the_projection(): void
    {
        // The attack this closes: post harmless-looking text next to a document saying something
        // else, so every text-only surface (search, exports, the Activity feed) reports the lie
        // while the note renders the real content. The submitted `body` is discarded.
        $doc = $this->richDoc();

        $this->postJson($this->notesUrl(), [
            'body' => 'Nothing to see here',
            'body_doc' => $doc,
        ])->assertCreated();

        $note = Note::query()->latest('id')->firstOrFail();

        $this->assertNotSame('Nothing to see here', $note->body);
        $this->assertSame(TiptapDocument::extractText($doc), $note->body);
    }

    public function test_an_edit_re_derives_the_body_from_the_new_document(): void
    {
        $note = Note::query()->forceCreate([
            'lead_id' => $this->lead->id,
            'user_id' => $this->bd->id,
            'body' => 'original',
            'body_doc' => null,
            'org_id' => $this->lead->org_id,
        ]);

        $doc = $this->richDoc();

        $this->patchJson("{$this->notesUrl()}/{$note->id}", [
            'body' => 'a lie',
            'body_doc' => $doc,
        ])->assertOk();

        $note->refresh();

        $this->assertSame($doc, $note->body_doc);
        $this->assertSame(TiptapDocument::extractText($doc), $note->body);
        $this->assertNotSame('a lie', $note->body);
    }

    // ── Legacy compatibility ────────────────────────────────────────────────────────────────────

    public function test_a_plain_text_note_still_stores_a_null_document(): void
    {
        // The importer and the Create Lead wizard send only `body`. That path must be untouched.
        $this->postJson($this->notesUrl(), ['body' => 'Plain text only'])->assertCreated();

        $note = Note::query()->latest('id')->firstOrFail();

        $this->assertNull($note->body_doc);
        $this->assertSame('Plain text only', $note->body);
    }

    public function test_a_legacy_note_reads_back_with_a_null_document(): void
    {
        Note::query()->forceCreate([
            'lead_id' => $this->lead->id,
            'user_id' => $this->bd->id,
            'body' => "Legacy note\nsecond line",
            'body_doc' => null,
            'org_id' => $this->lead->org_id,
        ]);

        $row = $this->getJson($this->notesUrl())->assertOk()->json('data.0');

        $this->assertNull($row['bodyDoc']);
        $this->assertSame("Legacy note\nsecond line", $row['body']);
    }

    public function test_a_rich_note_reads_back_with_its_document(): void
    {
        $doc = $this->richDoc();
        $this->postJson($this->notesUrl(), ['body' => 'x', 'body_doc' => $doc])->assertCreated();

        $row = $this->getJson($this->notesUrl())->assertOk()->json('data.0');

        $this->assertSame($doc, $row['bodyDoc']);
        $this->assertSame(TiptapDocument::extractText($doc), $row['body']);
    }

    public function test_an_explicit_null_document_is_accepted_as_plain_text(): void
    {
        $this->postJson($this->notesUrl(), ['body' => 'Plain', 'body_doc' => null])
            ->assertCreated();

        $this->assertNull(Note::query()->latest('id')->firstOrFail()->body_doc);
    }

    // ── Validation ──────────────────────────────────────────────────────────────────────────────

    public function test_a_document_with_a_disallowed_node_is_rejected(): void
    {
        $this->postJson($this->notesUrl(), [
            'body' => 'x',
            'body_doc' => ['type' => 'doc', 'content' => [['type' => 'script']]],
        ])->assertStatus(422)->assertJsonValidationErrors('body_doc');

        $this->assertSame(0, Note::query()->count());
    }

    public function test_a_document_with_a_disallowed_mark_is_rejected(): void
    {
        $this->postJson($this->notesUrl(), [
            'body' => 'x',
            'body_doc' => ['type' => 'doc', 'content' => [
                ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'onclick']]],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('body_doc');
    }

    public function test_a_document_nested_too_deeply_is_rejected(): void
    {
        $this->postJson($this->notesUrl(), [
            'body' => 'x',
            'body_doc' => $this->nested(TiptapDocument::MAX_DEPTH + 5),
        ])->assertStatus(422)->assertJsonValidationErrors('body_doc');
    }

    public function test_depth_is_rejected_before_node_types_are_inspected(): void
    {
        // 🔒 THE ORDERING GUARANTEE. `disallowedTypes()` cannot see below MAX_DEPTH — it returns an
        // EMPTY list for a `script` buried at depth 55 (pinned in TiptapDocumentTest). If the type
        // check ran first, or ran after a depth failure without returning, this document would be
        // accepted with an arbitrary node type inside it.
        $deep = $this->nested(55);
        $cursor = &$deep;
        while (isset($cursor['content'])) {
            $cursor = &$cursor['content'][0];
        }
        $cursor = ['type' => 'script'];

        // Precondition: the type check alone would pass this document.
        $this->assertSame([], TiptapDocument::disallowedTypes($deep));

        $this->postJson($this->notesUrl(), ['body' => 'x', 'body_doc' => $deep])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body_doc');

        $this->assertSame(0, Note::query()->count());
    }

    public function test_an_oversized_document_is_rejected(): void
    {
        // Legal node types, legal depth, but past the 512 KB byte ceiling.
        $paragraphs = [];
        for ($i = 0; $i < 4000; $i++) {
            $paragraphs[] = ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => str_repeat('a', 200)],
            ]];
        }
        $doc = ['type' => 'doc', 'content' => $paragraphs];

        $this->assertGreaterThan(TiptapDocument::MAX_BYTES, strlen((string) json_encode($doc)));

        $this->postJson($this->notesUrl(), ['body' => 'x', 'body_doc' => $doc])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body_doc');
    }

    public function test_the_character_limit_is_enforced_against_the_extracted_text(): void
    {
        // Comfortably under 512 KB, but its readable text exceeds 5000 characters. Measuring the
        // JSON blob instead of the projection would let this through.
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('a', 5001)]]],
        ]];

        $this->assertLessThan(TiptapDocument::MAX_BYTES, strlen((string) json_encode($doc)));
        $this->assertGreaterThan(5000, mb_strlen(TiptapDocument::extractText($doc)));

        $this->postJson($this->notesUrl(), ['body' => 'x', 'body_doc' => $doc])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body_doc');
    }

    public function test_a_document_at_the_character_limit_is_accepted(): void
    {
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => str_repeat('a', 5000)]]],
        ]];

        $this->postJson($this->notesUrl(), ['body' => 'x', 'body_doc' => $doc])->assertCreated();

        $this->assertSame(5000, mb_strlen(Note::query()->latest('id')->firstOrFail()->body));
    }

    public function test_a_malformed_document_is_rejected_not_stored(): void
    {
        // ACTUAL BEHAVIOUR: a shapeless root is caught by the `body_doc.type` rule
        // (`required_with` + `string` + `in:doc`), NOT by the whole-document checks in
        // withValidator() — those only run once the payload is a non-empty array.
        //
        // That split matters: `TiptapDocument::disallowedTypes()` returns an empty list for a node
        // with no/non-string `type` (it is skipped, not reported), so the field rule is the only
        // thing standing between a shapeless payload and the database.
        foreach ([
            'wrong root type' => ['type' => 'paragraph', 'content' => []],
            'no type at all' => ['content' => []],
            'type is not a string' => ['type' => 42],
        ] as $label => $doc) {
            $this->postJson($this->notesUrl(), ['body' => 'x', 'body_doc' => $doc])
                ->assertStatus(422, "expected rejection for: {$label}")
                ->assertJsonValidationErrors('body_doc.type');
        }

        $this->assertSame(0, Note::query()->count());
    }

    public function test_a_scalar_body_doc_is_rejected(): void
    {
        foreach (['a string', 42, true] as $value) {
            $this->postJson($this->notesUrl(), ['body' => 'x', 'body_doc' => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors('body_doc');
        }
    }

    public function test_a_body_doc_without_a_body_is_still_rejected(): void
    {
        // `body` stays required: the column is never null, and a plain-text client must be unaffected.
        $this->postJson($this->notesUrl(), ['body_doc' => $this->richDoc()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    // ── Whitespace inside the document ──────────────────────────────────────────────────────────
    //
    // These pin the `trimStrings(except: ['body_doc.*'])` exemption in bootstrap/app.php. They are
    // HTTP tests on purpose: the bug they cover lives entirely in global middleware, so anything
    // that calls the service or the request object directly passes whether the exemption is there
    // or not. Formatting a word mid-sentence is the ordinary case, not an edge case — it is what
    // splits one paragraph into several text nodes and hands TrimStrings the interior spaces.

    public function test_spaces_around_a_formatted_word_survive_the_round_trip(): void
    {
        // "Spoke to <b>Maria</b> today" — three text nodes, and the two that matter end and begin
        // with the spaces that hold the sentence together. Trimming them yields "Spoke toMariatoday".
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Spoke to '],
                ['type' => 'text', 'text' => 'Maria', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' today'],
            ]],
        ]];

        $this->postJson($this->notesUrl(), ['body' => 'ignored', 'body_doc' => $doc])
            ->assertCreated();

        $note = Note::query()->sole();

        // The derived body is the proof the document arrived intact: extractText() concatenates
        // adjacent text nodes with no separator, so it can only read correctly if the spaces are
        // still on the nodes themselves.
        $this->assertSame('Spoke to Maria today', $note->body);
        $this->assertSame('Spoke to ', $note->body_doc['content'][0]['content'][0]['text']);
        $this->assertSame(' today', $note->body_doc['content'][0]['content'][2]['text']);
    }

    public function test_an_edit_also_preserves_interior_spaces(): void
    {
        $note = Note::query()->forceCreate([
            'lead_id' => $this->lead->id,
            'user_id' => $this->bd->id,
            'body' => 'Original',
            'body_doc' => null,
            'org_id' => $this->lead->org_id,
        ]);

        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Quote sent '],
                ['type' => 'text', 'text' => 'yesterday', 'marks' => [['type' => 'italic']]],
                ['type' => 'text', 'text' => ' by email'],
            ]],
        ]];

        $this->patchJson("{$this->notesUrl()}/{$note->id}", ['body' => 'ignored', 'body_doc' => $doc])
            ->assertOk();

        $this->assertSame('Quote sent yesterday by email', $note->fresh()->body);
    }

    public function test_the_sibling_body_field_is_still_trimmed(): void
    {
        // The exemption is scoped to the document's interior and must not leak to `body`. A
        // plain-text client still gets the trimming it has always had.
        $this->postJson($this->notesUrl(), ['body' => '   padded on both sides   '])
            ->assertCreated();

        $this->assertSame('padded on both sides', Note::query()->sole()->body);
    }

    public function test_a_whitespace_only_body_is_still_rejected(): void
    {
        // The reason `body` must stay trimmed: `required` only rejects "   " once TrimStrings has
        // reduced it to "". Exempting `body` too would quietly admit blank notes.
        $this->postJson($this->notesUrl(), ['body' => '     '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }
}
