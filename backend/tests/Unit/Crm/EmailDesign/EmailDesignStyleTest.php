<?php

declare(strict_types=1);

namespace Tests\Unit\Crm\EmailDesign;

use App\Modules\Crm\Support\EmailDesign\EmailDesignContext;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use App\Modules\Crm\Support\EmailDesign\EmailDesignSchema;
use App\Modules\Crm\Support\EmailDesign\EmailDesignValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Style inheritance, the preheader and the logo's link.
 *
 * The chain under test is block color → template style override → design style → brand style → system
 * default. Documents that set every style explicitly are unaffected by brand styles, so an email only changes
 * when its design, its template or the branding it inherits changes.
 */
class EmailDesignStyleTest extends TestCase
{
    use BuildsEmailDesigns;

    private function renderHtml(array $layout, array $content, ?EmailDesignContext $context = null): string
    {
        return (new EmailDesignRenderer)->toHtml($layout, $content, $context ?? $this->context());
    }

    /** A fixture block with its colors removed, so each one inherits. */
    private function uncolored(array $block): array
    {
        return array_diff_key($block, array_flip(['color', 'background_color', 'text_color']));
    }

    /**
     * A layout that sets no style of its own and shows no signature.
     *
     * @param  list<array<string, mixed>>  $header
     * @param  list<array<string, mixed>>  $footer
     * @param  array<string, mixed>  $styles
     * @return array<string, mixed>
     */
    private function plainLayout(array $header = [], array $footer = [], array $styles = []): array
    {
        return [
            'version' => 1,
            'styles' => $styles,
            'header' => $header,
            'signature' => ['enabled' => false, 'sign_off' => '', 'align' => 'left'],
            'footer' => $footer,
        ];
    }

    // ── Validation ───────────────────────────────────────────────────────────────────────────

    public function test_every_style_may_be_left_to_inheritance(): void
    {
        $validator = new EmailDesignValidator;
        $layout = $this->plainLayout([$this->logo()], [$this->uncolored($this->divider(['id' => 'footer-divider'])), $this->uncolored($this->footer())]);
        $content = $this->content(array_map(fn (array $block) => $this->uncolored($block), $this->contentBlocks()));

        $this->assertNull($validator->layoutProblem($layout));
        $this->assertNull($validator->contentProblem($content));
    }

    public function test_a_null_style_inherits_exactly_like_an_absent_one(): void
    {
        $validator = new EmailDesignValidator;
        $nullStyles = array_fill_keys(array_keys(EmailDesignSchema::SYSTEM_DEFAULTS), null);

        $nullLayout = $this->plainLayout([], [$this->footer(['color' => null])], $nullStyles);
        $nullContent = $this->content([
            $this->heading(['color' => null]),
            $this->textBlock(['color' => null]),
            $this->button(['background_color' => null, 'text_color' => null]),
            $this->divider(['color' => null]),
        ], ['style_overrides' => $nullStyles]);

        $absentLayout = $this->plainLayout([], [$this->uncolored($this->footer())]);
        $absentContent = $this->content(array_map(fn (array $block) => $this->uncolored($block), $nullContent['blocks']));

        $this->assertNull($validator->layoutProblem($nullLayout));
        $this->assertNull($validator->contentProblem($nullContent));
        $this->assertSame($this->renderHtml($absentLayout, $absentContent), $this->renderHtml($nullLayout, $nullContent));
    }

    public function test_a_style_outside_the_schema_is_refused_in_both_documents(): void
    {
        $validator = new EmailDesignValidator;

        $this->assertSame(
            ['path' => 'styles.button_color', 'message' => EmailDesignValidator::UNKNOWN_FIELD],
            $validator->layoutProblem($this->layout(styles: ['button_color' => '#000000'])),
        );
        $this->assertSame(
            ['path' => 'style_overrides.button_color', 'message' => EmailDesignValidator::UNKNOWN_FIELD],
            $validator->contentProblem($this->content(extra: ['style_overrides' => ['button_color' => '#000000']])),
        );
    }

    // ── Rendering the chain ──────────────────────────────────────────────────────────────────

    public function test_an_unset_style_renders_the_system_default(): void
    {
        $defaults = EmailDesignSchema::SYSTEM_DEFAULTS;
        $html = $this->renderHtml(
            $this->plainLayout([], [$this->uncolored($this->footer())]),
            $this->content([
                $this->uncolored($this->heading()),
                $this->uncolored($this->textBlock()),
                $this->uncolored($this->button()),
                $this->uncolored($this->divider()),
            ]),
        );

        $this->assertStringContainsString('<body style="margin:0;padding:0;width:100%;background-color:'.$defaults['background_color'].';">', $html);
        $this->assertStringContainsString('bgcolor="'.$defaults['content_background_color'].'"', $html);
        $this->assertStringContainsString('font-family:Arial, Helvetica, sans-serif;', $html);
        $this->assertStringContainsString('font-weight:bold;color:'.$defaults['text_color'].';text-align:left;', $html);
        $this->assertStringContainsString('font-size:14px;line-height:1.5;color:'.$defaults['text_color'].';', $html);
        $this->assertStringContainsString('font-size:12px;line-height:1.5;color:'.$defaults['muted_text_color'].';', $html);
        $this->assertStringContainsString('style="color:'.$defaults['link_color'].';text-decoration:underline;"', $html);
        $this->assertStringContainsString('<td align="center" bgcolor="'.$defaults['accent_color'].'"', $html);
        $this->assertStringContainsString('font-weight:bold;color:'.$defaults['accent_text_color'].';text-decoration:none;', $html);
        $this->assertStringContainsString('<td height="1" bgcolor="'.$defaults['divider_color'].'"', $html);
    }

    public function test_styles_resolve_override_then_design_then_brand_then_system(): void
    {
        $context = $this->context(brandStyles: ['text_color' => '#222222', 'accent_color' => '#333333', 'divider_color' => '#444444', 'font' => 'georgia']);

        $styles = (new EmailDesignRenderer)->effectiveStyles(
            ['text_color' => '#111111', 'accent_color' => '#555555'],
            ['accent_color' => '#666666'],
            $context,
        );

        $this->assertSame(array_keys(EmailDesignSchema::SYSTEM_DEFAULTS), array_keys($styles));
        $this->assertSame('#666666', $styles['accent_color'], 'the template override beats the design');
        $this->assertSame('#111111', $styles['text_color'], 'the design beats the brand');
        $this->assertSame('#444444', $styles['divider_color'], 'the brand beats the system default');
        $this->assertSame('georgia', $styles['font']);
        $this->assertSame(EmailDesignSchema::SYSTEM_DEFAULTS['muted_text_color'], $styles['muted_text_color'], 'the system default ends the chain');
    }

    public function test_a_block_color_beats_every_style_and_an_unset_one_follows_the_chain(): void
    {
        $context = $this->context(brandStyles: ['text_color' => '#222222', 'accent_color' => '#333333']);
        $layout = $this->plainLayout(styles: ['text_color' => '#111111']);
        $content = $this->content(
            [$this->heading(['color' => '#444444']), $this->uncolored($this->heading(['id' => 'second'])), $this->uncolored($this->button())],
            ['style_overrides' => ['accent_color' => '#555555']],
        );

        $html = $this->renderHtml($layout, $content, $context);

        $this->assertStringContainsString('font-weight:bold;color:#444444;', $html);
        $this->assertStringContainsString('font-weight:bold;color:#111111;', $html);
        $this->assertStringNotContainsString('#222222', $html);
        $this->assertStringContainsString('<td align="center" bgcolor="#555555"', $html);
        $this->assertStringNotContainsString('#333333', $html);
    }

    public function test_a_template_override_changes_only_that_template(): void
    {
        $layout = $this->plainLayout();
        $button = $this->uncolored($this->button());

        $overridden = $this->renderHtml($layout, $this->content([$button], ['style_overrides' => ['accent_color' => '#AA0000']]));
        $inheriting = $this->renderHtml($layout, $this->content([$button]));

        $this->assertStringContainsString('<td align="center" bgcolor="#aa0000"', $overridden);
        $this->assertStringContainsString('<td align="center" bgcolor="'.EmailDesignSchema::SYSTEM_DEFAULTS['accent_color'].'"', $inheriting);
    }

    /** @return array<string, array{string, mixed}> */
    public static function unusableStyleValues(): array
    {
        return [
            'named color' => ['text_color', 'red'],
            'short hex' => ['accent_color', '#fff'],
            'css injection' => ['divider_color', '#123456;background:url(https://evil.example/x.png)'],
            'attribute breakout' => ['link_color', '#123456" onload="alert(1)'],
            'unlisted font' => ['font', 'comic-sans'],
            'font stack' => ['font', 'Arial, sans-serif'],
            'not a string' => ['muted_text_color', 123456],
            'null' => ['background_color', null],
        ];
    }

    #[DataProvider('unusableStyleValues')]
    public function test_an_unusable_value_at_any_step_is_skipped(string $key, mixed $value): void
    {
        $renderer = new EmailDesignRenderer;
        $context = $this->context(brandStyles: [$key => $value, 'unknown_key' => '#000000']);

        $this->assertSame(EmailDesignSchema::SYSTEM_DEFAULTS, $renderer->effectiveStyles([$key => $value], [$key => $value], $context));
    }

    public function test_brand_colors_are_lowercased(): void
    {
        $styles = (new EmailDesignRenderer)->effectiveStyles([], [], $this->context(brandStyles: ['text_color' => '#ABCDEF']));

        $this->assertSame('#abcdef', $styles['text_color']);
    }

    public function test_documents_that_set_every_style_they_use_are_unaffected_by_brand_styles(): void
    {
        $brand = array_map(fn (string $value) => str_starts_with($value, '#') ? '#010203' : 'times', EmailDesignSchema::SYSTEM_DEFAULTS);

        $this->assertSame(
            $this->renderHtml($this->layout(), $this->content()),
            $this->renderHtml($this->layout(), $this->content(), $this->context(brandStyles: $brand)),
        );
    }

    public function test_styles_never_change_the_plain_text_part(): void
    {
        $renderer = new EmailDesignRenderer;
        $inheriting = $this->content(array_map(fn (array $block) => $this->uncolored($block), $this->contentBlocks()), ['style_overrides' => ['accent_color' => '#AA0000']]);

        $this->assertSame(
            $renderer->toText($this->layout(), $this->content(), $this->context()),
            $renderer->toText($this->layout(styles: ['text_color' => '#010203']), $inheriting, $this->context()),
        );
    }

    // ── Preheader ────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{mixed, string}> */
    public static function unusablePreheaders(): array
    {
        return [
            'too long' => [str_repeat('a', 151), sprintf(EmailDesignValidator::TEXT_TOO_LONG, 150)],
            'newline' => ["Hello\nthere", EmailDesignValidator::INVALID_TEXT],
            'bidi override' => ["Hello \u{202E}there", EmailDesignValidator::INVALID_TEXT],
            'unknown placeholder' => ['Hi {{email}}', EmailDesignValidator::UNKNOWN_PLACEHOLDER],
            'incomplete placeholder' => ['Hi {{first_name', EmailDesignValidator::STRAY_BRACES],
            'not a string' => [42, EmailDesignValidator::MALFORMED],
        ];
    }

    #[DataProvider('unusablePreheaders')]
    public function test_an_unusable_preheader_is_refused(mixed $preheader, string $message): void
    {
        $this->assertSame(
            ['path' => 'preheader', 'message' => $message],
            (new EmailDesignValidator)->contentProblem($this->content(extra: ['preheader' => $preheader])),
        );
    }

    public function test_a_preheader_may_use_placeholders_up_to_its_limit(): void
    {
        $validator = new EmailDesignValidator;

        $this->assertNull($validator->contentProblem($this->content(extra: ['preheader' => 'Ideas for {{business_name}} in {{city}}'])));
        $this->assertNull($validator->contentProblem($this->content(extra: ['preheader' => str_repeat('ñ', 150)])));
    }

    public function test_the_preheader_is_hidden_first_in_the_body_filled_in_and_escaped(): void
    {
        $html = $this->renderHtml($this->layout(), $this->content(extra: ['preheader' => 'Ideas for {{business_name}} <b>& more</b>']));

        $this->assertMatchesRegularExpression(
            '/<body [^>]*>\n<div style="display:none;[^"]*mso-hide:all;">Ideas for Santos Bakery &lt;b&gt;&amp; more&lt;\/b&gt;(&#847;&zwnj;&nbsp;)+<\/div>\n<table /',
            $html,
        );
    }

    public function test_the_preheader_is_not_part_of_the_plain_text(): void
    {
        $text = (new EmailDesignRenderer)->toText($this->layout(), $this->content(extra: ['preheader' => 'Ideas for {{business_name}}']), $this->context());

        $this->assertStringNotContainsString('Ideas for', $text);
    }

    public function test_a_blank_preheader_renders_nothing(): void
    {
        $layout = $this->layout();

        $this->assertStringNotContainsString('<div', $this->renderHtml($layout, $this->content(extra: ['preheader' => '   '])));
        $this->assertStringNotContainsString('<div', $this->renderHtml($layout, $this->content(extra: ['preheader' => '{{city}}']), $this->context(['city' => ''])));
        $this->assertSame($this->renderHtml($layout, $this->content()), $this->renderHtml($layout, $this->content(extra: ['preheader' => ''])));
    }

    // ── Logo link ────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{array<string, mixed>, array{string, string}|null}> */
    public static function logoLinks(): array
    {
        return [
            'no target and no address' => [[], null],
            'website' => [['link_target' => 'website'], null],
            'website with a null address' => [['link_target' => 'website', 'link_url' => null], null],
            'none' => [['link_target' => 'none'], null],
            'custom' => [['link_target' => 'custom', 'link_url' => 'https://viteseo.ph'], null],
            'an address with no target' => [['link_url' => 'mailto:hello@viteseo.ph'], null],
            'custom without an address' => [['link_target' => 'custom'], ['header.0.link_url', EmailDesignValidator::UNSAFE_URL]],
            'custom with a null address' => [['link_target' => 'custom', 'link_url' => null], ['header.0.link_url', EmailDesignValidator::UNSAFE_URL]],
            'custom with an unsafe address' => [['link_target' => 'custom', 'link_url' => 'javascript:alert(1)'], ['header.0.link_url', EmailDesignValidator::UNSAFE_URL]],
            'custom with a placeholder' => [['link_target' => 'custom', 'link_url' => 'https://example.com/{{city}}'], ['header.0.link_url', EmailDesignValidator::PLACEHOLDER_IN_URL]],
            'none with an address' => [['link_target' => 'none', 'link_url' => 'https://viteseo.ph'], ['header.0.link_url', EmailDesignValidator::LINK_URL_NOT_ALLOWED]],
            'website with an address' => [['link_target' => 'website', 'link_url' => 'https://viteseo.ph'], ['header.0.link_url', EmailDesignValidator::LINK_URL_NOT_ALLOWED]],
            'unknown target' => [['link_target' => 'brand'], ['header.0.link_target', EmailDesignValidator::INVALID_LINK_TARGET]],
            'target that is not a string' => [['link_target' => 1], ['header.0.link_target', EmailDesignValidator::INVALID_LINK_TARGET]],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array{string, string}|null  $expected
     */
    #[DataProvider('logoLinks')]
    public function test_logo_link_targets_are_validated(array $fields, ?array $expected): void
    {
        $problem = (new EmailDesignValidator)->layoutProblem($this->layout([$this->logo($fields)], []));

        $this->assertSame($expected === null ? null : ['path' => $expected[0], 'message' => $expected[1]], $problem);
    }

    private function logoAnchor(string $html): ?string
    {
        return preg_match('/<a href="([^"]*)"[^>]*><img src="'.preg_quote(self::LOGO_URL, '/').'"/', $html, $m) === 1 ? html_entity_decode($m[1]) : null;
    }

    /** A layout whose only link could be the logo's. */
    private function logoLayout(array $logo = []): array
    {
        return $this->layout([$this->logo($logo)], []);
    }

    public function test_a_logo_links_to_the_organization_website_by_default(): void
    {
        $html = $this->renderHtml($this->logoLayout(), $this->content([$this->heading()]), $this->context(brandWebsite: 'https://viteseo.ph'));

        $this->assertSame('https://viteseo.ph', $this->logoAnchor($html));
        $this->assertStringContainsString('<a href="https://viteseo.ph" target="_blank" rel="noopener noreferrer" style="display:block;text-decoration:none;"><img src="'.self::LOGO_URL.'"', $html);
    }

    public function test_a_logo_is_not_linked_when_the_organization_has_no_website(): void
    {
        $html = $this->renderHtml($this->logoLayout(), $this->content([$this->heading()]));

        $this->assertStringContainsString('<img src="'.self::LOGO_URL.'"', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function test_a_logo_set_to_no_link_ignores_the_website(): void
    {
        $html = $this->renderHtml($this->logoLayout(['link_target' => 'none']), $this->content([$this->heading()]), $this->context(brandWebsite: 'https://viteseo.ph'));

        $this->assertStringNotContainsString('<a ', $html);
    }

    public function test_a_custom_logo_link_overrides_the_website(): void
    {
        $context = $this->context(brandWebsite: 'https://viteseo.ph');
        $content = $this->content([$this->heading()]);

        $this->assertSame('https://viteseo.ph/offer', $this->logoAnchor($this->renderHtml($this->logoLayout(['link_target' => 'custom', 'link_url' => 'https://viteseo.ph/offer']), $content, $context)));
        $this->assertSame('mailto:hello@viteseo.ph', $this->logoAnchor($this->renderHtml($this->logoLayout(['link_url' => 'mailto:hello@viteseo.ph']), $content, $context)));
    }

    /** @return array<string, array{string}> */
    public static function unusableWebsites(): array
    {
        return [
            'plain http' => ['http://viteseo.ph'],
            'javascript' => ['javascript:alert(1)'],
            'attribute breakout' => ['https://viteseo.ph/" onmouseover="alert(1)'],
            'placeholder' => ['https://viteseo.ph/{{city}}'],
            'credentials' => ['https://user:pass@viteseo.ph'],
            'empty' => [''],
        ];
    }

    #[DataProvider('unusableWebsites')]
    public function test_an_unusable_organization_website_leaves_the_logo_unlinked(string $website): void
    {
        $html = $this->renderHtml($this->logoLayout(), $this->content([$this->heading()]), $this->context(brandWebsite: $website));

        $this->assertStringContainsString('<img src="'.self::LOGO_URL.'"', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('onmouseover', $html);
    }
}
