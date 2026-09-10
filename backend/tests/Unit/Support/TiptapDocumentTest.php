<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\TiptapDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The server-side Tiptap document contract, pinned as it ACTUALLY behaves today.
 *
 * This helper is the only thing standing between a client-supplied JSON document and the database,
 * so it is the security boundary for every rich-text note. It had no test coverage; these tests
 * describe the current implementation rather than an intended one, and two of them deliberately pin
 * behaviour that is surprising ({@see test_depth_reports_the_cutoff_not_the_true_depth} and
 * {@see test_a_disallowed_node_buried_below_the_depth_cutoff_is_NOT_reported}) so that a future
 * change to either is a visible, deliberate decision rather than a silent one.
 */
class TiptapDocumentTest extends TestCase
{
    /** A document nested `$depth` levels deep (the root counts as level 1). */
    private function nested(int $depth, string $type = 'paragraph'): array
    {
        $node = ['type' => $type];
        for ($i = 1; $i < $depth; $i++) {
            $node = ['type' => $type, 'content' => [$node]];
        }

        return $node;
    }

    private function doc(array ...$children): array
    {
        return ['type' => 'doc', 'content' => $children];
    }

    private function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    // ── Limits ──────────────────────────────────────────────────────────────────────────────────

    public function test_the_documented_limits_are_what_the_class_exposes(): void
    {
        // 512 KB and 50 levels. Callers read these constants rather than hardcoding, so a change
        // here is a change to every consumer's contract.
        $this->assertSame(512 * 1024, TiptapDocument::MAX_BYTES);
        $this->assertSame(524288, TiptapDocument::MAX_BYTES);
        $this->assertSame(50, TiptapDocument::MAX_DEPTH);
    }

    public function test_the_class_does_not_itself_enforce_the_byte_ceiling(): void
    {
        // MAX_BYTES is a constant this class publishes, NOT a rule it applies: the size check lives
        // in the caller (SaveNoteRequest::withValidator json_encodes and compares). A document far
        // over the ceiling therefore still walks cleanly here — enforcement is the caller's job,
        // and any new consumer must do the same or the ceiling silently stops existing.
        $huge = $this->doc($this->paragraph(str_repeat('a', TiptapDocument::MAX_BYTES + 1000)));

        $this->assertGreaterThan(TiptapDocument::MAX_BYTES, strlen((string) json_encode($huge)));
        $this->assertSame([], TiptapDocument::disallowedTypes($huge));
        $this->assertSame(3, TiptapDocument::depth($huge));
    }

    // ── empty() ─────────────────────────────────────────────────────────────────────────────────

    public function test_empty_is_one_empty_paragraph_not_an_empty_doc(): void
    {
        // A childless doc renders as literally nothing in ProseMirror — no caret line, nothing for
        // the placeholder to attach to. The single paragraph is the fix, and is load-bearing.
        $this->assertSame(['type' => 'doc', 'content' => [['type' => 'paragraph']]], TiptapDocument::empty());
        $this->assertSame([], TiptapDocument::disallowedTypes(TiptapDocument::empty()));
        $this->assertSame('', TiptapDocument::extractText(TiptapDocument::empty()));
    }

    // ── Allowed / disallowed nodes ──────────────────────────────────────────────────────────────

    /** Every node type the enabled extensions can produce must survive validation. */
    #[DataProvider('allowedNodeProvider')]
    public function test_an_allowed_node_type_is_not_reported(string $type): void
    {
        $this->assertSame([], TiptapDocument::disallowedTypes($this->doc(['type' => $type])));
    }

    public static function allowedNodeProvider(): array
    {
        return array_map(fn (string $t): array => [$t], TiptapDocument::ALLOWED_NODES);
    }

    public function test_the_allowed_node_list_is_exactly_the_thirteen_we_expect(): void
    {
        // Pinned as a set: an addition here widens what can be stored, so it should never happen
        // by accident. Keep in sync with the frontend editor configuration.
        $this->assertSame([
            'doc', 'paragraph', 'text', 'heading', 'bulletList', 'orderedList', 'listItem',
            'taskList', 'taskItem', 'blockquote', 'codeBlock', 'horizontalRule', 'hardBreak',
        ], TiptapDocument::ALLOWED_NODES);
    }

    #[DataProvider('disallowedNodeProvider')]
    public function test_a_disallowed_node_type_is_reported(string $type): void
    {
        $this->assertSame([$type], TiptapDocument::disallowedTypes($this->doc(['type' => $type])));
    }

    public static function disallowedNodeProvider(): array
    {
        // `image`/`table` are plausible-but-unconfigured; `script`/`iframe` are the ones that would
        // matter if some future renderer ever turned a node into markup.
        return [['script'], ['iframe'], ['image'], ['table'], ['object'], ['embed'], ['html']];
    }

    public function test_disallowed_types_are_deduplicated(): void
    {
        $doc = $this->doc(['type' => 'script'], ['type' => 'script'], ['type' => 'iframe']);

        $found = TiptapDocument::disallowedTypes($doc);

        sort($found);
        $this->assertSame(['iframe', 'script'], $found);
    }

    public function test_a_disallowed_node_nested_inside_allowed_ones_is_still_found(): void
    {
        $doc = $this->doc([
            'type' => 'blockquote',
            'content' => [['type' => 'bulletList', 'content' => [['type' => 'script']]]],
        ]);

        $this->assertSame(['script'], TiptapDocument::disallowedTypes($doc));
    }

    // ── Allowed / disallowed marks ──────────────────────────────────────────────────────────────

    #[DataProvider('allowedMarkProvider')]
    public function test_an_allowed_mark_is_not_reported(string $mark): void
    {
        $doc = $this->doc(['type' => 'text', 'text' => 'x', 'marks' => [['type' => $mark]]]);

        $this->assertSame([], TiptapDocument::disallowedTypes($doc));
    }

    public static function allowedMarkProvider(): array
    {
        return array_map(fn (string $m): array => [$m], TiptapDocument::ALLOWED_MARKS);
    }

    public function test_the_allowed_mark_list_is_exactly_the_seven_we_expect(): void
    {
        $this->assertSame(
            ['bold', 'italic', 'strike', 'code', 'underline', 'highlight', 'link'],
            TiptapDocument::ALLOWED_MARKS,
        );
    }

    public function test_a_disallowed_mark_is_reported(): void
    {
        $doc = $this->doc(['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'onclick']]]);

        $this->assertSame(['onclick'], TiptapDocument::disallowedTypes($doc));
    }

    public function test_node_and_mark_violations_are_reported_together(): void
    {
        $doc = $this->doc(
            ['type' => 'script'],
            ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'onclick']]],
        );

        $found = TiptapDocument::disallowedTypes($doc);

        sort($found);
        $this->assertSame(['onclick', 'script'], $found);
    }

    public function test_a_marks_key_that_is_not_a_list_of_arrays_is_ignored_not_fatal(): void
    {
        // marksOf() filters to arrays, so a scalar or string in `marks` is skipped rather than
        // throwing. Malformed input must never 500 the request.
        $doc = $this->doc(['type' => 'text', 'text' => 'x', 'marks' => ['bold', 42, null]]);

        $this->assertSame([], TiptapDocument::disallowedTypes($doc));

        $stringMarks = $this->doc(['type' => 'text', 'text' => 'x', 'marks' => 'bold']);
        $this->assertSame([], TiptapDocument::disallowedTypes($stringMarks));
    }

    // ── Depth ───────────────────────────────────────────────────────────────────────────────────

    public function test_depth_counts_the_root_as_one(): void
    {
        $this->assertSame(1, TiptapDocument::depth(['type' => 'doc']));
        $this->assertSame(1, TiptapDocument::depth(['type' => 'doc', 'content' => []]));
    }

    #[DataProvider('depthProvider')]
    public function test_depth_of_a_nested_document(int $levels, int $expected): void
    {
        $this->assertSame($expected, TiptapDocument::depth($this->nested($levels)));
    }

    public static function depthProvider(): array
    {
        return [
            'two' => [2, 2],
            'five' => [5, 5],
            'at the ceiling' => [50, 50],
        ];
    }

    public function test_depth_reports_the_cutoff_not_the_true_depth(): void
    {
        // ⚠️ ACTUAL BEHAVIOUR, pinned deliberately. walk() invokes the callback and only THEN bails
        // when depth > MAX_DEPTH, so the deepest level ever observed is MAX_DEPTH + 1 = 51. A
        // 60-level and a 1000-level document both report 51.
        //
        // This is sufficient for validation — 51 > 50 rejects — but `depth()` must NOT be read as a
        // true measurement, and a caller comparing with `>=` or reporting the figure to a user would
        // be wrong. The recursion guard is what this buys: a pathological document cannot blow the
        // stack.
        $this->assertSame(51, TiptapDocument::depth($this->nested(60)));
        $this->assertSame(51, TiptapDocument::depth($this->nested(1000)));

        // The consequence that matters: anything over the ceiling still fails a `> MAX_DEPTH` check.
        $this->assertGreaterThan(TiptapDocument::MAX_DEPTH, TiptapDocument::depth($this->nested(1000)));
    }

    public function test_a_pathologically_deep_document_does_not_exhaust_the_stack(): void
    {
        // 5000 levels: the point of the bail-out. Without it this recurses to death.
        $this->assertSame(51, TiptapDocument::depth($this->nested(5000)));
        $this->assertSame('', TiptapDocument::extractText($this->nested(5000, 'paragraph')));
    }

    public function test_a_disallowed_node_buried_below_the_depth_cutoff_is_NOT_reported(): void
    {
        // ⚠️ ACTUAL BEHAVIOUR — a genuine blind spot, pinned so it cannot regress unnoticed.
        //
        // disallowedTypes() shares walk(), so it stops descending past MAX_DEPTH too. A `script`
        // node at depth 55 is therefore invisible to it and comes back as an EMPTY violation list.
        //
        // Nothing is currently exploitable, because SaveNoteRequest::withValidator checks depth
        // BEFORE node types and `return`s on failure — such a document is rejected for being too
        // deep before its contents are ever inspected. That ORDER is load-bearing security, not
        // stylistic: any new consumer that checks types without first rejecting on depth would
        // accept a document containing arbitrary node types.
        $deep = $this->nested(55);
        $cursor = &$deep;
        while (isset($cursor['content'])) {
            $cursor = &$cursor['content'][0];
        }
        $cursor = ['type' => 'script'];

        $this->assertSame([], TiptapDocument::disallowedTypes($deep));
        // …and this is the check that must run first.
        $this->assertGreaterThan(TiptapDocument::MAX_DEPTH, TiptapDocument::depth($deep));
    }

    // ── extractText ─────────────────────────────────────────────────────────────────────────────

    public function test_text_is_extracted_from_a_single_paragraph(): void
    {
        $this->assertSame('Hello', TiptapDocument::extractText($this->doc($this->paragraph('Hello'))));
    }

    public function test_block_siblings_are_separated_by_a_single_newline(): void
    {
        $doc = $this->doc($this->paragraph('Hello'), $this->paragraph('World'));

        $this->assertSame("Hello\nWorld", TiptapDocument::extractText($doc));
    }

    public function test_a_heading_reads_as_a_line_of_text(): void
    {
        $doc = $this->doc(
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'H']]],
            $this->paragraph('P'),
        );

        $this->assertSame("H\nP", TiptapDocument::extractText($doc));
    }

    public function test_list_items_are_separated_by_a_blank_line(): void
    {
        // ACTUAL BEHAVIOUR: `listItem` AND its inner `paragraph` each emit a newline, and
        // `bulletList` emits none, so items come out double-spaced. Worth knowing before this
        // projection is shown to a user or fed to a search index.
        $doc = $this->doc(['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [$this->paragraph('A')]],
            ['type' => 'listItem', 'content' => [$this->paragraph('B')]],
        ]]);

        $this->assertSame("A\n\nB", TiptapDocument::extractText($doc));
    }

    public function test_runs_of_three_or_more_newlines_collapse_to_two(): void
    {
        // Nested blocks each emit their own newline, so a blockquote>blockquote>paragraph run
        // produces three in a row. The collapse is only OBSERVABLE mid-document — see the sibling
        // test below for what happens when the run leads the string instead.
        $doc = $this->doc(
            $this->paragraph('before'),
            ['type' => 'blockquote', 'content' => [['type' => 'blockquote', 'content' => [$this->paragraph('deep')]]]],
            $this->paragraph('after'),
        );

        $text = TiptapDocument::extractText($doc);

        $this->assertStringNotContainsString("\n\n\n", $text);
        $this->assertSame("before\n\ndeep\nafter", $text);
    }

    public function test_a_leading_run_of_newlines_is_collapsed_and_then_trimmed_away_entirely(): void
    {
        // ACTUAL BEHAVIOUR. The same three-newline run at the START of the document collapses to two
        // and is then removed by trim(), so the nesting leaves no trace at all in the projection —
        // "deep\nafter", not "deep\n\nafter".
        //
        // Pinned because it is the counter-intuitive half of the pair: identical markup yields a
        // different separator depending only on whether something precedes it.
        $doc = $this->doc(
            ['type' => 'blockquote', 'content' => [['type' => 'blockquote', 'content' => [$this->paragraph('deep')]]]],
            $this->paragraph('after'),
        );

        $this->assertSame("deep\nafter", TiptapDocument::extractText($doc));
    }

    public function test_leading_and_trailing_whitespace_is_trimmed(): void
    {
        $text = TiptapDocument::extractText($this->doc($this->paragraph('x')));

        $this->assertSame('x', $text);
        $this->assertSame($text, trim($text));
    }

    public function test_a_document_with_no_text_nodes_extracts_to_an_empty_string(): void
    {
        $this->assertSame('', TiptapDocument::extractText($this->doc(['type' => 'horizontalRule'])));
        $this->assertSame('', TiptapDocument::extractText(['type' => 'doc']));
    }

    public function test_marks_do_not_affect_the_extracted_text(): void
    {
        // The projection is the plain reading of the note: formatting contributes nothing.
        $bold = $this->doc(['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Bold', 'marks' => [['type' => 'bold'], ['type' => 'underline']]],
        ]]);

        $this->assertSame('Bold', TiptapDocument::extractText($bold));
    }

    public function test_adjacent_text_nodes_in_one_paragraph_are_concatenated_without_a_separator(): void
    {
        $doc = $this->doc(['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Hello '],
            ['type' => 'text', 'text' => 'world', 'marks' => [['type' => 'bold']]],
        ]]);

        $this->assertSame('Hello world', TiptapDocument::extractText($doc));
    }

    // ── Malformed input ─────────────────────────────────────────────────────────────────────────

    public function test_a_node_without_a_type_key_is_skipped_rather_than_reported(): void
    {
        // ACTUAL BEHAVIOUR: a missing/non-string `type` yields null and is neither reported as
        // disallowed nor treated as text. Shapeless input passes disallowedTypes() — which is why
        // the caller also asserts `content.type === 'doc'` via a validation rule.
        $this->assertSame([], TiptapDocument::disallowedTypes(['content' => [['foo' => 'bar']]]));
        $this->assertSame([], TiptapDocument::disallowedTypes($this->doc(['type' => 42])));
        $this->assertSame([], TiptapDocument::disallowedTypes($this->doc(['type' => null])));
    }

    public function test_a_content_key_that_is_not_an_array_terminates_the_walk_safely(): void
    {
        foreach (['not-an-array', 42, null, true] as $content) {
            $doc = ['type' => 'doc', 'content' => $content];

            $this->assertSame([], TiptapDocument::disallowedTypes($doc));
            $this->assertSame(1, TiptapDocument::depth($doc));
            $this->assertSame('', TiptapDocument::extractText($doc));
        }
    }

    public function test_non_array_children_are_skipped(): void
    {
        $doc = ['type' => 'doc', 'content' => ['string', 42, null, ['type' => 'script']]];

        $this->assertSame(['script'], TiptapDocument::disallowedTypes($doc));
    }

    public function test_a_text_node_whose_text_is_not_a_string_contributes_nothing(): void
    {
        $doc = $this->doc(['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 42],
            ['type' => 'text'],
            ['type' => 'text', 'text' => 'real'],
        ]]);

        $this->assertSame('real', TiptapDocument::extractText($doc));
    }

    public function test_an_empty_array_is_handled_by_every_entry_point(): void
    {
        $this->assertSame([], TiptapDocument::disallowedTypes([]));
        $this->assertSame(1, TiptapDocument::depth([]));
        $this->assertSame('', TiptapDocument::extractText([]));
    }
}
