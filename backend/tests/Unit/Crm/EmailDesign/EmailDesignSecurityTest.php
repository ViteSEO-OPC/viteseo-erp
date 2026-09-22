<?php

declare(strict_types=1);

namespace Tests\Unit\Crm\EmailDesign;

use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use App\Modules\Crm\Support\EmailDesign\EmailDesignSchema;
use App\Modules\Crm\Support\EmailDesign\EmailDesignValidator;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every way a hand-written design or template content, or a hostile lead record, could try to get markup,
 * script, an unsafe link, an unapproved image or unbounded work into a customer's inbox — and the proof it
 * cannot.
 *
 * Validator cases pin the exact rejection. Renderer cases parse the output and check it against an
 * element/attribute allowlist, so an injection is caught however it is spelled.
 */
class EmailDesignSecurityTest extends TestCase
{
    use BuildsEmailDesigns;

    private const ELEMENTS = ['html', 'head', 'meta', 'title', 'body', 'div', 'table', 'tr', 'td', 'h1', 'h2', 'h3', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'u', 's', 'a', 'img', 'br'];

    private const ATTRIBUTES = ['lang', 'charset', 'name', 'content', 'http-equiv', 'style', 'role', 'width', 'height', 'cellpadding', 'cellspacing', 'border', 'bgcolor', 'align', 'href', 'target', 'rel', 'src', 'alt', 'start'];

    private const SCRIPT = '<script>alert(1)</script>';

    private const BREAKOUT = '"><img src=x onerror=alert(1)>';

    private const QUOTE_BREAKOUT = "' onmouseover='alert(1)";

    private function validator(): EmailDesignValidator
    {
        return new EmailDesignValidator;
    }

    private function renderer(): EmailDesignRenderer
    {
        return new EmailDesignRenderer;
    }

    private function assertLayoutProblem(string $path, string $message, mixed $layout): void
    {
        $this->assertSame(['path' => $path, 'message' => $message], $this->validator()->layoutProblem($layout));
    }

    private function assertContentProblem(string $path, string $message, mixed $content): void
    {
        $this->assertSame(['path' => $path, 'message' => $message], $this->validator()->contentProblem($content));
    }

    /**
     * Parse the HTML and hold every element and attribute to the allowlist; every href must be https,
     * mailto or tel, every image one the context resolved, and no style may load or execute anything.
     */
    private function assertSafeHtml(string $html): void
    {
        foreach (['<script', '<style', '<iframe', '<object', '<embed', '<form', '<input', '<link', '<svg', '<math', '<base', 'javascript:'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $html);
        }

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET));

        foreach ($dom->getElementsByTagName('*') as $element) {
            /** @var DOMElement $element */
            $this->assertContains($element->nodeName, self::ELEMENTS, 'Unexpected element <'.$element->nodeName.'>');

            foreach ($element->attributes as $attribute) {
                $this->assertContains($attribute->name, self::ATTRIBUTES, 'Unexpected attribute '.$attribute->name.' on <'.$element->nodeName.'>');

                match ($attribute->name) {
                    'href' => $this->assertMatchesRegularExpression('/^(https:\/\/|mailto:|tel:)/', $attribute->value),
                    'src' => $this->assertContains($attribute->value, [self::ASSET_URL, self::LOGO_URL]),
                    'style' => $this->assertDoesNotMatchRegularExpression('/url\s*\(|expression|javascript|@import|behavior|-moz-binding/i', $attribute->value),
                    default => null,
                };
            }
        }
    }

    // ── Script injection ─────────────────────────────────────────────────────────────────────

    public function test_script_in_every_text_field_and_token_is_rendered_as_inert_text(): void
    {
        $layout = $this->layout(
            [$this->logo()],
            [$this->footer(['content' => $this->doc($this->para($this->text(self::SCRIPT)))])],
            signature: ['sign_off' => self::SCRIPT],
        );
        $content = $this->content([
            $this->heading(['text' => self::SCRIPT.' {{first_name}}']),
            $this->textBlock(['content' => $this->doc(
                $this->para($this->text(self::SCRIPT), $this->text(self::SCRIPT, [['type' => 'bold'], $this->link('https://viteseo.ph')])),
                $this->bullets($this->item($this->para($this->text(self::SCRIPT)))),
            )]),
            $this->button(['label' => self::SCRIPT]),
            $this->image(['alt' => self::SCRIPT]),
        ]);
        $context = $this->context(
            ['first_name' => self::SCRIPT, 'sender_name' => self::SCRIPT, 'sender_title' => self::SCRIPT, 'sender_phone' => self::SCRIPT],
            logoAlt: self::SCRIPT,
        );

        $this->assertNull($this->validator()->layoutProblem($layout));
        $this->assertNull($this->validator()->contentProblem($content));

        $html = $this->renderer()->toHtml($layout, $content, $context);
        $this->assertSafeHtml($html);
        // 13 plantings: heading text and its token, three in the text block, label, image alt, sign-off, the
        // three sender tokens, footer and logo alt — every one present, every one escaped.
        $this->assertSame(13, substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));

        // The text/plain part is not HTML: the characters are simply text there.
        $this->assertStringContainsString(self::SCRIPT, $this->renderer()->toText($layout, $content, $context));
    }

    public function test_script_in_the_preheader_is_rendered_as_inert_hidden_text(): void
    {
        $content = $this->content(extra: ['preheader' => self::SCRIPT.' {{first_name}}']);

        $this->assertNull($this->validator()->contentProblem($content));

        $html = $this->renderer()->toHtml($this->layout(), $content, $this->context(['first_name' => self::BREAKOUT]));
        $this->assertSafeHtml($html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_markup_closing_the_surrounding_table_cannot_escape_its_cell(): void
    {
        $payload = '</td></tr></table><iframe src="https://evil.example"></iframe><table><tr><td>';
        $content = $this->content([$this->heading(['text' => $payload]), $this->textBlock(['content' => $this->doc($this->para($this->text($payload)))])]);

        $html = $this->renderer()->toHtml($this->layout(), $content, $this->context(['business_name' => $payload]));

        $this->assertSafeHtml($html);
        $this->assertStringContainsString('&lt;/td&gt;&lt;/tr&gt;&lt;/table&gt;&lt;iframe', $html);
    }

    // ── Attribute injection ──────────────────────────────────────────────────────────────────

    public function test_quotes_cannot_break_out_of_an_attribute(): void
    {
        $content = $this->content([
            $this->image(['alt' => self::BREAKOUT]),
            $this->image(['id' => 'image-2', 'alt' => self::QUOTE_BREAKOUT]),
            $this->button(['label' => self::BREAKOUT.' {{business_name}}']),
        ]);

        $html = $this->renderer()->toHtml($this->layout([$this->logo()], []), $content, $this->context(['business_name' => self::QUOTE_BREAKOUT], logoAlt: self::BREAKOUT));

        $this->assertSafeHtml($html);
        $this->assertStringContainsString('alt="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;"', $html);
        $this->assertStringContainsString('alt="&#039; onmouseover=&#039;alert(1)"', $html);
    }

    /** @return array<string, array{string, string}> */
    public static function attributeBreakingValues(): array
    {
        return [
            'color closing the style' => ['color', '#123456;background:url(https://evil.example/x.png)'],
            'color closing the attribute' => ['color', '#123456" onload="alert(1)'],
            'alignment closing the attribute' => ['align', 'left" onmouseover="alert(1)'],
            'alignment injecting css' => ['align', 'left;position:fixed'],
        ];
    }

    #[DataProvider('attributeBreakingValues')]
    public function test_values_written_into_style_or_attributes_cannot_carry_extra_markup(string $field, string $value): void
    {
        $this->assertNotNull($this->problemFor($this->heading([$field => $value])));
    }

    public function test_the_renderer_refuses_to_render_documents_that_do_not_validate(): void
    {
        $badContent = $this->content([$this->heading(['color' => '#123456" onload="alert(1)'])]);
        $badLayout = $this->layout(styles: ['text_color' => '#123456" onload="alert(1)']);

        foreach ([[$this->layout(), $badContent], [$badLayout, $this->content()]] as [$layout, $content]) {
            foreach (['toHtml', 'toText'] as $method) {
                try {
                    $this->renderer()->{$method}($layout, $content, $this->context());
                    $this->fail($method.' rendered an invalid document');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    // ── Malicious URLs ───────────────────────────────────────────────────────────────────────

    /**
     * Every field that holds a URL, as [region, block, path to the URL].
     *
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    private function urlFields(string $url): array
    {
        $linked = $this->doc($this->para($this->text('x', [$this->link($url)])));

        return [
            'button url' => [EmailDesignSchema::CONTENT, $this->button(['url' => $url]), 'blocks.0.url'],
            'image link' => [EmailDesignSchema::CONTENT, $this->image(['link_url' => $url]), 'blocks.0.link_url'],
            'logo link' => [EmailDesignSchema::HEADER, $this->logo(['link_url' => $url]), 'header.0.link_url'],
            'text link' => [EmailDesignSchema::CONTENT, $this->textBlock(['content' => $linked]), 'blocks.0.content.content.0.content.0.marks.0.attrs.href'],
            'footer link' => [EmailDesignSchema::FOOTER, $this->footer(['content' => $linked]), 'footer.0.content.content.0.content.0.marks.0.attrs.href'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function maliciousUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'mixed-case javascript' => ['JaVaScRiPt:alert(1)'],
            'javascript after a space' => [' javascript:alert(1)'],
            'javascript split by a tab' => ["java\tscript:alert(1)"],
            'entity-encoded javascript' => ['&#106;avascript:alert(1)'],
            'percent-encoded javascript' => ['%6Aavascript:alert(1)'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'data html' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'data image' => ['data:image/svg+xml,<svg onload=alert(1)>'],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://example.com/file'],
            'plain http' => ['http://example.com'],
            'protocol-relative' => ['//evil.example.com'],
            'relative path' => ['/zerem/settings'],
            'upper-case scheme' => ['HTTPS://example.com'],
            'https without a host' => ['https://'],
            'https with an empty authority' => ['https:///evil.example.com'],
            'backslash authority' => ['https:\\\\evil.example.com'],
            'credentials' => ['https://user:pass@example.com'],
            'userinfo spoof' => ['https://viteseo.ph@evil.example.com'],
            'single-label host' => ['https://localhost'],
            'unicode host' => ['https://exämple.com'],
            'double-quote breakout' => ['https://example.com/"onmouseover="alert(1)'],
            'single-quote breakout' => ["https://example.com/'onmouseover='alert(1)"],
            'angle brackets' => ['https://example.com/<script>alert(1)</script>'],
            'backtick' => ['https://example.com/`x`'],
            'space' => ['https://exa mple.com'],
            'trailing newline' => ["https://example.com\n"],
            'header injection' => ["https://example.com/\r\nSet-Cookie:x=y"],
            'too long' => ['https://example.com/'.str_repeat('a', 2000)],
            'empty' => [''],
            'mailto without an address' => ['mailto:'],
            'mailto that is not an address' => ['mailto:not-an-email'],
            'mailto adding a recipient' => ['mailto:a@example.com?bcc=evil@example.com'],
            'mailto with an encoded header' => ['mailto:a@example.com%0D%0ABcc:b@example.com'],
            'tel with words' => ['tel:call-me'],
            'empty tel' => ['tel:'],
            'tel with markup' => ['tel:+63<script>'],
        ];
    }

    #[DataProvider('maliciousUrls')]
    public function test_malicious_urls_are_rejected_in_every_url_field(string $url): void
    {
        foreach ($this->urlFields($url) as $field => [$region, $block, $path]) {
            $this->assertSame(['path' => $path, 'message' => EmailDesignValidator::UNSAFE_URL], $this->problemIn($region, $block), $field);
        }
    }

    public function test_a_url_that_is_not_a_string_is_rejected(): void
    {
        $this->assertContentProblem('blocks.0.url', EmailDesignValidator::UNSAFE_URL, $this->content([$this->button(['url' => null])]));
        $this->assertContentProblem('blocks.0.url', EmailDesignValidator::UNSAFE_URL, $this->content([$this->button(['url' => ['https://viteseo.ph']])]));
        $this->assertContentProblem('blocks.0.link_url', EmailDesignValidator::UNSAFE_URL, $this->content([$this->image(['link_url' => 42])]));
    }

    /** @return array<string, array{string}> */
    public static function safeUrls(): array
    {
        return [
            'https' => ['https://viteseo.ph'],
            'https with path, query and fragment' => ['https://system.viteseo.ph/book?ref=email&x=1#top'],
            'https with a port' => ['https://example.com:8443/path'],
            'mailto' => ['mailto:hello@viteseo.ph'],
            'mailto with a subject' => ['mailto:hello@viteseo.ph?subject=Hello%20there'],
            'international tel' => ['tel:+639171234567'],
            'local tel' => ['tel:02-8123-4567'],
        ];
    }

    #[DataProvider('safeUrls')]
    public function test_https_mailto_and_tel_urls_are_accepted_in_every_url_field(string $url): void
    {
        foreach ($this->urlFields($url) as $field => [$region, $block]) {
            $this->assertNull($this->problemIn($region, $block), $field);
        }
    }

    public function test_an_accepted_url_is_escaped_in_the_href(): void
    {
        $url = 'https://system.viteseo.ph/book?ref=email&x=1#top';
        $html = $this->renderer()->toHtml($this->layout(), $this->content([$this->button(['url' => $url])]), $this->context());

        $this->assertStringContainsString('href="https://system.viteseo.ph/book?ref=email&amp;x=1#top"', $html);
    }

    // ── Token injection ──────────────────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function urlsWithPlaceholders(): array
    {
        return [
            'a bare token' => ['{{first_name}}'],
            'a token in the query' => ['https://example.com/?name={{first_name}}'],
            'a token as the host' => ['https://{{business_name}}.com'],
            'a token in mailto' => ['mailto:{{first_name}}@example.com'],
            'a percent-encoded token' => ['https://example.com/?name=%7B%7Bfirst_name%7D%7D'],
            'a lower-case percent-encoded token' => ['https://example.com/%7b%7bcity%7d%7d'],
            'a half token' => ['https://example.com/{{city'],
        ];
    }

    #[DataProvider('urlsWithPlaceholders')]
    public function test_placeholders_are_forbidden_in_every_url_field(string $url): void
    {
        foreach ($this->urlFields($url) as $field => [$region, $block, $path]) {
            $this->assertSame(['path' => $path, 'message' => EmailDesignValidator::PLACEHOLDER_IN_URL], $this->problemIn($region, $block), $field);
        }
    }

    public function test_placeholders_are_forbidden_in_alt_text_image_references_and_link_attributes(): void
    {
        $this->assertContentProblem('blocks.0.alt', EmailDesignValidator::PLACEHOLDER_NOT_ALLOWED, $this->content([$this->image(['alt' => 'Photo of {{business_name}}'])]));
        $this->assertContentProblem('blocks.0.asset_id', EmailDesignValidator::INVALID_ASSET, $this->content([$this->image(['asset_id' => '{{business_name}}'])]));

        $mark = ['type' => 'link', 'attrs' => ['href' => 'https://viteseo.ph', 'title' => '{{first_name}}']];
        $this->assertContentProblem(
            'blocks.0.content.content.0.content.0.marks.0.attrs.title',
            EmailDesignValidator::PLACEHOLDER_NOT_ALLOWED,
            $this->content([$this->textBlock(['content' => $this->doc($this->para($this->text('x', [$mark])))])]),
        );
    }

    public function test_a_token_value_containing_a_placeholder_is_never_expanded_again(): void
    {
        $layout = $this->layout([], []);
        $content = $this->content([$this->heading(['text' => 'Hi {{first_name}}'])]);
        $context = $this->context(['first_name' => '{{sender_name}} {{sender_phone}}']);

        $html = $this->renderer()->toHtml($layout, $content, $context);
        $text = $this->renderer()->toText($layout, $content, $context);

        $this->assertStringContainsString('>Hi {{sender_name}} {{sender_phone}}</h1>', $html);
        $this->assertSame("Hi {{sender_name}} {{sender_phone}}\n\nBest regards,\nAlex Reyes\nGrowth Consultant\n+63 917 123 4567", $text);
        $this->assertSame(1, substr_count($html, 'Alex Reyes'));
    }

    public function test_a_token_value_cannot_add_lines_or_reorder_text(): void
    {
        $layout = $this->layout([], [], signature: ['enabled' => false]);
        $content = $this->content([$this->heading(['text' => 'Hi {{first_name}}']), $this->textBlock()]);
        $context = $this->context([
            'first_name' => "Maria\r\nBcc: evil@example.com\n\nClick https://evil.example",
            'business_name' => "Santos\u{202E}yrekaB\u{2028}Bakery\0",
        ]);

        $text = $this->renderer()->toText($layout, $content, $context);
        $html = $this->renderer()->toHtml($layout, $content, $context);

        $this->assertStringStartsWith('Hi Maria Bcc: evil@example.com Click https://evil.example', $text);
        $this->assertSame(2, substr_count($text, "\n"), 'only the blank line between the two blocks');
        $this->assertStringContainsString('Santos yrekaB Bakery ', $text);
        $this->assertStringNotContainsString("\u{202E}", $html.$text);
        $this->assertStringNotContainsString("\0", $html.$text);
        $this->assertStringNotContainsString("\r", $html);
    }

    public function test_invalid_utf8_in_a_token_value_is_scrubbed_not_rendered(): void
    {
        $html = $this->renderer()->toHtml($this->layout(), $this->content([$this->heading(['text' => '{{first_name}}'])]), $this->context(['first_name' => "Mar\xB1ia"]));

        $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
        $this->assertSafeHtml($html);
    }

    // ── Excessive nesting ────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function nestedLists(int $levels): array
    {
        $item = $this->item($this->para($this->text('deepest', [['type' => 'bold']])));
        for ($i = 0; $i < $levels; $i++) {
            $item = $this->item($this->para($this->text('level '.$i)), $this->bullets($item));
        }

        return $this->doc($this->bullets($item));
    }

    /** @return array<string, mixed> */
    private function deepJunk(int $levels): array
    {
        $value = ['leaf' => true];
        for ($i = 0; $i < $levels; $i++) {
            $value = ['n' => $value];
        }

        return $value;
    }

    public function test_deeply_nested_lists_are_rejected_for_depth(): void
    {
        $this->assertNull($this->validator()->contentProblem($this->content([$this->textBlock(['content' => $this->nestedLists(3)])])));
        $this->assertContentProblem('', EmailDesignValidator::TOO_DEEP, $this->content([$this->textBlock(['content' => $this->nestedLists(20)])]));
    }

    public function test_depth_is_rejected_before_anything_below_it_is_inspected(): void
    {
        // An unknown block type, an unknown field and a script payload, all below the depth limit: the answer
        // is depth, proving no structural check ran on a document too deep to be walked safely.
        $junk = ['type' => 'html', 'id' => self::SCRIPT, 'junk' => $this->deepJunk(EmailDesignSchema::MAX_DEPTH)];
        $content = $this->content([$junk]);
        $layout = $this->layout([$junk], []);

        $this->assertContentProblem('', EmailDesignValidator::TOO_DEEP, $content);
        $this->assertLayoutProblem('', EmailDesignValidator::TOO_DEEP, $layout);
        $this->assertSame(['path' => '', 'message' => EmailDesignValidator::TOO_DEEP], $this->validator()->contentProblemInJson((string) json_encode($content)));
        $this->assertSame(['path' => '', 'message' => EmailDesignValidator::TOO_DEEP], $this->validator()->layoutProblemInJson((string) json_encode($layout)));
    }

    public function test_nesting_beyond_what_json_can_encode_is_still_reported_as_depth(): void
    {
        $this->assertContentProblem('', EmailDesignValidator::TOO_DEEP, [...$this->content(), 'junk' => $this->deepJunk(600)]);
        $this->assertLayoutProblem('', EmailDesignValidator::TOO_DEEP, [...$this->layout(), 'junk' => $this->deepJunk(600)]);
    }

    public function test_a_document_exactly_at_the_depth_limit_is_not_rejected_for_depth(): void
    {
        // document (1) → junk (2) → … : an unknown field, so the answer must be about the field, not depth.
        $junk = $this->deepJunk(EmailDesignSchema::MAX_DEPTH - 3);

        $this->assertContentProblem('junk', EmailDesignValidator::UNKNOWN_FIELD, [...$this->content(), 'junk' => $junk]);
        $this->assertLayoutProblem('junk', EmailDesignValidator::UNKNOWN_FIELD, [...$this->layout(), 'junk' => $junk]);
    }

    public function test_a_too_deep_document_is_never_rendered(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer()->toHtml($this->layout(), $this->content([$this->textBlock(['content' => $this->nestedLists(20)])]), $this->context());
    }

    // ── Oversized JSON ───────────────────────────────────────────────────────────────────────

    public function test_an_oversized_document_is_rejected_before_depth_or_structure(): void
    {
        $blocks = [['type' => 'script', 'junk' => $this->deepJunk(EmailDesignSchema::MAX_DEPTH)], $this->heading(['text' => str_repeat('a', EmailDesignSchema::MAX_BYTES)])];

        $this->assertContentProblem('', EmailDesignValidator::TOO_LARGE, $this->content($blocks));
        $this->assertLayoutProblem('', EmailDesignValidator::TOO_LARGE, $this->layout($blocks, []));
    }

    public function test_an_oversized_json_string_is_rejected_without_being_decoded(): void
    {
        $garbage = str_repeat('{', EmailDesignSchema::MAX_BYTES + 1);

        $this->assertSame(['path' => '', 'message' => EmailDesignValidator::TOO_LARGE], $this->validator()->contentProblemInJson($garbage));
        $this->assertSame(['path' => '', 'message' => EmailDesignValidator::TOO_LARGE], $this->validator()->layoutProblemInJson($garbage));
    }

    public function test_many_small_blocks_cannot_exceed_the_byte_limit_either(): void
    {
        $text = $this->doc($this->para($this->text(str_repeat('b', EmailDesignSchema::MAX_BLOCK_TEXT))));
        $blocks = array_map(fn (int $i) => $this->textBlock(['id' => 'text-'.$i, 'content' => $text]), range(1, 25));

        $this->assertContentProblem('', EmailDesignValidator::TOO_LARGE, $this->content($blocks));
    }

    // ── Unknown block types ──────────────────────────────────────────────────────────────────

    /** @return array<string, array{mixed}> */
    public static function unknownBlockTypes(): array
    {
        return [
            'html' => ['html'],
            'raw html' => ['raw_html'],
            'script' => ['script'],
            'iframe' => ['iframe'],
            'columns' => ['columns'],
            'video' => ['video'],
            'form' => ['form'],
            'signature (an area of the design, not a block)' => ['signature'],
            'upper-case text' => ['Text'],
            'padded text' => [' text'],
            'empty' => [''],
            'integer' => [5],
            'null' => [null],
            'list' => [['text']],
        ];
    }

    #[DataProvider('unknownBlockTypes')]
    public function test_unknown_block_types_are_rejected(mixed $type): void
    {
        $block = ['type' => $type, 'id' => 'x', 'html' => self::SCRIPT];

        $this->assertContentProblem('blocks.0.type', EmailDesignValidator::UNKNOWN_BLOCK, $this->content([$block]));
        $this->assertLayoutProblem('header.0.type', EmailDesignValidator::UNKNOWN_BLOCK, $this->layout([$block], []));
    }

    // ── Invalid colors ───────────────────────────────────────────────────────────────────────

    /** @return array<string, array{mixed}> */
    public static function invalidColors(): array
    {
        return [
            'three digits' => ['#fff'],
            'eight digits' => ['#ffffffff'],
            'seven digits' => ['#1234567'],
            'named' => ['red'],
            'non-hex digit' => ['#12345g'],
            'no hash' => ['123456'],
            'rgb function' => ['rgb(0,0,0)'],
            'trailing newline' => ["#123456\n"],
            'css injection' => ['#123456;background:url(https://evil.example/x.png)'],
            'attribute breakout' => ['#123456" onload="alert(1)'],
            'expression' => ['expression(alert(1))'],
            'empty' => [''],
            'integer' => [123456],
        ];
    }

    #[DataProvider('invalidColors')]
    public function test_invalid_colors_are_rejected_everywhere_a_color_is_set(mixed $color): void
    {
        // A null color is not invalid: it inherits (EmailDesignStyleTest). Every color style is checked.
        foreach (array_keys(array_diff_key(EmailDesignSchema::SYSTEM_DEFAULTS, ['font' => true])) as $style) {
            $this->assertLayoutProblem("styles.{$style}", EmailDesignValidator::INVALID_COLOR, $this->layout(styles: [$style => $color]));
            $this->assertContentProblem("style_overrides.{$style}", EmailDesignValidator::INVALID_COLOR, $this->content(extra: ['style_overrides' => [$style => $color]]));
        }

        foreach ([['heading', 'color'], ['text', 'color'], ['footer', 'color'], ['divider', 'color'], ['button', 'background_color'], ['button', 'text_color']] as [$type, $field]) {
            $this->assertSame(
                ['path' => $this->placement($type).'.0.'.$field, 'message' => EmailDesignValidator::INVALID_COLOR],
                $this->problemFor($this->block($type, [$field => $color])),
            );
        }
    }

    // ── Invalid fonts ────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{mixed}> */
    public static function invalidFonts(): array
    {
        return [
            'unlisted font' => ['comic-sans'],
            'a stack instead of a key' => ['Arial, Helvetica, sans-serif'],
            'capitalized key' => ['Arial'],
            'key with css' => ['arial;background:url(https://evil.example/x.png)'],
            'key with a quote' => ["arial'"],
            'expression' => ['expression(alert(1))'],
            'empty' => [''],
            'integer' => [1],
        ];
    }

    #[DataProvider('invalidFonts')]
    public function test_invalid_fonts_are_rejected(mixed $font): void
    {
        $this->assertLayoutProblem('styles.font', EmailDesignValidator::INVALID_FONT, $this->layout(styles: ['font' => $font]));
        $this->assertContentProblem('style_overrides.font', EmailDesignValidator::INVALID_FONT, $this->content(extra: ['style_overrides' => ['font' => $font]]));
    }

    // ── Invalid dimensions ───────────────────────────────────────────────────────────────────

    /** @return array<string, array{string, string, mixed, array{int, int}}> */
    public static function invalidDimensions(): array
    {
        $cases = [];
        $ranges = [
            ['heading', 'level', EmailDesignSchema::HEADING_LEVELS],
            ['text', 'font_size', EmailDesignSchema::TEXT_FONT_SIZE],
            ['footer', 'font_size', EmailDesignSchema::FOOTER_FONT_SIZE],
            ['image', 'width', EmailDesignSchema::IMAGE_WIDTH],
            ['logo', 'width', EmailDesignSchema::LOGO_WIDTH],
            ['spacer', 'height', EmailDesignSchema::SPACER_HEIGHT],
            ['divider', 'thickness', EmailDesignSchema::DIVIDER_THICKNESS],
            ['button', 'radius', EmailDesignSchema::BUTTON_RADIUS],
        ];

        foreach ($ranges as [$type, $field, [$min, $max]]) {
            $values = [
                'below the minimum' => $min - 1,
                'above the maximum' => $max + 1,
                'negative' => -100,
                'huge' => PHP_INT_MAX,
                'a float' => $min + 0.5,
                'a numeric string' => (string) $min,
                'a css length' => $min.'px',
                'true' => true,
                'null' => null,
            ];
            foreach ($values as $label => $value) {
                $cases["{$type} {$field} {$label}"] = [$type, $field, $value, [$min, $max]];
            }
        }

        return $cases;
    }

    /** @param  array{int, int}  $range */
    #[DataProvider('invalidDimensions')]
    public function test_invalid_dimensions_are_rejected(string $type, string $field, mixed $value, array $range): void
    {
        $this->assertSame(
            ['path' => $this->placement($type).'.0.'.$field, 'message' => sprintf(EmailDesignValidator::OUT_OF_RANGE, $range[0], $range[1])],
            $this->problemFor($this->block($type, [$field => $value])),
        );
    }

    public function test_an_image_cannot_be_wider_than_the_content_column(): void
    {
        $this->assertSame(EmailDesignSchema::CONTENT_WIDTH - 2 * EmailDesignSchema::CONTENT_PADDING, EmailDesignSchema::IMAGE_WIDTH[1]);
    }

    // ── Invalid image references ─────────────────────────────────────────────────────────────

    /** @return array<string, array{mixed}> */
    public static function invalidImageReferences(): array
    {
        return [
            'remote https url' => ['https://evil.example/a.png'],
            'remote http url' => ['http://evil.example/a.png'],
            'protocol-relative url' => ['//evil.example/a.png'],
            'data uri' => ['data:image/png;base64,iVBORw0KGgo='],
            'path traversal' => ['../../../../etc/passwd'],
            'storage path' => ['crm/email-assets/1/logo.png'],
            'upper-case uuid' => ['0B9F5A8E-3C2D-4E1F-9A7B-6C5D4E3F2A1B'],
            'uuid without dashes' => ['0b9f5a8e3c2d4e1f9a7b6c5d4e3f2a1b'],
            'uuid in braces' => ['{0b9f5a8e-3c2d-4e1f-9a7b-6c5d4e3f2a1b}'],
            'uuid with a trailing newline' => ["0b9f5a8e-3c2d-4e1f-9a7b-6c5d4e3f2a1b\n"],
            'uuid with an extension' => ['0b9f5a8e-3c2d-4e1f-9a7b-6c5d4e3f2a1b.png'],
            'numeric id' => [42],
            'empty' => [''],
            'null' => [null],
        ];
    }

    #[DataProvider('invalidImageReferences')]
    public function test_invalid_image_references_are_rejected(mixed $reference): void
    {
        $this->assertContentProblem('blocks.0.asset_id', EmailDesignValidator::INVALID_ASSET, $this->content([$this->image(['asset_id' => $reference])]));
        $this->assertLayoutProblem('header.0.asset_id', EmailDesignValidator::INVALID_ASSET, $this->layout([$this->image(['asset_id' => $reference])], []));
    }

    public function test_an_image_or_logo_block_cannot_carry_its_own_source_url(): void
    {
        foreach (['src', 'url', 'href', 'srcset'] as $field) {
            $this->assertContentProblem('blocks.0.'.$field, EmailDesignValidator::UNKNOWN_FIELD, $this->content([$this->image([$field => 'https://evil.example/a.png'])]));
            $this->assertLayoutProblem('header.0.'.$field, EmailDesignValidator::UNKNOWN_FIELD, $this->layout([$this->logo([$field => 'https://evil.example/a.png'])], []));
        }
    }

    public function test_an_image_the_context_did_not_resolve_is_omitted(): void
    {
        $content = $this->content([$this->heading(), $this->image(['asset_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'])]);

        $this->assertStringNotContainsString('<img', $this->renderer()->toHtml($this->layout([], []), $content, $this->context()));
    }

    /** @return array<string, array{string}> */
    public static function unsafeContextUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data uri' => ['data:image/png;base64,iVBORw0KGgo='],
            'relative' => ['/storage/logo.png'],
            'protocol-relative' => ['//cdn.example/logo.png'],
            'attribute breakout' => ['https://cdn.example/logo.png" onerror="alert(1)'],
            'credentials' => ['https://user:pass@cdn.example/logo.png'],
            'space' => ['https://cdn.example/my logo.png'],
            'no host' => ['https:///logo.png'],
        ];
    }

    #[DataProvider('unsafeContextUrls')]
    public function test_an_unsafe_url_from_the_context_omits_the_image_rather_than_rendering_it(string $url): void
    {
        $html = $this->renderer()->toHtml(
            $this->layout([$this->logo()], []),
            $this->content([$this->heading(), $this->image()]),
            $this->context(assetUrls: [self::ASSET_ID => $url], logoUrl: $url),
        );

        $this->assertStringNotContainsString('<img', $html);
        $this->assertSafeHtml($html);
    }

    // ── Output shape ─────────────────────────────────────────────────────────────────────────

    public function test_a_full_email_renders_only_allowlisted_elements_and_attributes(): void
    {
        $layout = $this->layout(
            [$this->logo(['link_url' => 'tel:+639171234567']), $this->heading(['id' => 'header-heading', 'level' => 3])],
            [$this->divider(['id' => 'footer-divider']), $this->footer()],
        );
        $content = $this->content(
            [
                ...$this->contentBlocks(),
                $this->textBlock(['id' => 'lists', 'content' => $this->nestedLists(2)]),
                $this->image(['id' => 'linked-image', 'link_url' => 'mailto:hello@viteseo.ph']),
                $this->heading(['id' => 'h2', 'level' => 2]),
            ],
            ['preheader' => 'Hello {{first_name}}'],
        );

        $this->assertSafeHtml($this->renderer()->toHtml($layout, $content, $this->context()));
    }
}
