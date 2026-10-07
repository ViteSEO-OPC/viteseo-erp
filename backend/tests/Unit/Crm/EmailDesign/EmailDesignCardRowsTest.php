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
 * The card and rows blocks: a framed panel that holds blocks one level deep, and a list of title/description
 * lines — the parts a quotation or proposal email is built from.
 */
final class EmailDesignCardRowsTest extends TestCase
{
    use BuildsEmailDesigns;

    private function problem(array $blocks): ?array
    {
        return (new EmailDesignValidator)->contentProblem($this->content($blocks));
    }

    private function html(array $blocks, array $styles = [], ?EmailDesignContext $context = null): string
    {
        return (new EmailDesignRenderer)->toHtml($this->layout([], [], $styles, ['enabled' => false]), $this->content($blocks), $context ?? $this->context());
    }

    private function plain(array $blocks, ?EmailDesignContext $context = null): string
    {
        return (new EmailDesignRenderer)->toText($this->layout([], [], [], ['enabled' => false]), $this->content($blocks), $context ?? $this->context());
    }

    private function uncoloredCard(array $overrides = []): array
    {
        return array_diff_key($this->card($overrides), ['background_color' => 1, 'border_color' => 1]);
    }

    private function uncoloredRows(array $overrides = []): array
    {
        return array_diff_key($this->rows($overrides), ['title_color' => 1, 'description_color' => 1, 'divider_color' => 1]);
    }

    /** n distinct text blocks. */
    private function texts(int $count, string $prefix): array
    {
        return array_map(fn (int $i): array => $this->textBlock(['id' => "{$prefix}{$i}"]), range(1, $count));
    }

    // ── Schema ───────────────────────────────────────────────────────────────────────────────

    public function test_the_fixtures_are_valid_and_cover_what_a_card_may_hold(): void
    {
        $this->assertNull($this->problem([$this->card()]));
        $this->assertNull($this->problem([$this->rows()]));
        $this->assertSame(['heading', 'text', 'rows', 'divider', 'button', 'spacer'], EmailDesignSchema::CARD_BLOCKS);

        $everything = $this->card(['blocks' => [
            $this->heading(['id' => 'i-h']), $this->textBlock(['id' => 'i-t']), $this->rows(['id' => 'i-r']),
            $this->divider(['id' => 'i-d']), $this->button(['id' => 'i-b']), $this->spacer(['id' => 'i-s']),
        ]]);
        $this->assertNull($this->problem([$everything]));
    }

    public function test_cards_and_rows_belong_to_content_only(): void
    {
        $validator = new EmailDesignValidator;
        foreach ([$this->card(), $this->rows()] as $block) {
            $this->assertSame(EmailDesignValidator::BLOCK_NOT_ALLOWED, $validator->layoutProblem($this->layout([$block], []))['message'] ?? null);
            $this->assertSame(EmailDesignValidator::BLOCK_NOT_ALLOWED, $validator->layoutProblem($this->layout([], [$block]))['message'] ?? null);
        }
    }

    // ── Nesting ──────────────────────────────────────────────────────────────────────────────

    public function test_a_card_cannot_hold_a_card(): void
    {
        $this->assertSame(
            ['path' => 'blocks.0.blocks.0.type', 'message' => EmailDesignValidator::BLOCK_NOT_ALLOWED],
            $this->problem([$this->card(['blocks' => [$this->card(['id' => 'inner'])]])]),
        );
    }

    #[DataProvider('notInACard')]
    public function test_a_card_holds_only_card_blocks(string $type): void
    {
        $this->assertSame(
            ['path' => 'blocks.0.blocks.0.type', 'message' => EmailDesignValidator::BLOCK_NOT_ALLOWED],
            $this->problem([$this->card(['blocks' => [$this->block($type, ['id' => 'inner'])]])]),
        );
    }

    /** @return array<string, array{string}> */
    public static function notInACard(): array
    {
        return ['image' => ['image'], 'logo' => ['logo'], 'footer' => ['footer'], 'card' => ['card']];
    }

    public function test_a_card_needs_one_to_twenty_blocks_in_a_list(): void
    {
        $this->assertSame(['path' => 'blocks.0.blocks', 'message' => EmailDesignValidator::NO_BLOCKS], $this->problem([$this->card(['blocks' => []])]));
        $this->assertSame(['path' => 'blocks.0.blocks', 'message' => EmailDesignValidator::MALFORMED], $this->problem([$this->card(['blocks' => ['a' => $this->textBlock()]])]));
        $this->assertSame(['path' => 'blocks.0.blocks', 'message' => EmailDesignValidator::MALFORMED], $this->problem([$this->card(['blocks' => 'text'])]));
        $this->assertNull($this->problem([$this->card(['blocks' => $this->texts(EmailDesignSchema::MAX_CARD_BLOCKS, 'i')])]));
        $this->assertSame(
            ['path' => 'blocks.0.blocks', 'message' => sprintf(EmailDesignValidator::TOO_MANY_BLOCKS, EmailDesignSchema::MAX_CARD_BLOCKS)],
            $this->problem([$this->card(['blocks' => $this->texts(EmailDesignSchema::MAX_CARD_BLOCKS + 1, 'i')])]),
        );
    }

    public function test_a_cards_blocks_count_toward_the_content_limit(): void
    {
        $max = EmailDesignSchema::MAX_CONTENT_BLOCKS;
        $card = fn (int $inner): array => $this->card(['blocks' => $this->texts($inner, 'i')]);

        // 30 outside + 1 card + 19 inside = 50.
        $this->assertNull($this->problem([...$this->texts(30, 'o'), $card(19)]));
        // 30 outside + 1 card + 20 inside = 51.
        $this->assertSame(
            ['path' => 'blocks', 'message' => sprintf(EmailDesignValidator::TOO_MANY_BLOCKS, $max)],
            $this->problem([...$this->texts(30, 'o'), $card(20)]),
        );
    }

    public function test_ids_are_unique_across_the_document_including_cards(): void
    {
        $this->assertSame(
            ['path' => 'blocks.1.blocks.0.id', 'message' => EmailDesignValidator::DUPLICATE_ID],
            $this->problem([$this->textBlock(['id' => 'same']), $this->card(['blocks' => [$this->textBlock(['id' => 'same'])]])]),
        );
        $this->assertSame(
            ['path' => 'blocks.0.blocks.1.id', 'message' => EmailDesignValidator::DUPLICATE_ID],
            $this->problem([$this->card(['blocks' => [$this->textBlock(['id' => 'x']), $this->divider(['id' => 'x'])]])]),
        );
        $this->assertSame(
            ['path' => 'blocks.0.blocks.0.id', 'message' => EmailDesignValidator::DUPLICATE_ID],
            $this->problem([$this->card(['id' => 'x', 'blocks' => [$this->textBlock(['id' => 'x'])]])]),
        );
        $this->assertSame(
            ['path' => 'blocks.1.id', 'message' => EmailDesignValidator::DUPLICATE_ID],
            $this->problem([$this->card(['blocks' => [$this->textBlock(['id' => 'x'])]]), $this->textBlock(['id' => 'x'])]),
        );
        $this->assertSame(
            ['path' => 'blocks.0.blocks.0.id', 'message' => EmailDesignValidator::INVALID_ID],
            $this->problem([$this->card(['blocks' => [$this->textBlock(['id' => 'bad id!'])]])]),
        );
    }

    public function test_a_cards_inner_block_is_validated_like_any_block_and_reports_its_path(): void
    {
        $this->assertSame('blocks.0.blocks.1.url', $this->problem([$this->card(['blocks' => [
            $this->textBlock(['id' => 'a']), $this->button(['id' => 'b', 'url' => 'javascript:alert(1)']),
        ]])])['path'] ?? null);
        $this->assertSame('blocks.0.blocks.0.items.0.title', $this->problem([$this->card(['blocks' => [
            $this->rows(['id' => 'r', 'items' => [['title' => '']]]),
        ]])])['path'] ?? null);
    }

    public function test_deep_nesting_inside_a_card_is_refused_before_it_is_walked(): void
    {
        $deep = ['type' => 'paragraph'];
        for ($i = 0; $i < EmailDesignSchema::MAX_DEPTH; $i++) {
            $deep = ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$deep]]]];
        }
        $problem = $this->problem([$this->card(['blocks' => [$this->textBlock(['content' => ['type' => 'doc', 'content' => [$deep]]])]])]);

        $this->assertSame(['path' => '', 'message' => EmailDesignValidator::TOO_DEEP], $problem);
    }

    public function test_an_oversized_document_of_cards_is_refused_before_it_is_decoded(): void
    {
        $long = str_repeat('x', EmailDesignSchema::MAX_ROW_DESCRIPTION);
        $rows = $this->rows(['items' => array_fill(0, EmailDesignSchema::MAX_ROWS_ITEMS, ['title' => 'T', 'description' => $long])]);
        $json = (string) json_encode($this->content([
            $this->card(['id' => 'c1', 'blocks' => [[...$rows, 'id' => 'r1']]]),
            $this->card(['id' => 'c2', 'blocks' => [[...$rows, 'id' => 'r2']]]),
            $this->card(['id' => 'c3', 'blocks' => [[...$rows, 'id' => 'r3']]]),
            $this->card(['id' => 'c4', 'blocks' => [[...$rows, 'id' => 'r4']]]),
        ]));

        $this->assertGreaterThan(EmailDesignSchema::MAX_BYTES, strlen($json));
        $this->assertSame(EmailDesignValidator::TOO_LARGE, (new EmailDesignValidator)->contentProblemInJson($json)['message'] ?? null);
    }

    // ── Card fields ──────────────────────────────────────────────────────────────────────────

    public function test_a_card_eyebrow_and_title_are_optional_bounded_text_lines(): void
    {
        $this->assertNull($this->problem([array_diff_key($this->card(), ['eyebrow' => 1, 'title' => 1])]));
        $this->assertNull($this->problem([$this->card(['eyebrow' => null, 'title' => '', 'align' => 'left'])]));
        $this->assertNull($this->problem([$this->card(['title' => 'For {{business_name}} in {{city}}'])]));

        $this->assertSame('blocks.0.eyebrow', $this->problem([$this->card(['eyebrow' => str_repeat('a', EmailDesignSchema::MAX_CARD_EYEBROW + 1)])])['path'] ?? null);
        $this->assertSame('blocks.0.title', $this->problem([$this->card(['title' => str_repeat('a', EmailDesignSchema::MAX_CARD_TITLE + 1)])])['path'] ?? null);
        $this->assertSame('blocks.0.title', $this->problem([$this->card(['title' => 'Hi {{password}}'])])['path'] ?? null);
        $this->assertSame('blocks.0.title', $this->problem([$this->card(['title' => ['nested']])])['path'] ?? null);
        $this->assertSame('blocks.0.align', $this->problem([$this->card(['align' => 'justify'])])['path'] ?? null);
        $this->assertSame('blocks.0.background_color', $this->problem([$this->card(['background_color' => 'red'])])['path'] ?? null);
        $this->assertSame('blocks.0.border_color', $this->problem([$this->card(['border_color' => '#fff'])])['path'] ?? null);
        $this->assertSame('blocks.0.padding', $this->problem([$this->card(['padding' => 40])])['path'] ?? null);
        $this->assertSame(['path' => 'blocks.0.blocks', 'message' => EmailDesignValidator::MISSING_FIELD], $this->problem([array_diff_key($this->card(), ['blocks' => 1])]));
    }

    // ── Rows fields ──────────────────────────────────────────────────────────────────────────

    public function test_rows_need_one_to_thirty_items_in_a_list(): void
    {
        $item = ['title' => 'Website'];
        $this->assertSame(['path' => 'blocks.0.items', 'message' => EmailDesignValidator::NO_ROWS], $this->problem([$this->rows(['items' => []])]));
        $this->assertSame(['path' => 'blocks.0.items', 'message' => EmailDesignValidator::MALFORMED], $this->problem([$this->rows(['items' => ['a' => $item]])]));
        $this->assertNull($this->problem([$this->rows(['items' => array_fill(0, EmailDesignSchema::MAX_ROWS_ITEMS, $item)])]));
        $this->assertSame(
            ['path' => 'blocks.0.items', 'message' => sprintf(EmailDesignValidator::TOO_MANY_ROWS, EmailDesignSchema::MAX_ROWS_ITEMS)],
            $this->problem([$this->rows(['items' => array_fill(0, EmailDesignSchema::MAX_ROWS_ITEMS + 1, $item)])]),
        );
    }

    /** @return array<string, array{mixed, string, string}> */
    public static function badItems(): array
    {
        return [
            'not an object' => ['Website', 'blocks.0.items.0', EmailDesignValidator::MALFORMED],
            'a list' => [['Website'], 'blocks.0.items.0', EmailDesignValidator::MALFORMED],
            'no title' => [['description' => 'x'], 'blocks.0.items.0.title', EmailDesignValidator::MISSING_FIELD],
            'blank title' => [['title' => '   '], 'blocks.0.items.0.title', EmailDesignValidator::TEXT_REQUIRED],
            'unknown field' => [['title' => 'x', 'price' => 100], 'blocks.0.items.0.price', EmailDesignValidator::UNKNOWN_FIELD],
            'title not text' => [['title' => 35000], 'blocks.0.items.0.title', EmailDesignValidator::MALFORMED],
            'long title' => [['title' => str_repeat('a', EmailDesignSchema::MAX_ROW_TITLE + 1)], 'blocks.0.items.0.title', sprintf(EmailDesignValidator::TEXT_TOO_LONG, EmailDesignSchema::MAX_ROW_TITLE)],
            'long description' => [['title' => 'x', 'description' => str_repeat('a', EmailDesignSchema::MAX_ROW_DESCRIPTION + 1)], 'blocks.0.items.0.description', sprintf(EmailDesignValidator::TEXT_TOO_LONG, EmailDesignSchema::MAX_ROW_DESCRIPTION)],
        ];
    }

    #[DataProvider('badItems')]
    public function test_a_bad_rows_item_is_refused_at_its_path(mixed $item, string $path, string $message): void
    {
        $this->assertSame(['path' => $path, 'message' => $message], $this->problem([$this->rows(['items' => [$item]])]));
    }

    public function test_row_text_follows_the_placeholder_and_character_rules(): void
    {
        $this->assertNull($this->problem([$this->rows(['items' => [['title' => 'For {{business_name}}', 'description' => 'In {{city}}']]])]));
        $this->assertSame('blocks.0.items.0.title', $this->problem([$this->rows(['items' => [['title' => '{{unknown}}']]])])['path'] ?? null);
        $this->assertSame('blocks.0.items.0.description', $this->problem([$this->rows(['items' => [['title' => 'x', 'description' => 'a {{ b']]])])['path'] ?? null);
        $this->assertSame('blocks.0.items.0.title', $this->problem([$this->rows(['items' => [['title' => "a\x00b"]]])])['path'] ?? null);
    }

    public function test_rows_dividers_must_be_a_boolean_and_colors_hex(): void
    {
        $this->assertSame(['path' => 'blocks.0.dividers', 'message' => EmailDesignValidator::INVALID_SWITCH], $this->problem([$this->rows(['dividers' => 'yes'])]));
        $this->assertSame(['path' => 'blocks.0.dividers', 'message' => EmailDesignValidator::MISSING_FIELD], $this->problem([array_diff_key($this->rows(), ['dividers' => 1])]));
        foreach (['title_color', 'description_color', 'divider_color'] as $field) {
            $this->assertSame("blocks.0.{$field}", $this->problem([$this->rows([$field => 'blue'])])['path'] ?? null);
        }
    }

    // ── Rendering: card ──────────────────────────────────────────────────────────────────────

    public function test_a_card_is_a_framed_panel_in_the_design_card_style_holding_its_heading_and_blocks(): void
    {
        $html = $this->html([$this->uncoloredCard(['blocks' => [$this->uncoloredRows(['id' => 'r', 'items' => [['title' => 'Website']]])]])]);

        $this->assertStringContainsString(
            '<tr><td style="padding:8px 24px;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f4f4f5" style="width:100%;border-collapse:separate;border-spacing:0;background-color:#f4f4f5;border-radius:12px;">'
            .'<tr><td style="padding:16px 0;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">'
            .'<tr><td align="center" style="padding:8px 24px;text-align:center;">'
            .'<p style="margin:0 0 4px 0;font-family:Arial, Helvetica, sans-serif;font-size:12px;line-height:1.4;font-weight:bold;color:#71717a;">Quotation summary</p>'
            .'<p style="margin:0;font-family:Arial, Helvetica, sans-serif;font-size:20px;line-height:1.3;font-weight:bold;color:#18181b;">Basic website. ₱35,000, one-time.</p>'
            .'</td></tr>'
            .'<tr><td align="center" style="padding:8px 24px;text-align:center;">',
            $html,
        );
        $this->assertStringContainsString('</td></tr></table></td></tr></table></td></tr>', $html);
    }

    public function test_the_design_shapes_every_card_and_a_card_may_set_its_own_colors(): void
    {
        $styles = ['card_padding' => 32, 'card_radius' => 0, 'card_border_width' => 1, 'card_border_color' => '#CCCCCC', 'card_background_color' => '#FAFAFA', 'eyebrow_color' => '#0D7490'];
        $html = $this->html([$this->uncoloredCard()], $styles);

        $this->assertStringContainsString('bgcolor="#fafafa" style="width:100%;border-collapse:separate;border-spacing:0;background-color:#fafafa;border:1px solid #cccccc;">', $html);
        $this->assertStringNotContainsString('border-radius', $html);
        $this->assertStringContainsString('<tr><td style="padding:24px 0;">', $html);
        $this->assertStringContainsString('style="padding:8px 32px;text-align:center;"', $html);
        $this->assertStringContainsString('color:#0d7490;">Quotation summary</p>', $html);

        $own = $this->html([$this->card(['background_color' => '#101010', 'border_color' => '#202020'])], $styles);
        $this->assertStringContainsString('bgcolor="#101010"', $own);
        $this->assertStringContainsString('border:1px solid #202020;', $own);
    }

    public function test_a_cards_blocks_are_inset_by_the_card_padding_not_the_column(): void
    {
        $html = $this->html([
            $this->textBlock(['id' => 'outside']),
            $this->uncoloredCard(['blocks' => [$this->textBlock(['id' => 'inside']), $this->divider(['id' => 'd'])]]),
        ], ['content_padding' => 40, 'card_padding' => 16]);

        $this->assertSame(2, substr_count($html, 'style="padding:8px 40px;'), 'The outside text and the card row use the column inset.');
        $this->assertStringContainsString('<tr><td style="padding:8px 16px;"><table role="presentation" width="100%"', $html, 'The card divider uses the card inset.');
        $this->assertStringContainsString('<tr><td style="padding:8px 0;">', $html, 'card_padding 16 leaves 8px above and below.');
    }

    public function test_a_card_heading_blank_after_substitution_is_left_out_and_an_empty_card_draws_nothing(): void
    {
        $context = $this->context(['city' => '']);
        $html = $this->html([$this->card(['eyebrow' => '{{city}}', 'title' => '  '])], [], $context);
        $this->assertStringNotContainsString('font-size:12px;line-height:1.4;font-weight:bold', $html);
        $this->assertStringNotContainsString('font-size:20px', $html);

        $empty = $this->card(['eyebrow' => null, 'title' => '{{city}}', 'blocks' => [$this->rows(['id' => 'r', 'items' => [['title' => '{{city}}']]])]]);
        $this->assertSame($this->html([$this->textBlock()], [], $context), $this->html([$this->textBlock(), $empty], [], $context));
    }

    // ── Rendering: rows ──────────────────────────────────────────────────────────────────────

    public function test_rows_draw_each_title_over_its_description_with_a_rule_between_items(): void
    {
        $html = $this->html([$this->uncoloredRows(['items' => [
            ['title' => 'Website', 'description' => '5 pages.'],
            ['title' => 'Hosting'],
            ['title' => 'Payment', 'description' => '₱17,500 on signing.'],
        ]])]);

        $rule = '<tr><td height="1" bgcolor="#e4e4e7" style="height:1px;font-size:0;line-height:0;background-color:#e4e4e7;">&nbsp;</td></tr>';
        $this->assertSame(2, substr_count($html, $rule));
        $this->assertStringContainsString(
            '<tr><td align="center" style="padding:0px 0 10px 0;text-align:center;">'
            .'<p style="margin:0;font-family:Arial, Helvetica, sans-serif;font-size:15px;line-height:1.4;font-weight:bold;color:#18181b;">Website</p>'
            .'<p style="margin:4px 0 0 0;font-family:Arial, Helvetica, sans-serif;font-size:14px;line-height:1.5;color:#71717a;">5 pages.</p></td></tr>'
            .$rule
            .'<tr><td align="center" style="padding:10px 0 10px 0;text-align:center;">'
            .'<p style="margin:0;font-family:Arial, Helvetica, sans-serif;font-size:15px;line-height:1.4;font-weight:bold;color:#18181b;">Hosting</p></td></tr>',
            $html,
        );
        $this->assertStringContainsString('<tr><td align="center" style="padding:10px 0 0px 0;text-align:center;">', $html);
    }

    public function test_rows_without_dividers_have_no_rules_and_their_colors_can_be_set(): void
    {
        $html = $this->html([$this->rows(['dividers' => false, 'title_color' => '#111111', 'description_color' => '#222222', 'divider_color' => '#333333'])]);

        $this->assertStringNotContainsString('height="1"', $html);
        $this->assertStringContainsString('color:#111111;">Website</p>', $html);
        $this->assertStringContainsString('color:#222222;">5 pages for Santos Bakery, with a contact form.</p>', $html);
    }

    public function test_an_item_blank_after_substitution_is_left_out_and_blank_rows_draw_nothing(): void
    {
        $context = $this->context(['city' => '']);
        $html = $this->html([$this->rows(['items' => [['title' => '{{city}}'], ['title' => 'Kept', 'description' => '{{city}}']]])], [], $context);

        $this->assertStringNotContainsString('height="1"', $html, 'With one item left there is nothing to rule between.');
        $this->assertStringContainsString('font-weight:bold;color:#18181b;">Kept</p></td></tr>', $html);
        $this->assertSame(
            $this->html([$this->textBlock()], [], $context),
            $this->html([$this->textBlock(), $this->rows(['items' => [['title' => '{{city}}']]])], [], $context),
        );
    }

    // ── Hostile values ───────────────────────────────────────────────────────────────────────

    public function test_card_and_row_text_and_placeholder_values_are_escaped_and_substituted_once(): void
    {
        $context = $this->context(['business_name' => '<img src=x onerror=alert(1)> & "Co"', 'first_name' => '{{city}}', 'city' => "Ma\u{202E}nila\x07"]);
        $html = $this->html([$this->card([
            'eyebrow' => '</p><script>alert(1)</script>',
            'title' => 'For {{business_name}}',
            'blocks' => [$this->rows(['id' => 'r', 'items' => [['title' => '{{first_name}}', 'description' => "Quote's \"total\" <b>₱1</b> in {{city}}"]]])],
        ])], [], $context);

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;/p&gt;&lt;script&gt;alert(1)&lt;/script&gt;</p>', $html);
        $this->assertStringContainsString('For &lt;img src=x onerror=alert(1)&gt; &amp; &quot;Co&quot;</p>', $html);
        $this->assertStringContainsString('>{{city}}</p>', $html, 'A value is never expanded a second time.');
        $this->assertStringContainsString('Quote&#039;s &quot;total&quot; &lt;b&gt;₱1&lt;/b&gt; in Ma nila</p>', $html);
        $this->assertStringNotContainsString("\u{202E}", $html);
    }

    // ── Plain text ───────────────────────────────────────────────────────────────────────────

    public function test_the_text_part_writes_a_card_and_its_rows_readably_in_order(): void
    {
        $text = $this->plain([
            $this->heading(['text' => 'Your website quotation.']),
            $this->card(['blocks' => [
                $this->divider(['id' => 'd']),
                $this->rows(['id' => 'r', 'items' => [['title' => 'Website', 'description' => '5 pages.'], ['title' => 'Payment']]]),
                $this->textBlock(['id' => 'note', 'content' => $this->doc($this->para($this->text('Prices in PHP.')))]),
                $this->button(['id' => 'b', 'label' => 'See the quote', 'url' => 'https://viteseo.ph/q']),
            ]]),
            $this->textBlock(['id' => 'after', 'content' => $this->doc($this->para($this->text('Thank you.')))]),
        ]);

        $this->assertSame(
            "Your website quotation.\n\n"
            ."Quotation summary\nBasic website. ₱35,000, one-time.\n\n"
            ."----------------------------------------\n\n"
            ."Website\n5 pages.\n\nPayment\n\n"
            ."Prices in PHP.\n\n"
            ."See the quote: https://viteseo.ph/q\n\n"
            .'Thank you.',
            $text,
        );
    }

    public function test_the_text_part_leaves_out_blank_card_headings_and_items(): void
    {
        $context = $this->context(['city' => '']);
        $text = $this->plain([$this->card(['eyebrow' => null, 'title' => '{{city}}', 'blocks' => [
            $this->rows(['id' => 'r', 'items' => [['title' => '{{city}}'], ['title' => 'Kept', 'description' => '{{city}}']]]),
        ]])], $context);

        $this->assertSame('Kept', $text);
    }

    public function test_rendering_is_deterministic(): void
    {
        $blocks = [$this->card(), $this->rows()];
        $this->assertSame($this->html($blocks), $this->html($blocks));
        $this->assertSame($this->plain($blocks), $this->plain($blocks));
    }
}
