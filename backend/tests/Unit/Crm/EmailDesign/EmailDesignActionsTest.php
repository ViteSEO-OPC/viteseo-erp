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
 * The actions block — calls to action side by side, any mix of buttons and links — and the `link_underline`
 * style. Both are generic: what an action says and where it goes is content; how it looks is the design's.
 */
final class EmailDesignActionsTest extends TestCase
{
    use BuildsEmailDesigns;

    private function problem(array $blocks): ?array
    {
        return (new EmailDesignValidator)->contentProblem($this->content($blocks));
    }

    private function html(array $blocks, array $styles = [], ?EmailDesignContext $context = null, array $footer = []): string
    {
        return (new EmailDesignRenderer)->toHtml($this->layout([], $footer, $styles, ['enabled' => false]), $this->content($blocks), $context ?? $this->context());
    }

    private function plain(array $blocks, ?EmailDesignContext $context = null): string
    {
        return (new EmailDesignRenderer)->toText($this->layout([], [], [], ['enabled' => false]), $this->content($blocks), $context ?? $this->context());
    }

    private function actionButton(string $label, string $url, ?string $variant = null): array
    {
        return ['kind' => 'button', 'label' => $label, 'url' => $url, ...($variant === null ? [] : ['variant' => $variant])];
    }

    private function actionLink(string $label, string $url): array
    {
        return ['kind' => 'link', 'label' => $label, 'url' => $url];
    }

    // ── Validation ───────────────────────────────────────────────────────────────────────────

    public function test_actions_may_sit_in_content_a_card_and_the_footer_but_not_the_header(): void
    {
        $validator = new EmailDesignValidator;

        $this->assertNull($this->problem([$this->actions()]));
        $this->assertNull($this->problem([$this->card(['blocks' => [$this->actions(['id' => 'inner'])]])]));
        $this->assertNull($validator->layoutProblem($this->layout([], [$this->actions()])));
        $this->assertSame(EmailDesignValidator::BLOCK_NOT_ALLOWED, $validator->layoutProblem($this->layout([$this->actions()], []))['message'] ?? null);
    }

    public function test_one_to_three_items_in_a_list(): void
    {
        $item = $this->actionLink('More', 'https://viteseo.ph');
        $this->assertSame(['path' => 'blocks.0.items', 'message' => EmailDesignValidator::NO_ACTIONS], $this->problem([$this->actions(['items' => []])]));
        $this->assertSame(['path' => 'blocks.0.items', 'message' => EmailDesignValidator::MALFORMED], $this->problem([$this->actions(['items' => ['a' => $item]])]));
        $this->assertNull($this->problem([$this->actions(['items' => array_fill(0, EmailDesignSchema::MAX_ACTION_ITEMS, $item)])]));
        $this->assertSame(
            ['path' => 'blocks.0.items', 'message' => sprintf(EmailDesignValidator::TOO_MANY_ACTIONS, EmailDesignSchema::MAX_ACTION_ITEMS)],
            $this->problem([$this->actions(['items' => array_fill(0, EmailDesignSchema::MAX_ACTION_ITEMS + 1, $item)])]),
        );
    }

    /** @return array<string, array{mixed, string, string}> */
    public static function badItems(): array
    {
        return [
            'not an object' => ['More', 'blocks.0.items.0', EmailDesignValidator::MALFORMED],
            'no kind' => [['label' => 'More', 'url' => 'https://viteseo.ph'], 'blocks.0.items.0.kind', EmailDesignValidator::MISSING_FIELD],
            'unknown kind' => [['kind' => 'image', 'label' => 'More', 'url' => 'https://viteseo.ph'], 'blocks.0.items.0.kind', EmailDesignValidator::INVALID_ACTION_KIND],
            'no label' => [['kind' => 'link', 'url' => 'https://viteseo.ph'], 'blocks.0.items.0.label', EmailDesignValidator::MISSING_FIELD],
            'blank label' => [['kind' => 'button', 'label' => '  ', 'url' => 'https://viteseo.ph'], 'blocks.0.items.0.label', EmailDesignValidator::TEXT_REQUIRED],
            'long label' => [['kind' => 'link', 'label' => str_repeat('a', EmailDesignSchema::MAX_BUTTON_LABEL + 1), 'url' => 'https://viteseo.ph'], 'blocks.0.items.0.label', sprintf(EmailDesignValidator::TEXT_TOO_LONG, EmailDesignSchema::MAX_BUTTON_LABEL)],
            'javascript url' => [['kind' => 'link', 'label' => 'x', 'url' => 'javascript:alert(1)'], 'blocks.0.items.0.url', ''],
            'http url' => [['kind' => 'button', 'label' => 'x', 'url' => 'http://viteseo.ph'], 'blocks.0.items.0.url', ''],
            'placeholder in url' => [['kind' => 'link', 'label' => 'x', 'url' => 'https://viteseo.ph/{{first_name}}'], 'blocks.0.items.0.url', ''],
            'a link with a variant' => [['kind' => 'link', 'label' => 'x', 'url' => 'https://viteseo.ph', 'variant' => 'secondary'], 'blocks.0.items.0.variant', EmailDesignValidator::UNKNOWN_FIELD],
            'unknown variant' => [['kind' => 'button', 'label' => 'x', 'url' => 'https://viteseo.ph', 'variant' => 'ghost'], 'blocks.0.items.0.variant', EmailDesignValidator::INVALID_VARIANT],
            'unknown field' => [['kind' => 'button', 'label' => 'x', 'url' => 'https://viteseo.ph', 'color' => '#ffffff'], 'blocks.0.items.0.color', EmailDesignValidator::UNKNOWN_FIELD],
            'unknown placeholder' => [['kind' => 'button', 'label' => 'Call {{secret}}', 'url' => 'https://viteseo.ph'], 'blocks.0.items.0.label', ''],
        ];
    }

    #[DataProvider('badItems')]
    public function test_a_bad_item_is_refused_at_its_path(mixed $item, string $path, string $message): void
    {
        $problem = $this->problem([$this->actions(['items' => [$item]])]);

        $this->assertSame($path, $problem['path'] ?? null);
        if ($message !== '') {
            $this->assertSame($message, $problem['message'] ?? null);
        }
    }

    public function test_the_block_needs_its_alignment_and_a_button_radius_in_range(): void
    {
        $this->assertSame('blocks.0.align', $this->problem([$this->actions(['align' => 'justify'])])['path'] ?? null);
        $this->assertSame('blocks.0.radius', $this->problem([$this->actions(['radius' => 25])])['path'] ?? null);
        $this->assertSame('blocks.0.radius', $this->problem([array_diff_key($this->actions(), ['radius' => 1])])['path'] ?? null);
        $this->assertSame('blocks.0.color', $this->problem([$this->actions(['color' => '#000000'])])['path'] ?? null);
    }

    public function test_a_valid_mix_of_buttons_and_links(): void
    {
        $this->assertNull($this->problem([$this->actions(['items' => [
            $this->actionButton('Book a call', 'https://viteseo.ph/book'),
            $this->actionButton('Email {{first_name}}', 'mailto:hello@viteseo.ph', 'secondary'),
            $this->actionLink('Call us ›', 'tel:+639171234567'),
        ]])]));
    }

    // ── Rendering ────────────────────────────────────────────────────────────────────────────

    public function test_a_button_and_a_link_share_one_line_and_wrap_on_a_narrow_screen(): void
    {
        $html = $this->html([$this->actions()], ['secondary_color' => '#1C1C1E', 'secondary_text_color' => '#FFFFFF', 'link_color' => '#0E6E86']);

        $this->assertStringContainsString(
            '<tr><td align="center" style="padding:8px 24px;text-align:center;">'
            .'<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><![endif]-->'
            .'<!--[if mso]><td style="padding:0 6px;"><![endif]--><div style="display:inline-block;vertical-align:middle;margin:4px 6px;">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:0;">'
            .'<tr><td align="center" bgcolor="#1c1c1e" style="border-radius:24px;background-color:#1c1c1e;">'
            .'<a href="mailto:hello@viteseo.ph" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 24px;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:20px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:24px;">Contact us</a>'
            .'</td></tr></table></div><!--[if mso]></td><![endif]-->'
            .'<!--[if mso]><td style="padding:0 6px;"><![endif]--><div style="display:inline-block;vertical-align:middle;margin:4px 6px;">'
            .'<a href="https://viteseo.ph/work" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 0;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:20px;color:#0e6e86;text-decoration:underline;">See our portfolio ›</a>'
            .'</div><!--[if mso]></td><![endif]-->'
            .'<!--[if mso]></tr></table><![endif]--></td></tr>',
            $html,
        );
    }

    public function test_buttons_take_the_accent_or_secondary_colors_of_the_design(): void
    {
        $html = $this->html([$this->actions(['items' => [$this->actionButton('One', 'https://viteseo.ph/1'), $this->actionButton('Two', 'https://viteseo.ph/2', 'secondary')]])],
            ['accent_color' => '#0E6E86', 'accent_text_color' => '#FAFAFA', 'secondary_color' => '#222222', 'secondary_text_color' => '#EEEEEE']);

        $this->assertStringContainsString('bgcolor="#0e6e86"', $html);
        $this->assertStringContainsString('color:#fafafa;text-decoration:none;border-radius:24px;">One</a>', $html);
        $this->assertStringContainsString('bgcolor="#222222"', $html);
        $this->assertStringContainsString('color:#eeeeee;text-decoration:none;border-radius:24px;">Two</a>', $html);
    }

    public function test_the_block_radius_shapes_every_button_in_it(): void
    {
        $html = $this->html([$this->actions(['radius' => 0, 'items' => [$this->actionButton('A', 'https://viteseo.ph/a'), $this->actionButton('B', 'https://viteseo.ph/b')]])]);

        $this->assertSame(4, substr_count($html, 'border-radius:0px;'));
    }

    public function test_alignment_places_the_row_and_its_outlook_table(): void
    {
        $html = $this->html([$this->actions(['align' => 'left'])]);

        $this->assertStringContainsString('<tr><td align="left" style="padding:8px 24px;text-align:left;"><!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="left">', $html);
    }

    public function test_an_item_blank_after_substitution_is_left_out_and_a_blank_row_draws_nothing(): void
    {
        $context = $this->context(['city' => '']);
        $html = $this->html([$this->actions(['items' => [$this->actionLink('{{city}}', 'https://viteseo.ph/a'), $this->actionLink('Kept', 'https://viteseo.ph/b')]])], [], $context);

        $this->assertSame(1, substr_count($html, 'display:inline-block;vertical-align:middle'));
        $this->assertStringNotContainsString('viteseo.ph/a', $html);
        $this->assertSame(
            $this->html([$this->textBlock()], [], $context),
            $this->html([$this->textBlock(), $this->actions(['items' => [$this->actionLink('{{city}}', 'https://viteseo.ph/a')]])], [], $context),
        );
    }

    public function test_labels_and_placeholder_values_are_escaped_and_substituted_once(): void
    {
        $context = $this->context(['first_name' => '<script>x</script>{{city}}', 'city' => 'Manila']);
        $html = $this->html([$this->actions(['items' => [$this->actionButton('Hi {{first_name}} "&"', 'https://viteseo.ph/a?x=1&y=2'), $this->actionLink('Go now', 'https://viteseo.ph/b')]])], [], $context);

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('>Hi &lt;script&gt;x&lt;/script&gt;{{city}} &quot;&amp;&quot;</a>', $html);
        $this->assertStringContainsString('href="https://viteseo.ph/a?x=1&amp;y=2"', $html);
    }

    public function test_actions_render_inside_a_card_and_in_the_footer(): void
    {
        $inCard = $this->html([$this->card(['blocks' => [$this->actions(['id' => 'inner'])]])], ['card_padding' => 16]);
        $this->assertStringContainsString('<tr><td align="center" style="padding:8px 16px;text-align:center;"><!--[if mso]><table', $inCard);

        $inFooter = $this->html([$this->textBlock()], [], null, [$this->actions(['id' => 'footer-actions'])]);
        $this->assertStringContainsString('See our portfolio ›</a>', $inFooter);
    }

    public function test_the_text_part_lists_each_action_with_its_link(): void
    {
        $text = $this->plain([$this->textBlock(['content' => $this->doc($this->para($this->text('Thanks.')))]), $this->actions()]);

        $this->assertSame("Thanks.\n\nContact us: mailto:hello@viteseo.ph\nSee our portfolio ›: https://viteseo.ph/work", $text);
    }

    // ── link_underline ───────────────────────────────────────────────────────────────────────

    public function test_link_underline_must_be_a_boolean(): void
    {
        $validator = new EmailDesignValidator;
        foreach ([true, false, null] as $value) {
            $this->assertNull($validator->layoutProblem($this->layout(styles: ['link_underline' => $value])));
            $this->assertNull($validator->contentProblem($this->content(null, ['style_overrides' => ['link_underline' => $value]])));
        }
        foreach (['false', 0, 1, 'none'] as $value) {
            $this->assertSame(
                ['path' => 'styles.link_underline', 'message' => EmailDesignValidator::INVALID_SWITCH],
                $validator->layoutProblem($this->layout(styles: ['link_underline' => $value])),
            );
        }
    }

    public function test_unset_or_true_link_underline_renders_byte_identically_to_before(): void
    {
        $blocks = [$this->textBlock(['content' => $this->doc($this->para($this->text('Visit', [$this->link('https://viteseo.ph')])))]), $this->actions()];
        $before = $this->html($blocks);

        $this->assertSame($before, $this->html($blocks, ['link_underline' => true]));
        $this->assertStringContainsString('color:#0d9488;text-decoration:underline;">Visit</a>', $before);
    }

    public function test_link_underline_false_removes_the_underline_from_text_footer_and_action_links_only(): void
    {
        $footer = [$this->footer()];
        $blocks = [$this->textBlock(['content' => $this->doc($this->para($this->text('Visit', [$this->link('https://viteseo.ph')])))]), $this->actions()];
        $html = $this->html($blocks, ['link_underline' => false], null, $footer);

        $this->assertStringNotContainsString('text-decoration:underline', $html);
        $this->assertStringContainsString('color:#0d9488;text-decoration:none;">Visit</a>', $html);
        $this->assertStringContainsString('color:#0d9488;text-decoration:none;">See our portfolio ›</a>', $html);
        $this->assertStringContainsString('color:#0d9488;text-decoration:none;">Visit us</a>', $html, 'The footer link too.');
        // Buttons were never underlined, and still are not.
        $this->assertStringContainsString('text-decoration:none;border-radius:24px;">Contact us</a>', $html);
    }

    public function test_link_underline_follows_the_override_design_brand_chain(): void
    {
        $renderer = new EmailDesignRenderer;
        $theme = $renderer->effectiveStyles([], [], $this->context());

        $this->assertTrue($renderer->additionalStyles([], [], $this->context(), $theme)['link_underline']);
        $this->assertFalse($renderer->additionalStyles([], [], $this->context(brandStyles: ['link_underline' => false]), $theme)['link_underline']);
        $this->assertTrue($renderer->additionalStyles(['link_underline' => true], [], $this->context(brandStyles: ['link_underline' => false]), $theme)['link_underline']);
        $this->assertFalse($renderer->additionalStyles(['link_underline' => true], ['link_underline' => false], $this->context(), $theme)['link_underline']);
        $this->assertTrue($renderer->additionalStyles([], [], $this->context(brandStyles: ['link_underline' => 'no']), $theme)['link_underline'], 'A non-boolean is skipped.');
    }
}
