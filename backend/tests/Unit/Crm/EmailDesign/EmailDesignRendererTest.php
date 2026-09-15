<?php

declare(strict_types=1);

namespace Tests\Unit\Crm\EmailDesign;

use App\Modules\Crm\Support\EmailDesign\EmailDesignContext;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use PHPUnit\Framework\TestCase;

/**
 * What a design layout and a template's content become together: the table-based text/html part and the
 * text/plain part. Attack-shaped input is covered by EmailDesignSecurityTest and inheritance by
 * EmailDesignStyleTest; this pins composition, layout, substitution and determinism.
 */
class EmailDesignRendererTest extends TestCase
{
    use BuildsEmailDesigns;

    private function renderHtml(?array $layout = null, ?array $content = null, ?EmailDesignContext $context = null): string
    {
        return (new EmailDesignRenderer)->toHtml($layout ?? $this->layout(), $content ?? $this->content(), $context ?? $this->context());
    }

    private function renderText(?array $layout = null, ?array $content = null, ?EmailDesignContext $context = null): string
    {
        return (new EmailDesignRenderer)->toText($layout ?? $this->layout(), $content ?? $this->content(), $context ?? $this->context());
    }

    /**
     * A layout with no header, no footer and (by default) no signature, so a test sees only its content.
     *
     * @param  array<string, mixed>  $signature
     * @param  array<string, mixed>  $styles
     */
    private function bare(array $signature = ['enabled' => false], array $styles = []): array
    {
        return $this->layout([], [], $styles, $signature);
    }

    // ── Document ─────────────────────────────────────────────────────────────────────────────

    public function test_the_document_is_a_600px_fluid_table_layout_with_an_outlook_ghost_table(): void
    {
        $html = $this->renderHtml();

        $this->assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">", $html);
        $this->assertStringEndsWith("</table>\n</body>\n</html>", $html);
        $this->assertStringContainsString('<body style="margin:0;padding:0;width:100%;background-color:#f4f4f5;">', $html);
        $this->assertStringContainsString('<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->', $html);
        $this->assertStringContainsString('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width:100%;max-width:600px;border-collapse:collapse;background-color:#ffffff;">', $html);
        $this->assertStringContainsString('<!--[if mso]></td></tr></table><![endif]-->', $html);
    }

    public function test_styles_are_inline_only(): void
    {
        $html = $this->renderHtml();

        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringNotContainsString(' class=', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame(0, preg_match('/<(?!\/|!|html|head|meta|title|body|table|tr|td|h[1-3]|p|ul|ol|li|strong|em|u|s|a|img|br)[a-z]/i', $html));
    }

    public function test_every_table_is_marked_presentational(): void
    {
        $html = $this->renderHtml();

        $this->assertSame(substr_count($html, '<table '), substr_count($html, '<table role="presentation"'));
    }

    public function test_the_same_documents_and_context_always_produce_identical_output(): void
    {
        $this->assertSame($this->renderHtml(), $this->renderHtml());
        $this->assertSame($this->renderText(), $this->renderText());
    }

    public function test_key_order_and_mark_order_do_not_change_the_output(): void
    {
        $reverse = function (mixed $value) use (&$reverse): mixed {
            if (! is_array($value)) {
                return $value;
            }
            $mapped = array_map($reverse, $value);

            return array_is_list($value) ? $mapped : array_reverse($mapped, true);
        };

        $marked = fn (array $marks) => $this->content([$this->textBlock(['content' => $this->doc($this->para($this->text('x', $marks)))])]);

        $this->assertSame($this->renderHtml(), $this->renderHtml($reverse($this->layout()), $reverse($this->content())));
        $this->assertSame(
            $this->renderHtml($this->bare(), $marked([['type' => 'bold'], ['type' => 'italic'], $this->link('https://viteseo.ph')])),
            $this->renderHtml($this->bare(), $marked([$this->link('https://viteseo.ph'), ['type' => 'italic'], ['type' => 'bold']])),
        );
    }

    public function test_block_ids_are_never_written(): void
    {
        $html = $this->renderHtml($this->layout([$this->heading(['id' => 'header-marker-9c1d'])], []), $this->content([$this->heading(['id' => 'content-marker-7f3a'])]));

        $this->assertStringNotContainsString('marker', $html);
    }

    public function test_the_design_font_becomes_its_email_safe_stack(): void
    {
        $html = $this->renderHtml($this->bare(styles: ['font' => 'georgia']), $this->content([$this->heading()]));

        $this->assertStringContainsString('font-family:Georgia, Times New Roman, Times, serif;', $html);
    }

    // ── Composition ──────────────────────────────────────────────────────────────────────────

    public function test_the_header_content_signature_and_footer_render_in_that_order(): void
    {
        $layout = $this->layout(
            [$this->heading(['id' => 'header', 'text' => 'HEADER MARK'])],
            [$this->footer(['content' => $this->doc($this->para($this->text('FOOTER MARK')))])],
            signature: ['sign_off' => 'SIGNOFF MARK'],
        );
        $content = $this->content([$this->heading(['text' => 'CONTENT MARK'])]);

        foreach ([$this->renderHtml($layout, $content), $this->renderText($layout, $content)] as $output) {
            $positions = array_map(fn (string $mark) => strpos($output, $mark), ['HEADER MARK', 'CONTENT MARK', 'SIGNOFF MARK', 'FOOTER MARK']);
            $this->assertNotContains(false, $positions);
            $sorted = $positions;
            sort($sorted);
            $this->assertSame($sorted, $positions);
        }
    }

    public function test_the_same_content_renders_in_whatever_design_it_is_shown_in(): void
    {
        $content = $this->content([$this->heading()]);
        $first = $this->layout([], [$this->footer(['content' => $this->doc($this->para($this->text('First design')))])]);
        $second = $this->layout([], [$this->footer(['content' => $this->doc($this->para($this->text('Second design')))])], signature: ['sign_off' => 'Cheers,']);

        $one = $this->renderHtml($first, $content);
        $two = $this->renderHtml($second, $content);

        $this->assertStringContainsString('>Hi Maria,</h1>', $one);
        $this->assertStringContainsString('>Hi Maria,</h1>', $two);
        $this->assertStringContainsString('First design', $one);
        $this->assertStringNotContainsString('Second design', $one);
        $this->assertStringContainsString('Second design', $two);
        $this->assertStringContainsString('Cheers,', $two);
    }

    public function test_the_signature_area_can_be_switched_off(): void
    {
        $content = $this->content([$this->heading()]);

        $this->assertStringNotContainsString('Alex Reyes', $this->renderHtml($this->bare(['enabled' => false]), $content));
        $this->assertSame('Hi Maria,', $this->renderText($this->bare(['enabled' => false]), $content));
    }

    public function test_a_template_sign_off_replaces_the_design_sign_off(): void
    {
        $html = $this->renderHtml($this->bare(['enabled' => true]), $this->content([$this->heading()], ['sign_off' => 'Cheers, {{first_name}}']));

        $this->assertStringContainsString('>Cheers, Maria</p>', $html);
        $this->assertStringNotContainsString('Best regards,', $html);
    }

    public function test_an_empty_template_sign_off_leaves_out_the_sign_off_line_but_not_the_sender(): void
    {
        $text = $this->renderText($this->bare(['enabled' => true]), $this->content([$this->heading()], ['sign_off' => '']));

        $this->assertSame("Hi Maria,\n\nAlex Reyes\nGrowth Consultant\n+63 917 123 4567", $text);
    }

    public function test_the_signature_prints_the_sign_off_and_every_sender_line_that_has_a_value(): void
    {
        $base = 'font-family:Arial, Helvetica, sans-serif;font-size:14px;line-height:1.5;color:#18181b;';
        $layout = $this->bare(['enabled' => true, 'sign_off' => 'Thanks, {{first_name}}', 'align' => 'right']);
        $content = $this->content([$this->heading()]);

        $html = $this->renderHtml($layout, $content);
        $this->assertStringContainsString(
            '<tr><td align="right" style="padding:8px 24px;text-align:right;"><p style="margin:0 0 8px 0;'.$base.'">Thanks, Maria</p><p style="margin:0;'.$base.'"><strong>Alex Reyes</strong></p><p style="margin:0;'.$base.'">Growth Consultant</p><p style="margin:0;'.$base.'">+63 917 123 4567</p></td></tr>',
            $html,
        );

        $noTitle = $this->renderHtml($layout, $content, $this->context(['sender_title' => '  ', 'sender_phone' => '']));
        $this->assertStringNotContainsString('Growth Consultant', $noTitle);
        $this->assertStringContainsString('<strong>Alex Reyes</strong></p></td></tr>', $noTitle);
    }

    public function test_an_empty_signature_renders_no_row(): void
    {
        $layout = $this->bare(['enabled' => true, 'sign_off' => '']);
        $content = $this->content([$this->spacer()]);
        $context = $this->context(['sender_name' => '', 'sender_title' => '', 'sender_phone' => '']);

        $this->assertStringNotContainsString('<p', $this->renderHtml($layout, $content, $context));
        $this->assertSame('', $this->renderText($layout, $content, $context));
    }

    // ── Blocks ───────────────────────────────────────────────────────────────────────────────

    public function test_a_heading_renders_its_level_size_color_and_alignment_with_placeholders_filled(): void
    {
        $html = $this->renderHtml($this->bare(), $this->content([$this->heading(['level' => 2, 'align' => 'center', 'color' => '#AB12CD'])]));

        $this->assertStringContainsString(
            '<tr><td align="center" style="padding:8px 24px;text-align:center;"><h2 style="margin:0;font-family:Arial, Helvetica, sans-serif;font-size:22px;line-height:1.25;font-weight:bold;color:#ab12cd;text-align:center;">Hi Maria,</h2></td></tr>',
            $html,
        );
    }

    public function test_a_text_block_renders_paragraphs_marks_links_breaks_and_lists(): void
    {
        $doc = $this->doc(
            $this->para(
                $this->text('Hello '),
                $this->text('{{business_name}}', [['type' => 'italic'], ['type' => 'bold']]),
                $this->hardBreak(),
                $this->text('Book', [$this->link('https://viteseo.ph/book'), ['type' => 'underline']]),
            ),
            $this->para(),
            $this->bullets($this->item($this->para($this->text('One')), $this->para($this->text('More'))), $this->item($this->para($this->text('Two')))),
            ['type' => 'orderedList', 'attrs' => ['start' => 3, 'type' => null], 'content' => [$this->item($this->para($this->text('Three')))]],
        );
        $html = $this->renderHtml($this->bare(), $this->content([$this->textBlock(['content' => $doc, 'font_size' => 16, 'color' => '#333333'])]));
        $base = 'font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:1.5;color:#333333;';

        $this->assertStringContainsString(
            '<p style="margin:0 0 12px 0;'.$base.'">Hello <strong><em>Santos Bakery</em></strong><br>'
            .'<a href="https://viteseo.ph/book" target="_blank" rel="noopener noreferrer" style="color:#0d9488;text-decoration:underline;"><u>Book</u></a></p>'
            .'<p style="margin:0 0 12px 0;'.$base.'">&nbsp;</p>'
            .'<ul style="margin:0 0 12px 0;padding:0 0 0 24px;'.$base.'"><li style="margin:0;'.$base.'">One<br>More</li><li style="margin:0;'.$base.'">Two</li></ul>'
            .'<ol start="3" style="margin:0;padding:0 0 0 24px;'.$base.'"><li style="margin:0;'.$base.'">Three</li></ol>',
            $html,
        );
    }

    public function test_a_nested_list_sits_inside_its_item(): void
    {
        $doc = $this->doc($this->bullets($this->item($this->para($this->text('Parent')), $this->numbered($this->item($this->para($this->text('Child')))))));

        $html = $this->renderHtml($this->bare(), $this->content([$this->textBlock(['content' => $doc])]));

        $this->assertMatchesRegularExpression('/<li style="[^"]*">Parent<ol style="margin:4px 0 0 0;[^"]*"><li style="[^"]*">Child<\/li><\/ol><\/li><\/ul>/', $html);
    }

    public function test_a_button_is_a_colored_cell_holding_the_link(): void
    {
        $html = $this->renderHtml($this->bare(), $this->content([$this->button(['align' => 'right', 'radius' => 0, 'label' => 'Call {{first_name}}'])]));

        $this->assertStringContainsString(
            '<tr><td align="right" style="padding:8px 24px;text-align:right;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:0 0 0 auto;">'
            .'<tr><td align="center" bgcolor="#0d9488" style="border-radius:0px;background-color:#0d9488;">'
            .'<a href="https://viteseo.ph/book" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 24px;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:20px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:0px;">Call Maria</a>'
            .'</td></tr></table></td></tr>',
            $html,
        );
    }

    public function test_an_image_scales_down_to_the_column_and_uses_the_resolved_asset_url(): void
    {
        $html = $this->renderHtml($this->bare(), $this->content([$this->image(['width' => 300, 'align' => 'left'])]));

        $this->assertStringContainsString(
            '<tr><td align="left" style="padding:8px 24px;text-align:left;"><img src="'.self::ASSET_URL.'" width="300" alt="Our work" style="display:block;width:300px;max-width:100%;height:auto;margin:0 auto 0 0;border:0;outline:none;text-decoration:none;"></td></tr>',
            $html,
        );
    }

    public function test_a_linked_image_is_wrapped_in_its_link(): void
    {
        $html = $this->renderHtml($this->bare(), $this->content([$this->image(['link_url' => 'https://viteseo.ph/work'])]));

        $this->assertStringContainsString('<a href="https://viteseo.ph/work" target="_blank" rel="noopener noreferrer" style="display:block;text-decoration:none;"><img src="'.self::ASSET_URL.'"', $html);
    }

    public function test_the_logo_comes_from_the_context_and_disappears_without_one(): void
    {
        $layout = $this->layout([$this->logo(['width' => 120])], [], signature: ['enabled' => false]);
        $content = $this->content([$this->heading()]);

        $this->assertStringContainsString('<img src="'.self::LOGO_URL.'" width="120" alt="Vite SEO" style="display:block;width:120px;max-width:100%;height:auto;margin:0 auto;', $this->renderHtml($layout, $content));
        $this->assertStringNotContainsString('<img', $this->renderHtml($layout, $content, $this->context(logoUrl: null)));
    }

    public function test_the_logo_url_from_a_local_environment_may_be_plain_http(): void
    {
        $url = 'http://localhost:8001/api/v1/crm/email-assets/logo.png';

        $this->assertStringContainsString('<img src="'.$url.'"', $this->renderHtml($this->layout([$this->logo()], []), $this->content([$this->heading()]), $this->context(logoUrl: $url)));
    }

    public function test_a_divider_and_a_spacer_have_fixed_heights(): void
    {
        $html = $this->renderHtml($this->bare(), $this->content([$this->divider(['thickness' => 2, 'color' => '#CCCCCC']), $this->spacer(['height' => 40])]));

        $this->assertStringContainsString('<tr><td height="2" bgcolor="#cccccc" style="height:2px;font-size:0;line-height:0;background-color:#cccccc;">&nbsp;</td></tr>', $html);
        $this->assertStringContainsString('<tr><td height="40" style="height:40px;font-size:0;line-height:0;">&nbsp;</td></tr>', $html);
    }

    public function test_a_footer_uses_its_own_size_color_and_the_link_color(): void
    {
        $html = $this->renderHtml($this->layout([], [$this->footer()], ['link_color' => '#FF0000'], ['enabled' => false]), $this->content([$this->heading()]));

        $this->assertStringContainsString(
            '<p style="margin:0;font-family:Arial, Helvetica, sans-serif;font-size:12px;line-height:1.5;color:#71717a;">Vite SEO Digital Advertising OPC<br><a href="https://viteseo.ph" target="_blank" rel="noopener noreferrer" style="color:#ff0000;text-decoration:underline;">Visit us</a></p>',
            $html,
        );
    }

    public function test_an_unknown_lowercase_token_in_the_context_map_renders_blank(): void
    {
        $html = $this->renderHtml($this->bare(), $this->content([$this->heading(['text' => 'Hi {{first_name}}!'])]), new EmailDesignContext);

        $this->assertStringContainsString('>Hi !</h1>', $html);
    }

    // ── Plain text ───────────────────────────────────────────────────────────────────────────

    public function test_the_plain_text_part_is_every_part_readable_in_order(): void
    {
        $this->assertSame(
            "Hi Maria,\n\n"
            ."We would like to help Santos Bakery grow online.\n\n"
            ."Book a call: https://viteseo.ph/book\n\n"
            ."----------------------------------------\n\n"
            ."Best regards,\nAlex Reyes\nGrowth Consultant\n+63 917 123 4567\n\n"
            ."----------------------------------------\n\n"
            ."Vite SEO Digital Advertising OPC\nVisit us (https://viteseo.ph)",
            $this->renderText(),
        );
    }

    public function test_plain_text_lists_are_bulleted_numbered_and_indented(): void
    {
        $doc = $this->doc(
            $this->bullets(
                $this->item($this->para($this->text('One'))),
                $this->item($this->para($this->text('Two')), $this->para($this->text('continued')), $this->numbered($this->item($this->para($this->text('Nested'))))),
            ),
            $this->para($this->text('After')),
            ['type' => 'orderedList', 'attrs' => ['start' => 9], 'content' => [$this->item($this->para($this->text('Nine'))), $this->item($this->para($this->text('Ten')))]],
        );

        $this->assertSame(
            "• One\n• Two\n  continued\n  1. Nested\n\nAfter\n\n9. Nine\n10. Ten",
            $this->renderText($this->bare(), $this->content([$this->textBlock(['content' => $doc])])),
        );
    }

    public function test_plain_text_writes_a_link_url_once_after_its_whole_label(): void
    {
        $link = $this->link('https://viteseo.ph/book');
        $doc = $this->doc(
            $this->para($this->text('Please '), $this->text('Book', [$link, ['type' => 'bold']]), $this->text(' a call', [$link]), $this->text(' today.')),
            $this->para($this->text('https://viteseo.ph', [$this->link('https://viteseo.ph')])),
            $this->para($this->text('Email us', [$this->link('mailto:hello@viteseo.ph')])),
        );

        $this->assertSame(
            "Please Book a call (https://viteseo.ph/book) today.\n\nhttps://viteseo.ph\n\nEmail us (mailto:hello@viteseo.ph)",
            $this->renderText($this->bare(), $this->content([$this->textBlock(['content' => $doc])])),
        );
    }

    public function test_plain_text_includes_linked_images_and_skips_decoration(): void
    {
        $layout = $this->layout([$this->logo(['link_url' => 'https://viteseo.ph'])], [], signature: ['enabled' => false]);
        $content = $this->content([
            $this->spacer(),
            $this->image(),
            $this->image(['id' => 'with-alt', 'link_url' => 'https://viteseo.ph/work']),
            $this->image(['id' => 'without-alt', 'alt' => '', 'link_url' => 'https://viteseo.ph/gallery']),
        ]);

        $this->assertSame("Our work: https://viteseo.ph/work\n\nhttps://viteseo.ph/gallery", $this->renderText($layout, $content));
    }

    public function test_plain_text_collapses_empty_paragraphs_to_one_blank_line(): void
    {
        $doc = $this->doc($this->para($this->text('Top')), $this->para(), $this->para(), $this->para(), $this->para($this->text('Bottom')));

        $this->assertSame("Top\n\nBottom", $this->renderText($this->bare(), $this->content([$this->textBlock(['content' => $doc])])));
    }

    public function test_plain_text_contains_no_markup(): void
    {
        $this->assertStringNotContainsString('<', $this->renderText());
    }
}
