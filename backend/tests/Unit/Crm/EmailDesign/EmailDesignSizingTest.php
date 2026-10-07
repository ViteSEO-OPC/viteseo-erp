<?php

declare(strict_types=1);

namespace Tests\Unit\Crm\EmailDesign;

use App\Modules\Crm\Support\EmailDesign\EmailDesignContext;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use App\Modules\Crm\Support\EmailDesign\EmailDesignSchema;
use App\Modules\Crm\Support\EmailDesign\EmailDesignValidator;
use PHPUnit\Framework\TestCase;

/**
 * The text-size and spacing styles of cards, rows, the signature and buttons: optional design styles whose
 * defaults are what the renderer wrote before they existed, so only a design that sets one looks different.
 */
final class EmailDesignSizingTest extends TestCase
{
    use BuildsEmailDesigns;

    private const SIZING = [
        'card_eyebrow_size', 'card_title_size', 'row_title_size', 'row_description_size', 'row_padding',
        'signature_size', 'signature_detail_size', 'button_padding_y', 'button_padding_x',
    ];

    private function html(array $styles, array $blocks, ?EmailDesignContext $context = null, array $overrides = []): string
    {
        return (new EmailDesignRenderer)->toHtml(
            $this->layout([], [], $styles, ['show_company' => true, 'show_email' => true, 'show_phone' => true]),
            $this->content($blocks, $overrides === [] ? [] : ['style_overrides' => $overrides]),
            $context ?? $this->signedContext(),
        );
    }

    private function signedContext(): EmailDesignContext
    {
        $base = $this->context();

        return new EmailDesignContext(tokens: $base->tokens, assetUrls: $base->assetUrls, logoUrl: $base->logoUrl, logoAlt: $base->logoAlt,
            organizationName: 'Vite SEO Digital Advertising OPC', organizationEmail: 'business@viteseo.ph', senderPhone: '0945 449 3862');
    }

    /** @return list<array<string, mixed>> */
    private function everything(): array
    {
        return [$this->button(), $this->card(['blocks' => [$this->rows(['id' => 'r']), $this->actions(['id' => 'a'])]])];
    }

    public function test_the_sizing_styles_are_numeric_styles_with_the_old_values_as_defaults(): void
    {
        $this->assertSame(
            ['card_eyebrow_size' => 12, 'card_title_size' => 20, 'row_title_size' => 15, 'row_description_size' => 14, 'row_padding' => 10,
                'signature_size' => 14, 'signature_detail_size' => 14, 'button_padding_y' => 12, 'button_padding_x' => 24],
            array_map(fn (array $style): int => $style[0], array_intersect_key(EmailDesignSchema::NUMERIC_STYLES, array_flip(self::SIZING))),
        );
    }

    public function test_setting_every_sizing_style_to_its_default_renders_byte_identically_to_leaving_it_unset(): void
    {
        $defaults = array_map(fn (array $style): int => $style[0], array_intersect_key(EmailDesignSchema::NUMERIC_STYLES, array_flip(self::SIZING)));

        $this->assertSame($this->html([], $this->everything()), $this->html($defaults, $this->everything()));
    }

    public function test_each_style_is_validated_by_its_range_in_a_design_and_a_template_override(): void
    {
        $validator = new EmailDesignValidator;
        foreach (self::SIZING as $key) {
            [, [$min, $max]] = EmailDesignSchema::NUMERIC_STYLES[$key];
            $this->assertNull($validator->layoutProblem($this->layout(styles: [$key => $min])));
            $this->assertNull($validator->contentProblem($this->content(null, ['style_overrides' => [$key => $max]])));
            $this->assertSame("styles.{$key}", $validator->layoutProblem($this->layout(styles: [$key => $max + 1]))['path'] ?? null);
            $this->assertSame("styles.{$key}", $validator->layoutProblem($this->layout(styles: [$key => (string) $min]))['path'] ?? null);
        }
    }

    public function test_card_and_row_sizes_and_row_padding(): void
    {
        $blocks = [$this->card(['blocks' => [$this->rows(['id' => 'r', 'items' => [['title' => 'Website', 'description' => '5 pages.'], ['title' => 'Payment', 'description' => 'On signing.']]])]])];
        $html = $this->html(['card_eyebrow_size' => 13, 'card_title_size' => 22, 'row_title_size' => 16, 'row_description_size' => 15, 'row_padding' => 14], $blocks);

        $this->assertStringContainsString('font-size:13px;line-height:1.4;font-weight:bold;color:#71717a;">Quotation summary</p>', $html);
        $this->assertStringContainsString('font-size:22px;line-height:1.3;font-weight:bold;color:#18181b;">Basic website. ₱35,000, one-time.</p>', $html);
        $this->assertStringContainsString('font-size:16px;line-height:1.4;font-weight:bold;color:#18181b;">Website</p>', $html);
        $this->assertStringContainsString('font-size:15px;line-height:1.5;color:#71717a;">5 pages.</p>', $html);
        $this->assertStringContainsString('style="padding:0px 0 14px 0;text-align:center;">', $html);
        $this->assertStringContainsString('style="padding:14px 0 0px 0;text-align:center;">', $html);
    }

    public function test_signature_sizes_split_the_sign_off_and_name_from_the_details(): void
    {
        $html = $this->html(['signature_size' => 17, 'signature_detail_size' => 15], [$this->textBlock()]);

        $this->assertStringContainsString('font-size:17px;line-height:1.5;color:#18181b;">Best regards,</p>', $html);
        $this->assertStringContainsString('font-size:17px;line-height:1.5;color:#18181b;"><strong>Alex Reyes</strong></p>', $html);
        $this->assertStringContainsString('font-size:15px;line-height:1.5;color:#18181b;">Growth Consultant</p>', $html);
        $this->assertStringContainsString('font-size:15px;line-height:1.5;color:#71717a;">Vite SEO Digital Advertising OPC</p>', $html);
        $this->assertStringContainsString('font-size:15px;line-height:1.5;color:#18181b;"><a href="mailto:business@viteseo.ph"', $html);
    }

    public function test_button_padding_sizes_button_blocks_action_buttons_and_lines_up_action_links(): void
    {
        $html = $this->html(['button_padding_y' => 14, 'button_padding_x' => 28], [$this->button(), $this->actions()]);

        $this->assertSame(2, substr_count($html, 'style="display:inline-block;padding:14px 28px;'));
        $this->assertStringNotContainsString('padding:12px 24px', $html);
        $this->assertStringContainsString('style="display:inline-block;padding:14px 0;', $html, 'An action link stays as tall as the buttons beside it.');
    }

    public function test_a_template_override_wins_over_the_design_and_an_unset_one_inherits_it(): void
    {
        $design = ['row_title_size' => 16, 'button_padding_y' => 14];
        $blocks = [$this->button(), $this->rows()];

        $inherited = $this->html($design, $blocks);
        $this->assertStringContainsString('font-size:16px;line-height:1.4;font-weight:bold', $inherited);
        $this->assertStringContainsString('padding:14px 24px;', $inherited);

        $overridden = $this->html($design, $blocks, null, ['row_title_size' => 18, 'button_padding_y' => 10]);
        $this->assertStringContainsString('font-size:18px;line-height:1.4;font-weight:bold', $overridden);
        $this->assertStringContainsString('padding:10px 24px;', $overridden);
    }

    public function test_an_out_of_range_brand_value_is_ignored(): void
    {
        $renderer = new EmailDesignRenderer;
        $context = $this->context(brandStyles: ['row_padding' => 99, 'card_title_size' => 'big']);
        $extra = $renderer->additionalStyles([], [], $context, $renderer->effectiveStyles([], [], $context));

        $this->assertSame(10, $extra['row_padding']);
        $this->assertSame(20, $extra['card_title_size']);
    }
}
