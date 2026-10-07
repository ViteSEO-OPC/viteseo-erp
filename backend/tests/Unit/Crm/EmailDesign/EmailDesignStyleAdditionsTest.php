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
 * The optional styles, heading size, secondary button and signature switches added inside schema version 1.
 *
 * Every one is additive: absent, it changes nothing (the golden corpus proves that for stored documents; the
 * first test here proves the defaults ARE the old literals), and present, it changes only what it names.
 */
final class EmailDesignStyleAdditionsTest extends TestCase
{
    use BuildsEmailDesigns;

    private function validator(): EmailDesignValidator
    {
        return new EmailDesignValidator;
    }

    private function html(array $layout, array $content, ?EmailDesignContext $context = null): string
    {
        return (new EmailDesignRenderer)->toHtml($layout, $content, $context ?? $this->context());
    }

    private function plain(array $layout, array $content, EmailDesignContext $context): string
    {
        return (new EmailDesignRenderer)->toText($layout, $content, $context);
    }

    /** A context carrying the signature's optional values. */
    private function signatureContext(string $company = 'Vite SEO Digital Advertising OPC', ?string $email = 'business@viteseo.ph', ?string $phone = '0945 449 3862', array $tokens = []): EmailDesignContext
    {
        $base = $this->context($tokens);

        return new EmailDesignContext(
            tokens: $base->tokens,
            assetUrls: $base->assetUrls,
            logoUrl: $base->logoUrl,
            logoAlt: $base->logoAlt,
            organizationName: $company,
            organizationEmail: $email,
            senderPhone: $phone,
        );
    }

    /** A layout whose signature has the given switches, with no header or footer to search past. */
    private function signed(array $switches, array $signature = []): array
    {
        return $this->layout([], [], [], [...$signature, ...$switches]);
    }

    // ── Defaults are the old literals ────────────────────────────────────────────────────────

    public function test_setting_every_new_style_to_its_default_renders_byte_identically_to_leaving_it_unset(): void
    {
        $defaults = [
            'content_padding' => EmailDesignSchema::CONTENT_PADDING,
            'container_radius' => 0,
            'card_radius' => 12,
            'card_padding' => 24,
            'card_border_width' => 0,
            'card_background_color' => '#F4F4F5',
            'card_border_color' => '#E4E4E7',
            'eyebrow_color' => '#71717A',
            'secondary_color' => '#18181B',
            'secondary_text_color' => '#FFFFFF',
        ];
        $content = $this->content();

        $this->assertSame(
            $this->html($this->layout(), $content),
            $this->html($this->layout(styles: $defaults), $content),
        );
        $this->assertSame(
            $this->html($this->layout(), $content),
            $this->html($this->layout(signature: ['show_company' => false, 'show_email' => false, 'show_phone' => false]), $content),
        );
    }

    public function test_the_numeric_defaults_are_what_the_renderer_wrote_before_they_existed(): void
    {
        $this->assertSame(EmailDesignSchema::CONTENT_PADDING, EmailDesignSchema::NUMERIC_STYLES['content_padding'][0]);
        $this->assertSame(0, EmailDesignSchema::NUMERIC_STYLES['container_radius'][0]);
        $this->assertStringContainsString('padding:8px 24px;', $this->html($this->layout(), $this->content()));
    }

    // ── Validation ───────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{string, mixed}> */
    public static function validStyles(): array
    {
        $cases = [];
        foreach (EmailDesignSchema::NUMERIC_STYLES as $key => [, [$min, $max]]) {
            $cases["{$key} min"] = [$key, $min];
            $cases["{$key} max"] = [$key, $max];
        }
        foreach (array_keys(EmailDesignSchema::ADDITIONAL_COLOR_STYLES) as $key) {
            $cases["{$key} hex"] = [$key, '#1A2b3C'];
            $cases["{$key} null"] = [$key, null];
        }

        return $cases;
    }

    #[DataProvider('validStyles')]
    public function test_a_design_and_a_template_override_accept_the_new_styles(string $key, mixed $value): void
    {
        $this->assertNull($this->validator()->layoutProblem($this->layout(styles: [$key => $value])));
        $this->assertNull($this->validator()->contentProblem($this->content(null, ['style_overrides' => [$key => $value]])));
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidStyles(): array
    {
        $cases = [];
        foreach (EmailDesignSchema::NUMERIC_STYLES as $key => [, [$min, $max]]) {
            $cases["{$key} below"] = [$key, $min - 1];
            $cases["{$key} above"] = [$key, $max + 1];
            $cases["{$key} string"] = [$key, (string) $max];
            $cases["{$key} float"] = [$key, $max - 0.5];
            $cases["{$key} color"] = [$key, '#ffffff'];
        }
        foreach (array_keys(EmailDesignSchema::ADDITIONAL_COLOR_STYLES) as $key) {
            $cases["{$key} short hex"] = [$key, '#fff'];
            $cases["{$key} css"] = [$key, 'red;background:url(x)'];
            $cases["{$key} number"] = [$key, 12];
        }

        return $cases;
    }

    #[DataProvider('invalidStyles')]
    public function test_a_new_style_with_a_bad_value_is_refused_at_its_path(string $key, mixed $value): void
    {
        $this->assertSame("styles.{$key}", $this->validator()->layoutProblem($this->layout(styles: [$key => $value]))['path'] ?? null);
        $this->assertSame("style_overrides.{$key}", $this->validator()->contentProblem($this->content(null, ['style_overrides' => [$key => $value]]))['path'] ?? null);
    }

    public function test_an_unknown_style_key_is_still_refused(): void
    {
        $problem = $this->validator()->layoutProblem($this->layout(styles: ['card_shadow' => '#000000']));

        $this->assertSame(['path' => 'styles.card_shadow', 'message' => EmailDesignValidator::UNKNOWN_FIELD], $problem);
    }

    public function test_signature_switches_must_be_booleans_and_unknown_switches_are_refused(): void
    {
        foreach (EmailDesignSchema::SIGNATURE_OPTIONS as $option) {
            $this->assertNull($this->validator()->layoutProblem($this->signed([$option => true])));
            $this->assertNull($this->validator()->layoutProblem($this->signed([$option => false])));
            foreach (['true', 1, null] as $bad) {
                $this->assertSame(
                    ['path' => "signature.{$option}", 'message' => EmailDesignValidator::INVALID_SWITCH],
                    $this->validator()->layoutProblem($this->signed([$option => $bad])),
                );
            }
        }

        $this->assertSame('signature.show_title', $this->validator()->layoutProblem($this->signed(['show_title' => true]))['path'] ?? null);
    }

    public function test_a_heading_font_size_is_a_whole_number_in_range(): void
    {
        [$min, $max] = EmailDesignSchema::HEADING_FONT_SIZE;
        foreach ([$min, 36, $max, null] as $size) {
            $this->assertNull($this->validator()->contentProblem($this->content([$this->heading(['font_size' => $size])])));
        }
        foreach ([$min - 1, $max + 1, '36', 36.5] as $size) {
            $this->assertSame('blocks.0.font_size', $this->validator()->contentProblem($this->content([$this->heading(['font_size' => $size])]))['path'] ?? null);
        }
    }

    public function test_a_button_variant_is_primary_or_secondary(): void
    {
        foreach (['primary', 'secondary', null] as $variant) {
            $this->assertNull($this->validator()->contentProblem($this->content([$this->button(['variant' => $variant])])));
        }
        $this->assertSame(
            ['path' => 'blocks.0.variant', 'message' => EmailDesignValidator::INVALID_VARIANT],
            $this->validator()->contentProblem($this->content([$this->button(['variant' => 'ghost'])])),
        );
    }

    // ── Rendering: layout ────────────────────────────────────────────────────────────────────

    public function test_content_padding_is_the_side_inset_of_every_block_row(): void
    {
        $html = $this->html($this->layout(styles: ['content_padding' => 48]), $this->content());

        $this->assertStringNotContainsString('padding:8px 24px;', $html);
        $this->assertSame(substr_count($this->html($this->layout(), $this->content()), 'padding:8px 24px;'), substr_count($html, 'padding:8px 48px;'));
    }

    public function test_a_wider_content_padding_narrows_images_to_the_row_they_sit_in(): void
    {
        $html = $this->html($this->layout(styles: ['content_padding' => 48]), $this->content([$this->image(['width' => EmailDesignSchema::INNER_WIDTH])]));

        $this->assertStringContainsString('width="504" alt="Our work" style="display:block;width:504px;', $html);
        $this->assertStringNotContainsString('width="552"', $html);
    }

    public function test_a_narrower_content_padding_keeps_the_chosen_image_width(): void
    {
        $html = $this->html($this->layout(styles: ['content_padding' => 8]), $this->content([$this->image(['width' => EmailDesignSchema::INNER_WIDTH])]));

        $this->assertStringContainsString('width="552"', $html);
        $this->assertStringContainsString('padding:8px 8px;', $html);
    }

    public function test_container_radius_rounds_the_email_and_only_the_email(): void
    {
        $square = $this->html($this->layout(), $this->content());
        $round = $this->html($this->layout(styles: ['container_radius' => 16]), $this->content());

        $this->assertStringNotContainsString('border-radius:16px', $square);
        $this->assertSame(1, substr_count($round, 'border-collapse:separate;border-spacing:0;border-radius:16px;background-color:#ffffff;'));
        // The page table and the inner tables keep collapsing.
        $this->assertSame(substr_count($square, 'border-collapse:collapse;') - 1, substr_count($round, 'border-collapse:collapse;'));
    }

    public function test_a_heading_font_size_replaces_its_levels_size(): void
    {
        $html = $this->html($this->layout([], []), $this->content([
            $this->heading(['id' => 'a', 'font_size' => 36, 'text' => 'Your website quotation.']),
            $this->heading(['id' => 'b', 'level' => 2, 'text' => 'Level two']),
        ]));

        $this->assertStringContainsString('<h1 style="margin:0;font-family:Arial, Helvetica, sans-serif;font-size:36px;', $html);
        $this->assertStringContainsString('<h2 style="margin:0;font-family:Arial, Helvetica, sans-serif;font-size:22px;', $html);
    }

    // ── Rendering: secondary buttons and inheritance ─────────────────────────────────────────

    public function test_a_secondary_button_takes_the_secondary_colors_and_a_primary_one_is_unchanged(): void
    {
        $strip = fn (array $b): array => array_diff_key($b, ['background_color' => 1, 'text_color' => 1]);
        $layout = $this->layout([], [], ['accent_color' => '#0D7490', 'text_color' => '#18181B', 'content_background_color' => '#FFFFFF']);

        $primary = $this->html($layout, $this->content([$strip($this->button())]));
        $explicitPrimary = $this->html($layout, $this->content([$strip($this->button(['variant' => 'primary']))]));
        $secondary = $this->html($layout, $this->content([$strip($this->button(['variant' => 'secondary']))]));

        $this->assertSame($primary, $explicitPrimary);
        $this->assertStringContainsString('bgcolor="#0d7490"', $primary);
        // Secondary falls back to the text color on the email's background: a dark button with a light label.
        $this->assertStringContainsString('bgcolor="#18181b" style="border-radius:6px;background-color:#18181b;"', $secondary);
        $this->assertStringContainsString('color:#ffffff;text-decoration:none;', $secondary);
    }

    public function test_secondary_colors_follow_the_override_design_brand_fallback_chain_and_a_block_color_wins(): void
    {
        $renderer = new EmailDesignRenderer;
        $context = $this->context(brandStyles: ['secondary_color' => '#333333', 'content_padding' => 100, 'card_radius' => 20]);
        $theme = $renderer->effectiveStyles([], [], $context);

        $fromBrand = $renderer->additionalStyles([], [], $context, $theme);
        $this->assertSame('#333333', $fromBrand['secondary_color']);
        $this->assertSame(20, $fromBrand['card_radius']);
        $this->assertSame(EmailDesignSchema::CONTENT_PADDING, $fromBrand['content_padding'], 'An out-of-range brand value is skipped.');

        $fromDesign = $renderer->additionalStyles(['secondary_color' => '#222222', 'card_radius' => 8], [], $context, $theme);
        $this->assertSame('#222222', $fromDesign['secondary_color']);
        $this->assertSame(8, $fromDesign['card_radius']);

        $fromOverride = $renderer->additionalStyles(['secondary_color' => '#222222'], ['secondary_color' => '#111111', 'card_radius' => 0], $context, $theme);
        $this->assertSame('#111111', $fromOverride['secondary_color']);
        $this->assertSame(0, $fromOverride['card_radius']);

        $html = $this->html($this->layout([], [], ['secondary_color' => '#222222']), $this->content([$this->button(['variant' => 'secondary', 'background_color' => '#ABCDEF'])]));
        $this->assertStringContainsString('bgcolor="#abcdef"', $html);
    }

    public function test_unset_additional_colors_take_the_resolved_style_they_name(): void
    {
        $renderer = new EmailDesignRenderer;
        $context = $this->context();
        $theme = $renderer->effectiveStyles(['background_color' => '#EEEEEE', 'divider_color' => '#DDDDDD', 'muted_text_color' => '#777777', 'text_color' => '#111111', 'content_background_color' => '#FAFAFA'], [], $context);
        $extra = $renderer->additionalStyles([], [], $context, $theme);

        $this->assertSame([
            'card_background_color' => '#eeeeee',
            'card_border_color' => '#dddddd',
            'eyebrow_color' => '#777777',
            'secondary_color' => '#111111',
            'secondary_text_color' => '#fafafa',
        ], array_intersect_key($extra, EmailDesignSchema::ADDITIONAL_COLOR_STYLES));
    }

    // ── Rendering: signature switches ────────────────────────────────────────────────────────

    public function test_the_signature_switches_add_the_company_and_a_linked_email_and_phone(): void
    {
        $layout = $this->signed(['show_company' => true, 'show_email' => true, 'show_phone' => true]);
        $html = $this->html($layout, $this->content([$this->textBlock()]), $this->signatureContext());

        $this->assertStringContainsString(
            '<p style="margin:0;font-family:Arial, Helvetica, sans-serif;font-size:14px;line-height:1.5;color:#71717a;">Vite SEO Digital Advertising OPC</p>'
            .'<p style="margin:0;font-family:Arial, Helvetica, sans-serif;font-size:14px;line-height:1.5;color:#18181b;">'
            .'<a href="mailto:business@viteseo.ph" style="color:#0d9488;text-decoration:none;">business@viteseo.ph</a>'
            .' &middot; <a href="tel:09454493862" style="color:#0d9488;text-decoration:none;">0945 449 3862</a></p>',
            $html,
        );

        $text = $this->plain($layout, $this->content([$this->textBlock()]), $this->signatureContext());
        $this->assertStringEndsWith("Best regards,\nAlex Reyes\nGrowth Consultant\nVite SEO Digital Advertising OPC\nbusiness@viteseo.ph · 0945 449 3862", $text);
    }

    public function test_the_switches_are_off_by_default_even_when_the_context_has_the_values(): void
    {
        $content = $this->content([$this->textBlock()]);

        $this->assertSame(
            $this->html($this->layout([], []), $content, $this->context()),
            $this->html($this->layout([], []), $content, $this->signatureContext()),
        );
        $this->assertSame(
            $this->plain($this->layout([], []), $content, $this->context()),
            $this->plain($this->layout([], []), $content, $this->signatureContext()),
        );
    }

    public function test_a_switched_off_signature_shows_none_of_them(): void
    {
        $layout = $this->signed(['enabled' => false, 'show_company' => true, 'show_email' => true, 'show_phone' => true]);
        $html = $this->html($layout, $this->content([$this->textBlock()]), $this->signatureContext());

        $this->assertStringNotContainsString('business@viteseo.ph', $html);
        $this->assertStringNotContainsString('0945', $html);
        $this->assertStringNotContainsString('Digital Advertising OPC', $html);
    }

    public function test_a_switch_with_no_value_is_left_out_gracefully(): void
    {
        $layout = $this->signed(['show_company' => true, 'show_email' => true, 'show_phone' => true]);
        $html = $this->html($layout, $this->content([$this->textBlock()]), $this->signatureContext('', null, null, ['sender_phone' => '']));

        $this->assertStringNotContainsString('mailto:', $html);
        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringNotContainsString('&middot;', $html);
        $this->assertSame(
            $this->html($this->layout([], []), $this->content([$this->textBlock()]), $this->signatureContext('', null, null, ['sender_phone' => ''])),
            $html,
        );
    }

    public function test_the_phone_alone_is_linked_without_a_separator(): void
    {
        $html = $this->html($this->signed(['show_phone' => true]), $this->content([$this->textBlock()]), $this->signatureContext(phone: '+63 (917) 123-4567'));

        $this->assertStringContainsString('<a href="tel:+639171234567" style="color:#0d9488;text-decoration:none;">+63 (917) 123-4567</a></p>', $html);
        $this->assertStringNotContainsString('&middot;', $html);
    }

    public function test_a_phone_that_is_not_a_dialable_number_is_shown_as_escaped_text(): void
    {
        $html = $this->html($this->signed(['show_phone' => true]), $this->content([$this->textBlock()]), $this->signatureContext(phone: '0917 ext. <b>12</b>'));

        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringContainsString('0917 ext. &lt;b&gt;12&lt;/b&gt;</p>', $html);
    }

    public function test_an_address_that_is_not_a_plain_email_is_left_out(): void
    {
        foreach (['not-an-email', 'a@b.c?subject=x', 'x"onmouseover=1@b.co', 'a@b.co&cc=x@y.co', 'javascript:alert(1)//@x.co'] as $email) {
            $html = $this->html($this->signed(['show_email' => true]), $this->content([$this->textBlock()]), $this->signatureContext(email: $email));
            $this->assertStringNotContainsString('mailto:', $html, $email);
        }
    }

    public function test_hostile_company_and_phone_values_are_escaped_and_cleaned(): void
    {
        $html = $this->html(
            $this->signed(['show_company' => true, 'show_phone' => true]),
            $this->content([$this->textBlock()]),
            $this->signatureContext("<script>x</script>\u{202E}Co\x07", phone: "+63\u{2028}917"),
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt; Co</p>', $html);
        $this->assertStringNotContainsString("\u{202E}", $html);
        $this->assertStringNotContainsString("\u{2028}", $html);
    }

    public function test_show_phone_replaces_the_phone_token_line_rather_than_printing_it_twice(): void
    {
        $context = $this->signatureContext(phone: '0945 449 3862', tokens: ['sender_phone' => '+63 917 123 4567']);

        $legacy = $this->html($this->signed([]), $this->content([$this->textBlock()]), $context);
        $this->assertStringContainsString('+63 917 123 4567', $legacy);

        $switched = $this->html($this->signed(['show_phone' => true]), $this->content([$this->textBlock()]), $context);
        $this->assertStringNotContainsString('+63 917 123 4567', $switched);
        $this->assertSame(1, substr_count($switched, '0945 449 3862</a>'));
    }

    public function test_a_sign_off_followed_only_by_new_lines_keeps_its_gap(): void
    {
        $context = new EmailDesignContext(tokens: [], organizationName: 'Acme');
        $html = $this->html($this->signed(['show_company' => true]), $this->content([$this->textBlock()]), $context);

        $this->assertStringContainsString('<p style="margin:0 0 8px 0;font-family:Arial, Helvetica, sans-serif;font-size:14px;line-height:1.5;color:#18181b;">Best regards,</p>', $html);
    }
}
