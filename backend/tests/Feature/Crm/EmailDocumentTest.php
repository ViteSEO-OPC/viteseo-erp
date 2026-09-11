<?php

namespace Tests\Feature\Crm;

use App\Modules\Crm\Services\Mail\EmailDocument;
use App\Support\TiptapDocument;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The email-safe document: what a formatted reply may contain, and the only HTML the server writes.
 *
 * Pure — no database, no HTTP. The send path is covered by EmailRichTextSendTest; this pins the
 * validator and the renderer themselves, including every way a hand-written payload could try to get
 * markup, script or an unapproved attribute into a customer's inbox.
 */
class EmailDocumentTest extends TestCase
{
    /** @param  list<array<string, mixed>>  $content */
    private function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /** @param  list<array<string, mixed>>  $marks */
    private function text(string $text, array $marks = []): array
    {
        return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    private function para(array ...$inline): array
    {
        return $inline === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $inline];
    }

    private function item(array ...$blocks): array
    {
        return ['type' => 'listItem', 'content' => $blocks];
    }

    /** Everything the email subset allows, in one document. */
    private function everything(): array
    {
        return $this->doc([
            $this->para($this->text('Hello '), $this->text('Maria', [['type' => 'bold']]), $this->text(',')),
            $this->para(),
            $this->para(
                $this->text('italic', [['type' => 'italic']]),
                $this->text(' '),
                $this->text('under', [['type' => 'underline']]),
                $this->text(' '),
                $this->text('gone', [['type' => 'strike']]),
            ),
            ['type' => 'bulletList', 'content' => [
                $this->item($this->para($this->text('One'))),
                $this->item(
                    $this->para($this->text('Two')),
                    ['type' => 'orderedList', 'attrs' => ['start' => 1], 'content' => [
                        $this->item($this->para($this->text('Nested'))),
                    ]],
                ),
            ]],
            ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [
                $this->item($this->para($this->text('Third'))),
                $this->item($this->para($this->text('Fourth'))),
            ]],
        ]);
    }

    // ── Accepts exactly the email subset ─────────────────────────────────────────────────────

    public function test_every_allowed_construct_is_accepted(): void
    {
        $this->assertNull(EmailDocument::problem($this->everything()));
    }

    public function test_the_html_is_exactly_the_allowlisted_rendering(): void
    {
        $html = EmailDocument::toHtml($this->doc([
            $this->para($this->text('Hi '), $this->text('there', [['type' => 'italic'], ['type' => 'bold']])),
            ['type' => 'bulletList', 'content' => [$this->item($this->para($this->text('A')))]],
        ]));

        // Marks nest in ONE canonical order whatever order the client sent them in.
        $this->assertSame(
            '<div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5;">'
            .'<p style="margin: 0;">Hi <strong><em>there</em></strong></p>'
            .'<ul style="margin: 0; padding-left: 24px;"><li><p style="margin: 0;">A</p></li></ul>'
            .'</div>',
            $html,
        );
    }

    public function test_the_html_contains_only_allowlisted_tags_and_attributes(): void
    {
        $html = EmailDocument::toHtml($this->everything());

        preg_match_all('#</?([a-zA-Z0-9]+)([^>]*)>#', $html, $tags, PREG_SET_ORDER);
        $this->assertNotEmpty($tags);

        foreach ($tags as [$whole, $name, $attributes]) {
            $this->assertContains(strtolower($name), ['div', 'p', 'br', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li'], "Unexpected tag {$whole}");
            preg_match_all('/([a-zA-Z-]+)\s*=/', $attributes, $names);
            foreach ($names[1] as $attribute) {
                $this->assertContains(strtolower($attribute), ['style', 'start'], "Unexpected attribute in {$whole}");
            }
        }
    }

    public function test_the_plain_text_is_a_readable_projection(): void
    {
        $this->assertSame(
            "Hello Maria,\n\nitalic under gone\n• One\n• Two\n  1. Nested\n3. Third\n4. Fourth",
            EmailDocument::toText($this->everything()),
        );
    }

    public function test_an_ordered_lists_start_is_honoured(): void
    {
        $html = EmailDocument::toHtml($this->doc([
            ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [$this->item($this->para($this->text('x')))]],
        ]));

        $this->assertStringContainsString('<ol start="3" style=', $html);
    }

    // ── Escaping: text is text, never markup ─────────────────────────────────────────────────

    public function test_text_is_escaped(): void
    {
        $html = EmailDocument::toHtml($this->doc([
            $this->para($this->text('Tom & Jerry <b>"quoted"</b> \'single\'')),
        ]));

        $this->assertStringContainsString('Tom &amp; Jerry &lt;b&gt;&quot;quoted&quot;&lt;/b&gt; &apos;single&apos;', $html);
    }

    public function test_script_and_event_handler_payloads_in_text_cannot_become_markup(): void
    {
        $payload = '<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a>';
        $html = EmailDocument::toHtml($this->doc([$this->para($this->text($payload, [['type' => 'bold']]))]));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // The words survive as visible TEXT inside an allowlisted tag, which is what they are.
        $this->assertStringContainsString('<strong>&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_an_attribute_injection_through_the_list_start_is_ignored(): void
    {
        foreach (['2" onclick="alert(1)', '5', 2.5, -1, 999999999, null, ['nested']] as $start) {
            $html = EmailDocument::toHtml($this->doc([
                ['type' => 'orderedList', 'attrs' => ['start' => $start], 'content' => [$this->item($this->para($this->text('x')))]],
            ]));

            $this->assertStringNotContainsString('onclick', $html);
            $this->assertStringContainsString('<ol style=', $html, 'Anything but a sane integer is ignored, not rendered.');
        }
    }

    public function test_attributes_on_other_nodes_are_never_rendered(): void
    {
        $html = EmailDocument::toHtml($this->doc([
            ['type' => 'paragraph', 'attrs' => ['style' => 'background:url(javascript:x)', 'onclick' => 'x'], 'content' => [$this->text('x')]],
        ]));

        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    // ── Rejects everything else ──────────────────────────────────────────────────────────────

    /** @return array<string, array{array<string, mixed>}> */
    public static function unsupportedNodes(): array
    {
        $cases = [];
        foreach (['heading', 'blockquote', 'codeBlock', 'horizontalRule', 'hardBreak', 'taskList', 'taskItem', 'image', 'script', 'iframe'] as $type) {
            $cases[$type] = [['type' => 'doc', 'content' => [['type' => $type, 'content' => [['type' => 'text', 'text' => 'x']]]]]];
        }
        // A node Internal Notes allows is still refused here, even inside an otherwise valid paragraph.
        $cases['hardBreak inline'] = [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'hardBreak']]]]]];

        return $cases;
    }

    #[DataProvider('unsupportedNodes')]
    public function test_nodes_outside_the_email_subset_are_rejected(array $doc): void
    {
        $problem = EmailDocument::problem($doc);

        $this->assertNotNull($problem);
    }

    /** @return array<string, array{string}> */
    public static function unsupportedMarks(): array
    {
        return ['link' => ['link'], 'highlight' => ['highlight'], 'code' => ['code'], 'textStyle' => ['textStyle'], 'script' => ['script']];
    }

    #[DataProvider('unsupportedMarks')]
    public function test_marks_outside_the_email_subset_are_rejected(string $mark): void
    {
        $doc = $this->doc([$this->para($this->text('x', [['type' => $mark, 'attrs' => ['href' => 'javascript:alert(1)']]]))]);

        $this->assertStringContainsString('does not support', (string) EmailDocument::problem($doc));
    }

    /** @return array<string, array{mixed}> */
    public static function malformedDocuments(): array
    {
        $p = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]];

        return [
            'not an array' => ['<p>hi</p>'],
            'root is not a doc' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]]],
            'empty doc' => [['type' => 'doc', 'content' => []]],
            'missing type' => [['type' => 'doc', 'content' => [['content' => []]]]],
            'non-string type' => [['type' => 'doc', 'content' => [['type' => ['paragraph']]]]],
            'text without text' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text']]]]]],
            'empty text' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]]]],
            'text with children' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'content' => [$p]]]]]]],
            'text directly in doc' => [['type' => 'doc', 'content' => [['type' => 'text', 'text' => 'x']]]],
            'content is an object' => [['type' => 'doc', 'content' => ['a' => $p]]],
            'child is a string' => [['type' => 'doc', 'content' => ['<b>x</b>']]],
            'list holding a paragraph' => [['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [$p]]]]],
            'paragraph holding a list' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$p]]]]]]]]],
            'empty list' => [['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => []]]]],
            'list item not opening with a paragraph' => [['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$p]]]]]]]]]]],
            'nested doc' => [['type' => 'doc', 'content' => [['type' => 'doc', 'content' => [$p]]]]],
            'marks on a paragraph' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'marks' => [['type' => 'bold']], 'content' => [['type' => 'text', 'text' => 'x']]]]]],
            'marks not a list' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => ['type' => 'bold']]]]]]],
            'mark without a type' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['attrs' => []]]]]]]]],
        ];
    }

    #[DataProvider('malformedDocuments')]
    public function test_malformed_documents_are_rejected(mixed $doc): void
    {
        $this->assertNotNull(EmailDocument::problem($doc));
    }

    // ── Size and depth limits, in the security-required order ────────────────────────────────

    public function test_a_document_over_the_byte_limit_is_rejected(): void
    {
        $doc = $this->doc([$this->para($this->text(str_repeat('a', TiptapDocument::MAX_BYTES + 1)))]);

        $this->assertSame(EmailDocument::TOO_LARGE, EmailDocument::problem($doc));
    }

    public function test_a_document_nested_past_the_depth_limit_is_rejected(): void
    {
        $this->assertSame(EmailDocument::TOO_DEEP, EmailDocument::problem($this->deepList(TiptapDocument::MAX_DEPTH)));
    }

    public function test_a_disallowed_node_buried_below_the_depth_cutoff_is_still_rejected(): void
    {
        // The shared walk stops descending at MAX_DEPTH, so a type check alone would never see this
        // node. Depth is refused first, which is the only reason this document cannot slip through.
        $doc = $this->deepList(TiptapDocument::MAX_DEPTH, ['type' => 'script', 'content' => [['type' => 'text', 'text' => 'x']]]);

        $this->assertNotNull(EmailDocument::problem($doc));
    }

    public function test_rendering_an_invalid_document_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailDocument::toHtml(['type' => 'doc', 'content' => [['type' => 'heading', 'content' => [['type' => 'text', 'text' => 'x']]]]]);
    }

    public function test_extracting_text_from_an_invalid_document_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailDocument::toText(['type' => 'doc', 'content' => []]);
    }

    /** Nested bullet lists, `$levels` deep, optionally with `$innermost` at the bottom. */
    private function deepList(int $levels, ?array $innermost = null): array
    {
        $node = $innermost ?? $this->para($this->text('bottom'));
        for ($i = 0; $i < $levels; $i++) {
            $node = ['type' => 'bulletList', 'content' => [$this->item($this->para($this->text('level')), $node)]];
        }

        return $this->doc([$node]);
    }
}
