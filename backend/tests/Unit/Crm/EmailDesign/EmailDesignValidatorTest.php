<?php

declare(strict_types=1);

namespace Tests\Unit\Crm\EmailDesign;

use App\Modules\Crm\Support\EmailDesign\EmailDesignSchema;
use App\Modules\Crm\Support\EmailDesign\EmailDesignValidator;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two email design documents themselves: what a valid design layout and template content look like, and
 * every structural way either can be wrong — including a block in a part of the email that may not hold it.
 * The attack-shaped cases live in EmailDesignSecurityTest; styles, the preheader and the logo link in
 * EmailDesignStyleTest.
 */
class EmailDesignValidatorTest extends TestCase
{
    use BuildsEmailDesigns;

    private function validator(): EmailDesignValidator
    {
        return new EmailDesignValidator;
    }

    private function assertLayoutProblem(string $path, string $message, mixed $layout): void
    {
        $this->assertSame(['path' => $path, 'message' => $message], $this->validator()->layoutProblem($layout));
    }

    private function assertContentProblem(string $path, string $message, mixed $content): void
    {
        $this->assertSame(['path' => $path, 'message' => $message], $this->validator()->contentProblem($content));
    }

    /** @param  array<string, mixed>  $block */
    private function assertBlockProblem(string $field, string $message, array $block): void
    {
        $this->assertSame(['path' => $this->placement($block['type']).'.0.'.$field, 'message' => $message], $this->problemFor($block));
    }

    // ── Valid documents ──────────────────────────────────────────────────────────────────────

    public function test_the_fixture_layout_and_content_are_valid(): void
    {
        $this->assertNull($this->validator()->layoutProblem($this->layout()));
        $this->assertNull($this->validator()->contentProblem($this->content()));
    }

    public function test_both_documents_are_valid_as_json_strings(): void
    {
        $this->assertNull($this->validator()->layoutProblemInJson((string) json_encode($this->layout())));
        $this->assertNull($this->validator()->contentProblemInJson((string) json_encode($this->content())));
    }

    public function test_a_layout_may_have_no_header_and_no_footer(): void
    {
        $this->assertNull($this->validator()->layoutProblem($this->layout([], [])));
    }

    public function test_the_fixture_covers_every_block_content_may_hold(): void
    {
        // The default content fixture holds every single block; the card, rows and actions blocks are covered by their
        // own fixtures (EmailDesignCardRowsTest, EmailDesignActionsTest), so the many tests that render this one keep
        // their exact output.
        $this->assertSame(EmailDesignSchema::REGION_BLOCKS[EmailDesignSchema::CONTENT], [...array_column($this->contentBlocks(), 'type'), 'card', 'rows', 'actions']);
    }

    /** @return array<string, array{string, string}> */
    public static function allowedPlacements(): array
    {
        $cases = [];
        foreach (EmailDesignSchema::REGION_BLOCKS as $region => $types) {
            foreach ($types as $type) {
                $cases["{$type} in {$region}"] = [$region, $type];
            }
        }

        return $cases;
    }

    #[DataProvider('allowedPlacements')]
    public function test_a_block_is_valid_in_every_part_that_allows_it(string $region, string $type): void
    {
        $this->assertNull($this->problemIn($region, $this->block($type)));
    }

    /** @return array<string, array{string, string}> */
    public static function refusedPlacements(): array
    {
        $cases = [];
        foreach (EmailDesignSchema::REGION_BLOCKS as $region => $types) {
            foreach (array_diff(EmailDesignSchema::blockTypes(), $types) as $type) {
                $cases["{$type} in {$region}"] = [$region, $type];
            }
        }

        return $cases;
    }

    #[DataProvider('refusedPlacements')]
    public function test_a_block_outside_the_parts_that_allow_it_is_refused(string $region, string $type): void
    {
        $this->assertSame(['path' => "{$region}.0.type", 'message' => EmailDesignValidator::BLOCK_NOT_ALLOWED], $this->problemIn($region, $this->block($type)));
    }

    /**
     * A button belongs to EITHER document; a logo and footer text belong only to the design.
     *
     * The button rule changed deliberately: a design may now carry the reusable call to action every
     * template drawn in it shows, while a template still carries its own. The two other rules did not
     * change and are asserted here so widening one allow-list cannot quietly widen the rest —
     * `logo` and `footer` remain the design's alone, because a template that could place them would be
     * able to contradict the design it is shown in.
     */
    public function test_a_button_belongs_to_any_part_while_logos_and_footers_belong_to_the_design(): void
    {
        $this->assertContains('button', EmailDesignSchema::REGION_BLOCKS[EmailDesignSchema::HEADER]);
        $this->assertContains('button', EmailDesignSchema::REGION_BLOCKS[EmailDesignSchema::FOOTER]);
        $this->assertContains('button', EmailDesignSchema::REGION_BLOCKS[EmailDesignSchema::CONTENT]);

        $this->assertNotContains('logo', EmailDesignSchema::REGION_BLOCKS[EmailDesignSchema::CONTENT]);
        $this->assertNotContains('footer', EmailDesignSchema::REGION_BLOCKS[EmailDesignSchema::CONTENT]);
    }

    /** A design-level button validates in the header and in the footer, with the same rules as any other. */
    public function test_a_button_validates_in_the_designs_header_and_footer(): void
    {
        $this->assertNull($this->problemIn(EmailDesignSchema::HEADER, $this->button()));
        $this->assertNull($this->problemIn(EmailDesignSchema::FOOTER, $this->button()));

        // And it is still held to the button rules wherever it sits.
        $this->assertSame(
            ['path' => 'header.0.url', 'message' => EmailDesignValidator::UNSAFE_URL],
            $this->problemIn(EmailDesignSchema::HEADER, $this->button(['url' => 'javascript:alert(1)'])),
        );
    }

    /** @return array<string, array{string, string, int}> */
    public static function rangeBoundaries(): array
    {
        return [
            'heading level 1' => ['heading', 'level', 1],
            'heading level 3' => ['heading', 'level', 3],
            'text font size 12' => ['text', 'font_size', 12],
            'text font size 22' => ['text', 'font_size', 22],
            'footer font size 10' => ['footer', 'font_size', 10],
            'footer font size 14' => ['footer', 'font_size', 14],
            'image width 16' => ['image', 'width', 16],
            'image width 552' => ['image', 'width', 552],
            'logo width 40' => ['logo', 'width', 40],
            'logo width 300' => ['logo', 'width', 300],
            'spacer height 4' => ['spacer', 'height', 4],
            'spacer height 96' => ['spacer', 'height', 96],
            'divider thickness 1' => ['divider', 'thickness', 1],
            'divider thickness 4' => ['divider', 'thickness', 4],
            'button radius 0' => ['button', 'radius', 0],
            'button radius 24' => ['button', 'radius', 24],
        ];
    }

    #[DataProvider('rangeBoundaries')]
    public function test_range_boundaries_are_inclusive(string $type, string $field, int $value): void
    {
        $this->assertNull($this->problemFor($this->block($type, [$field => $value])));
    }

    public function test_every_font_and_alignment_is_accepted(): void
    {
        foreach (array_keys(EmailDesignSchema::FONTS) as $font) {
            $this->assertNull($this->validator()->layoutProblem($this->layout(styles: ['font' => $font])), $font);
            $this->assertNull($this->validator()->contentProblem($this->content(extra: ['style_overrides' => ['font' => $font]])), $font);
        }
        foreach (EmailDesignSchema::ALIGNMENTS as $align) {
            $this->assertNull($this->problemFor($this->heading(['align' => $align])), $align);
            $this->assertNull($this->validator()->layoutProblem($this->layout(signature: ['align' => $align])), $align);
        }
    }

    // ── Top level ────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{mixed}> */
    public static function notADocument(): array
    {
        return [
            'null' => [null],
            'string' => ['design'],
            'integer' => [1],
            'a list' => [[1, 2, 3]],
        ];
    }

    #[DataProvider('notADocument')]
    public function test_a_value_that_is_not_an_object_is_malformed(mixed $value): void
    {
        $this->assertLayoutProblem('', EmailDesignValidator::MALFORMED, $value);
        $this->assertContentProblem('', EmailDesignValidator::MALFORMED, $value);
    }

    /** @return array<string, array{string, string, string}> */
    public static function badJson(): array
    {
        return [
            'truncated' => ['{"version":1,', '', EmailDesignValidator::MALFORMED],
            'a JSON string' => ['"design"', '', EmailDesignValidator::MALFORMED],
            'a JSON list' => ['[1,2]', '', EmailDesignValidator::MALFORMED],
            'invalid UTF-8' => ["{\"version\":\"\xB1\x31\"}", '', EmailDesignValidator::MALFORMED],
            'an empty object' => ['{}', 'version', EmailDesignValidator::MISSING_FIELD],
        ];
    }

    #[DataProvider('badJson')]
    public function test_bad_json_is_rejected(string $json, string $path, string $message): void
    {
        $expected = ['path' => $path, 'message' => $message];

        $this->assertSame($expected, $this->validator()->layoutProblemInJson($json));
        $this->assertSame($expected, $this->validator()->contentProblemInJson($json));
    }

    /** @return array<string, array{mixed}> */
    public static function unsupportedVersions(): array
    {
        return [
            'two' => [2],
            'zero' => [0],
            'string one' => ['1'],
            'float one' => [1.0],
            'null' => [null],
        ];
    }

    #[DataProvider('unsupportedVersions')]
    public function test_only_version_one_is_supported(mixed $version): void
    {
        $this->assertLayoutProblem('version', EmailDesignValidator::UNSUPPORTED_VERSION, [...$this->layout(), 'version' => $version]);
        $this->assertContentProblem('version', EmailDesignValidator::UNSUPPORTED_VERSION, [...$this->content(), 'version' => $version]);
    }

    public function test_an_unknown_top_level_field_is_rejected_before_a_missing_one(): void
    {
        $layout = $this->layout();
        unset($layout['footer']);
        $layout['html'] = '<p>hi</p>';
        $this->assertLayoutProblem('html', EmailDesignValidator::UNKNOWN_FIELD, $layout);

        $content = $this->content();
        unset($content['blocks']);
        $content['html'] = '<p>hi</p>';
        $this->assertContentProblem('html', EmailDesignValidator::UNKNOWN_FIELD, $content);
    }

    public function test_an_unknown_key_is_reduced_to_safe_characters_in_the_path(): void
    {
        $this->assertContentProblem('scriptalert1script', EmailDesignValidator::UNKNOWN_FIELD, [...$this->content(), '<script>alert(1)</script>' => 1]);
        $this->assertLayoutProblem('unknown', EmailDesignValidator::UNKNOWN_FIELD, [...$this->layout(), '"><' => 1]);
    }

    /** @return array<string, array{string}> */
    public static function layoutFields(): array
    {
        return array_combine(EmailDesignSchema::LAYOUT_FIELDS, array_map(fn (string $field) => [$field], EmailDesignSchema::LAYOUT_FIELDS));
    }

    #[DataProvider('layoutFields')]
    public function test_every_layout_field_is_required(string $field): void
    {
        $layout = $this->layout();
        unset($layout[$field]);

        $this->assertLayoutProblem($field, EmailDesignValidator::MISSING_FIELD, $layout);
    }

    public function test_content_needs_its_version_and_blocks(): void
    {
        foreach (EmailDesignSchema::CONTENT_FIELDS['required'] as $field) {
            $content = $this->content();
            unset($content[$field]);
            $this->assertContentProblem($field, EmailDesignValidator::MISSING_FIELD, $content);
        }
    }

    public function test_design_styles_are_an_object_of_optional_styles(): void
    {
        $this->assertLayoutProblem('styles', EmailDesignValidator::MALFORMED, [...$this->layout(), 'styles' => 'arial']);
        $this->assertLayoutProblem('styles.css', EmailDesignValidator::UNKNOWN_FIELD, $this->layout(styles: ['css' => 'body{}']));
        $this->assertNull($this->validator()->layoutProblem([...$this->layout(), 'styles' => []]));

        $layout = $this->layout();
        unset($layout['styles']['link_color']);
        $this->assertNull($this->validator()->layoutProblem($layout));
    }

    public function test_style_overrides_are_optional_and_checked_like_design_styles(): void
    {
        $this->assertNull($this->validator()->contentProblem($this->content(extra: ['style_overrides' => null])));
        $this->assertNull($this->validator()->contentProblem($this->content(extra: ['style_overrides' => []])));
        $this->assertNull($this->validator()->contentProblem($this->content(extra: ['style_overrides' => ['accent_color' => '#AA0000', 'font' => 'georgia']])));

        $this->assertContentProblem('style_overrides', EmailDesignValidator::MALFORMED, $this->content(extra: ['style_overrides' => 'red']));
        $this->assertContentProblem('style_overrides.css', EmailDesignValidator::UNKNOWN_FIELD, $this->content(extra: ['style_overrides' => ['css' => 'x']]));
        $this->assertContentProblem('style_overrides.text_color', EmailDesignValidator::INVALID_COLOR, $this->content(extra: ['style_overrides' => ['text_color' => 'red']]));
    }

    // ── Signature area ───────────────────────────────────────────────────────────────────────

    public function test_the_signature_area_must_be_an_object(): void
    {
        $this->assertLayoutProblem('signature', EmailDesignValidator::MALFORMED, [...$this->layout(), 'signature' => 'Best regards']);
        $this->assertLayoutProblem('signature', EmailDesignValidator::MALFORMED, [...$this->layout(), 'signature' => [1, 2]]);
    }

    /** @return array<string, array{string}> */
    public static function signatureFields(): array
    {
        return array_combine(EmailDesignSchema::SIGNATURE_FIELDS, array_map(fn (string $field) => [$field], EmailDesignSchema::SIGNATURE_FIELDS));
    }

    #[DataProvider('signatureFields')]
    public function test_every_signature_field_is_required(string $field): void
    {
        $layout = $this->layout();
        unset($layout['signature'][$field]);

        $this->assertLayoutProblem("signature.{$field}", EmailDesignValidator::MISSING_FIELD, $layout);
    }

    public function test_the_signature_area_holds_nothing_else(): void
    {
        $this->assertLayoutProblem('signature.show_photo', EmailDesignValidator::UNKNOWN_FIELD, $this->layout(signature: ['show_photo' => true]));
    }

    /** @return array<string, array{mixed}> */
    public static function notBooleans(): array
    {
        return ['integer' => [1], 'string' => ['true'], 'null' => [null], 'zero' => [0]];
    }

    #[DataProvider('notBooleans')]
    public function test_signature_enabled_must_be_a_boolean(mixed $value): void
    {
        $this->assertLayoutProblem('signature.enabled', EmailDesignValidator::MALFORMED, $this->layout(signature: ['enabled' => $value]));
    }

    public function test_a_sign_off_may_be_blank_or_use_placeholders(): void
    {
        $this->assertNull($this->validator()->layoutProblem($this->layout(signature: ['sign_off' => ''])));
        $this->assertNull($this->validator()->layoutProblem($this->layout(signature: ['sign_off' => 'Thanks, {{first_name}}'])));
        $this->assertNull($this->validator()->contentProblem($this->content(extra: ['sign_off' => ''])));
        $this->assertNull($this->validator()->contentProblem($this->content(extra: ['sign_off' => 'Cheers, {{first_name}}'])));
    }

    /** @return array<string, array{mixed, string}> */
    public static function badSignOffs(): array
    {
        return [
            'too long' => [str_repeat('a', 101), sprintf(EmailDesignValidator::TEXT_TOO_LONG, 100)],
            'unknown placeholder' => ['Thanks, {{email}}', EmailDesignValidator::UNKNOWN_PLACEHOLDER],
            'newline' => ["Thanks,\nAlex", EmailDesignValidator::INVALID_TEXT],
            'not a string' => [5, EmailDesignValidator::MALFORMED],
        ];
    }

    #[DataProvider('badSignOffs')]
    public function test_a_bad_sign_off_is_refused_in_the_design_and_in_content(mixed $signOff, string $message): void
    {
        $this->assertLayoutProblem('signature.sign_off', $message, $this->layout(signature: ['sign_off' => $signOff]));
        $this->assertContentProblem('sign_off', $message, $this->content(extra: ['sign_off' => $signOff]));
    }

    public function test_the_signature_alignment_is_checked(): void
    {
        $this->assertLayoutProblem('signature.align', EmailDesignValidator::INVALID_ALIGNMENT, $this->layout(signature: ['align' => 'justify']));
    }

    // ── Parts ────────────────────────────────────────────────────────────────────────────────

    public function test_content_blocks_must_be_a_non_empty_list_within_the_limit(): void
    {
        $this->assertContentProblem('blocks', EmailDesignValidator::MALFORMED, [...$this->content(), 'blocks' => 'heading']);
        $this->assertContentProblem('blocks', EmailDesignValidator::MALFORMED, [...$this->content(), 'blocks' => ['first' => $this->heading()]]);
        $this->assertContentProblem('blocks', EmailDesignValidator::NO_BLOCKS, $this->content([]));

        $blocks = array_map(fn (int $i) => $this->spacer(['id' => 'spacer-'.$i]), range(1, EmailDesignSchema::MAX_CONTENT_BLOCKS));
        $this->assertNull($this->validator()->contentProblem($this->content($blocks)));

        $blocks[] = $this->spacer(['id' => 'one-too-many']);
        $this->assertContentProblem('blocks', sprintf(EmailDesignValidator::TOO_MANY_BLOCKS, EmailDesignSchema::MAX_CONTENT_BLOCKS), $this->content($blocks));
    }

    public function test_a_header_and_footer_are_lists_within_their_limit(): void
    {
        $this->assertLayoutProblem('header', EmailDesignValidator::MALFORMED, [...$this->layout(), 'header' => 'logo']);
        $this->assertLayoutProblem('footer', EmailDesignValidator::MALFORMED, [...$this->layout(), 'footer' => ['a' => $this->footer()]]);

        foreach (['header', 'footer'] as $region) {
            $spacers = array_map(fn (int $i) => $this->spacer(['id' => "{$region}-spacer-{$i}"]), range(1, EmailDesignSchema::MAX_REGION_BLOCKS));
            $layout = $region === 'header' ? $this->layout($spacers, []) : $this->layout([], $spacers);
            $this->assertNull($this->validator()->layoutProblem($layout), $region);

            $spacers[] = $this->spacer(['id' => "{$region}-one-too-many"]);
            $layout = $region === 'header' ? $this->layout($spacers, []) : $this->layout([], $spacers);
            $this->assertLayoutProblem($region, sprintf(EmailDesignValidator::TOO_MANY_BLOCKS, EmailDesignSchema::MAX_REGION_BLOCKS), $layout);
        }
    }

    public function test_a_block_that_is_not_an_object_is_malformed(): void
    {
        $this->assertContentProblem('blocks.1', EmailDesignValidator::MALFORMED, $this->content([$this->heading(), 'spacer']));
        $this->assertLayoutProblem('header.1', EmailDesignValidator::MALFORMED, $this->layout([$this->logo(), 'logo'], []));
    }

    public function test_block_ids_are_unique_across_a_layout(): void
    {
        $this->assertLayoutProblem('footer.0.id', EmailDesignValidator::DUPLICATE_ID, $this->layout([$this->spacer(['id' => 'same'])], [$this->divider(['id' => 'same'])]));
    }

    public function test_block_ids_are_unique_within_content(): void
    {
        $this->assertContentProblem('blocks.1.id', EmailDesignValidator::DUPLICATE_ID, $this->content([$this->spacer(['id' => 'same']), $this->divider(['id' => 'same'])]));
    }

    public function test_a_layout_and_content_may_use_the_same_ids(): void
    {
        $this->assertNull($this->validator()->layoutProblem($this->layout([$this->heading()], [])));
        $this->assertNull($this->validator()->contentProblem($this->content([$this->heading()])));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidIds(): array
    {
        return [
            'empty' => [''],
            'space' => ['has space'],
            'too long' => [str_repeat('a', 65)],
            'markup' => ['<b>'],
            'trailing newline' => ["block\n"],
            'integer' => [12],
            'null' => [null],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_a_block_id_must_be_a_short_safe_string(mixed $id): void
    {
        $this->assertContentProblem('blocks.0.id', EmailDesignValidator::INVALID_ID, $this->content([$this->spacer(['id' => $id])]));
    }

    public function test_a_missing_block_type_is_reported_as_missing(): void
    {
        $block = $this->heading();
        unset($block['type']);

        $this->assertContentProblem('blocks.0.type', EmailDesignValidator::MISSING_FIELD, $this->content([$block]));
    }

    /** @return array<string, array{string, string}> */
    public static function requiredFields(): array
    {
        $cases = [];
        foreach (EmailDesignSchema::BLOCK_FIELDS as $type => $fields) {
            foreach ($fields['required'] as $field) {
                if ($field !== 'type') {
                    $cases["{$type} without {$field}"] = [$type, $field];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('requiredFields')]
    public function test_every_required_block_field_is_enforced(string $type, string $field): void
    {
        $block = $this->block($type);
        unset($block[$field]);

        $this->assertBlockProblem($field, EmailDesignValidator::MISSING_FIELD, $block);
    }

    /** @return array<string, array{string, string}> */
    public static function fieldsFromOtherPlaces(): array
    {
        return [
            'heading style' => ['heading', 'style'],
            'text html' => ['text', 'html'],
            'button onclick' => ['button', 'onclick'],
            'button class' => ['button', 'class'],
            'image src' => ['image', 'src'],
            'image url' => ['image', 'url'],
            'logo url' => ['logo', 'url'],
            'logo src' => ['logo', 'src'],
            'spacer css' => ['spacer', 'css'],
            'footer width' => ['footer', 'width'],
            'divider link_url' => ['divider', 'link_url'],
            'heading sign_off' => ['heading', 'sign_off'],
        ];
    }

    #[DataProvider('fieldsFromOtherPlaces')]
    public function test_a_field_outside_the_block_schema_is_rejected(string $type, string $field): void
    {
        $this->assertBlockProblem($field, EmailDesignValidator::UNKNOWN_FIELD, $this->block($type, [$field => 'x']));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidAlignments(): array
    {
        return [
            'justify' => ['justify'],
            'upper case' => ['CENTER'],
            'empty' => [''],
            'null' => [null],
            'integer' => [1],
        ];
    }

    #[DataProvider('invalidAlignments')]
    public function test_alignment_must_be_left_center_or_right(mixed $align): void
    {
        foreach (['logo', 'heading', 'text', 'button', 'image', 'footer'] as $type) {
            $this->assertBlockProblem('align', EmailDesignValidator::INVALID_ALIGNMENT, $this->block($type, ['align' => $align]));
        }
    }

    // ── Single-line text ─────────────────────────────────────────────────────────────────────

    /** @return array<string, array{string, string, int}> */
    public static function lineLimits(): array
    {
        return [
            'heading text' => ['heading', 'text', EmailDesignSchema::MAX_HEADING_TEXT],
            'button label' => ['button', 'label', EmailDesignSchema::MAX_BUTTON_LABEL],
            'image alt' => ['image', 'alt', EmailDesignSchema::MAX_ALT_TEXT],
        ];
    }

    #[DataProvider('lineLimits')]
    public function test_single_line_text_has_a_character_limit(string $type, string $field, int $max): void
    {
        // Multibyte characters count once each.
        $this->assertNull($this->problemFor($this->block($type, [$field => str_repeat('ñ', $max)])));
        $this->assertBlockProblem($field, sprintf(EmailDesignValidator::TEXT_TOO_LONG, $max), $this->block($type, [$field => str_repeat('ñ', $max + 1)]));
    }

    public function test_headings_and_button_labels_cannot_be_blank_but_alt_text_can(): void
    {
        $this->assertBlockProblem('text', EmailDesignValidator::TEXT_REQUIRED, $this->heading(['text' => '   ']));
        $this->assertBlockProblem('label', EmailDesignValidator::TEXT_REQUIRED, $this->button(['label' => '']));
        $this->assertNull($this->problemFor($this->image(['alt' => ''])));
    }

    /** @return array<string, array{string}> */
    public static function disallowedCharacters(): array
    {
        return [
            'newline' => ["Hello\nWorld"],
            'carriage return' => ["Hello\rWorld"],
            'tab' => ["Hello\tWorld"],
            'null byte' => ["Hello\0World"],
            'C1 control' => ["Hello\u{0085}World"],
            'line separator' => ["Hello\u{2028}World"],
            'right-to-left override' => ["Hello\u{202E}dlroW"],
            'isolate' => ["Hello\u{2066}World"],
        ];
    }

    #[DataProvider('disallowedCharacters')]
    public function test_control_separator_and_bidi_characters_are_rejected(string $text): void
    {
        $this->assertBlockProblem('text', EmailDesignValidator::INVALID_TEXT, $this->heading(['text' => $text]));
        $this->assertContentProblem(
            'blocks.0.content.content.0.content.0.text',
            EmailDesignValidator::INVALID_TEXT,
            $this->content([$this->textBlock(['content' => $this->doc($this->para($this->text($text)))])]),
        );
    }

    public function test_a_text_field_that_is_not_a_string_is_malformed(): void
    {
        $this->assertBlockProblem('label', EmailDesignValidator::MALFORMED, $this->button(['label' => 5]));
        $this->assertBlockProblem('alt', EmailDesignValidator::MALFORMED, $this->image(['alt' => null]));
    }

    // ── Placeholders ─────────────────────────────────────────────────────────────────────────

    public function test_whitelisted_placeholders_are_allowed_in_every_text_field(): void
    {
        $this->assertNull($this->validator()->contentProblem($this->content(
            [
                $this->heading(['text' => 'Hi {{ first_name }} from {{city}}']),
                $this->textBlock(['content' => $this->doc($this->para($this->text('{{business_name}} in {{industry}}')))]),
                $this->button(['label' => 'Talk to {{sender_name}}']),
            ],
            ['preheader' => 'For {{business_name}}', 'sign_off' => 'Thanks, {{contact_name}}'],
        )));

        $this->assertNull($this->validator()->layoutProblem($this->layout(
            [$this->heading(['text' => 'News for {{business_name}}'])],
            [$this->footer(['content' => $this->doc($this->para($this->text('Sent by {{sender_name}}')))])],
            signature: ['sign_off' => 'Cheers, {{first_name}}'],
        )));
    }

    /** @return array<string, array{string, string}> */
    public static function badPlaceholders(): array
    {
        return [
            'unknown key' => ['Hi {{email}}', EmailDesignValidator::UNKNOWN_PLACEHOLDER],
            'camel case' => ['Hi {{FirstName}}', EmailDesignValidator::UNKNOWN_PLACEHOLDER],
            'space inside the key' => ['Hi {{first name}}', EmailDesignValidator::UNKNOWN_PLACEHOLDER],
            'empty' => ['Hi {{}}', EmailDesignValidator::UNKNOWN_PLACEHOLDER],
            'triple braces' => ['Hi {{{first_name}}}', EmailDesignValidator::UNKNOWN_PLACEHOLDER],
            'not yet a template token' => ['{{sender_title}}', EmailDesignValidator::UNKNOWN_PLACEHOLDER],
            'unclosed' => ['Hi {{first_name', EmailDesignValidator::STRAY_BRACES],
            'unopened' => ['Hi first_name}}', EmailDesignValidator::STRAY_BRACES],
            'lone opener after a valid token' => ['{{city}} {{', EmailDesignValidator::STRAY_BRACES],
        ];
    }

    #[DataProvider('badPlaceholders')]
    public function test_unknown_and_incomplete_placeholders_are_rejected(string $text, string $message): void
    {
        $this->assertBlockProblem('text', $message, $this->heading(['text' => $text]));
        $this->assertBlockProblem('label', $message, $this->button(['label' => $text]));
        $this->assertContentProblem('preheader', $message, $this->content(extra: ['preheader' => $text]));
    }

    public function test_a_placeholder_split_across_formatting_is_incomplete(): void
    {
        $content = $this->doc($this->para($this->text('{{first'), $this->text('_name}}', [['type' => 'bold']])));

        $this->assertContentProblem('blocks.0.content.content.0.content.0.text', EmailDesignValidator::STRAY_BRACES, $this->content([$this->textBlock(['content' => $content])]));
    }

    public function test_the_placeholder_whitelist_defaults_to_the_template_catalog_and_can_be_widened(): void
    {
        $this->assertSame(array_keys(EmailTemplateCatalog::TOKENS), EmailDesignSchema::tokenKeys());

        $content = $this->content([$this->heading(['text' => '{{sender_title}}'])]);
        $this->assertNotNull((new EmailDesignValidator)->contentProblem($content));
        $this->assertNull((new EmailDesignValidator([...EmailDesignSchema::tokenKeys(), 'sender_title']))->contentProblem($content));
    }

    // ── Rich text documents ──────────────────────────────────────────────────────────────────

    public function test_text_blocks_accept_lists_breaks_formatting_and_links_as_tiptap_writes_them(): void
    {
        $content = $this->doc(
            $this->para(
                $this->text('Bold ', [['type' => 'bold'], ['type' => 'italic']]),
                $this->hardBreak(),
                ['type' => 'hardBreak', 'marks' => [['type' => 'bold']]],
                $this->text('link', [$this->link('https://viteseo.ph'), ['type' => 'underline', 'attrs' => []]]),
            ),
            $this->para(),
            $this->bullets(
                $this->item($this->para($this->text('One'))),
                $this->item($this->para($this->text('Two')), $this->numbered($this->item($this->para($this->text('Nested'))))),
            ),
            ['type' => 'orderedList', 'attrs' => ['start' => 3, 'type' => '1'], 'content' => [$this->item($this->para($this->text('Three')))]],
            ['type' => 'orderedList', 'content' => [$this->item($this->para($this->text('No attrs')))]],
        );

        $this->assertNull($this->validator()->contentProblem($this->content([$this->textBlock(['content' => $content])])));
    }

    public function test_a_block_document_must_be_a_non_empty_doc(): void
    {
        $this->assertContentProblem('blocks.0.content', EmailDesignValidator::MALFORMED, $this->content([$this->textBlock(['content' => 'Hello'])]));
        $this->assertContentProblem('blocks.0.content', EmailDesignValidator::MALFORMED, $this->content([$this->textBlock(['content' => $this->para($this->text('Hello'))])]));
        $this->assertContentProblem('blocks.0.content', EmailDesignValidator::TEXT_REQUIRED, $this->content([$this->textBlock(['content' => $this->doc()])]));
        $this->assertLayoutProblem('footer.0.content', EmailDesignValidator::TEXT_REQUIRED, $this->layout([], [$this->footer(['content' => ['type' => 'doc']])]));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unsupportedNodes(): array
    {
        return [
            'heading' => [['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'x']]]],
            'blockquote' => [['type' => 'blockquote', 'content' => []]],
            'code block' => [['type' => 'codeBlock', 'content' => []]],
            'image' => [['type' => 'image', 'attrs' => ['src' => 'https://evil.example/x.png']]],
            'horizontal rule' => [['type' => 'horizontalRule']],
            'task list' => [['type' => 'taskList', 'content' => []]],
            'table' => [['type' => 'table', 'content' => []]],
        ];
    }

    /** @param  array<string, mixed>  $node */
    #[DataProvider('unsupportedNodes')]
    public function test_nodes_outside_the_email_subset_are_rejected(array $node): void
    {
        $this->assertContentProblem('blocks.0.content.content.0', EmailDesignValidator::UNSUPPORTED_FORMATTING, $this->content([$this->textBlock(['content' => $this->doc($node)])]));
    }

    /** @return array<string, array{string}> */
    public static function unsupportedMarks(): array
    {
        return [
            'highlight' => ['highlight'],
            'code' => ['code'],
            'text style' => ['textStyle'],
            'subscript' => ['subscript'],
        ];
    }

    #[DataProvider('unsupportedMarks')]
    public function test_marks_outside_the_email_subset_are_rejected(string $mark): void
    {
        $content = $this->doc($this->para($this->text('x', [['type' => $mark]])));

        $this->assertContentProblem('blocks.0.content.content.0.content.0.marks.0', EmailDesignValidator::UNSUPPORTED_FORMATTING, $this->content([$this->textBlock(['content' => $content])]));
    }

    public function test_footers_hold_paragraphs_only(): void
    {
        $doc = $this->doc($this->bullets($this->item($this->para($this->text('x')))));

        $this->assertNull($this->validator()->contentProblem($this->content([$this->textBlock(['content' => $doc])])));
        $this->assertLayoutProblem('footer.0.content.content.0', EmailDesignValidator::UNSUPPORTED_FORMATTING, $this->layout([], [$this->footer(['content' => $doc])]));
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function malformedDocuments(): array
    {
        $text = ['type' => 'text', 'text' => 'x'];
        $para = ['type' => 'paragraph', 'content' => [$text]];
        $item = ['type' => 'listItem', 'content' => [$para]];

        return [
            'text directly in the doc' => [['type' => 'doc', 'content' => [$text]], 'blocks.0.content.content.0', EmailDesignValidator::MALFORMED],
            'list inside a paragraph' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'bulletList', 'content' => [$item]]]]]], 'blocks.0.content.content.0.content.0', EmailDesignValidator::MALFORMED],
            'doc inside a paragraph' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'doc', 'content' => [$para]]]]]], 'blocks.0.content.content.0.content.0', EmailDesignValidator::MALFORMED],
            'empty list' => [['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => []]]], 'blocks.0.content.content.0', EmailDesignValidator::MALFORMED],
            'list item opening with a list' => [['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'bulletList', 'content' => [$item]]]]]]]], 'blocks.0.content.content.0.content.0', EmailDesignValidator::MALFORMED],
            'empty text node' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]]], 'blocks.0.content.content.0.content.0.text', EmailDesignValidator::MALFORMED],
            'text node without text' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text']]]]], 'blocks.0.content.content.0.content.0.text', EmailDesignValidator::MISSING_FIELD],
            'text node with children' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'content' => [$text]]]]]], 'blocks.0.content.content.0.content.0.content', EmailDesignValidator::UNKNOWN_FIELD],
            'node without a type' => [['type' => 'doc', 'content' => [['content' => [$text]]]], 'blocks.0.content.content.0', EmailDesignValidator::MALFORMED],
            'child that is not an object' => [['type' => 'doc', 'content' => ['paragraph']], 'blocks.0.content.content.0', EmailDesignValidator::MALFORMED],
            'content that is not a list' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => 'x']]], 'blocks.0.content.content.0.content', EmailDesignValidator::MALFORMED],
            'style on a paragraph' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'style' => 'color:red', 'content' => [$text]]]], 'blocks.0.content.content.0.style', EmailDesignValidator::UNKNOWN_FIELD],
            'attrs on a paragraph' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['textAlign' => 'center'], 'content' => [$text]]]], 'blocks.0.content.content.0.attrs', EmailDesignValidator::UNKNOWN_FIELD],
            'marks on a paragraph' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'marks' => [['type' => 'bold']], 'content' => [$text]]]], 'blocks.0.content.content.0.marks', EmailDesignValidator::UNKNOWN_FIELD],
            'duplicate mark' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'bold'], ['type' => 'bold']]]]]]], 'blocks.0.content.content.0.content.0.marks.1', EmailDesignValidator::MALFORMED],
            'marks that are not a list' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => ['type' => 'bold']]]]]], 'blocks.0.content.content.0.content.0.marks', EmailDesignValidator::MALFORMED],
            'attributes on bold' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'bold', 'attrs' => ['style' => 'x']]]]]]]], 'blocks.0.content.content.0.content.0.marks.0.attrs', EmailDesignValidator::UNKNOWN_FIELD],
            'link without attrs' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link']]]]]]], 'blocks.0.content.content.0.content.0.marks.0.attrs', EmailDesignValidator::MISSING_FIELD],
            'link without href' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['target' => '_blank']]]]]]]], 'blocks.0.content.content.0.content.0.marks.0.attrs.href', EmailDesignValidator::MISSING_FIELD],
            'link with an event handler' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://viteseo.ph', 'onclick' => 'alert(1)']]]]]]]], 'blocks.0.content.content.0.content.0.marks.0.attrs.onclick', EmailDesignValidator::UNKNOWN_FIELD],
            'link class that is not a string' => [['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://viteseo.ph', 'class' => ['a']]]]]]]]], 'blocks.0.content.content.0.content.0.marks.0.attrs.class', EmailDesignValidator::MALFORMED],
            'list start of zero' => [['type' => 'doc', 'content' => [['type' => 'orderedList', 'attrs' => ['start' => 0], 'content' => [$item]]]], 'blocks.0.content.content.0.attrs.start', sprintf(EmailDesignValidator::OUT_OF_RANGE, 1, 100000)],
            'list start as a string' => [['type' => 'doc', 'content' => [['type' => 'orderedList', 'attrs' => ['start' => '3'], 'content' => [$item]]]], 'blocks.0.content.content.0.attrs.start', sprintf(EmailDesignValidator::OUT_OF_RANGE, 1, 100000)],
            'unknown list type' => [['type' => 'doc', 'content' => [['type' => 'orderedList', 'attrs' => ['type' => 'disc'], 'content' => [$item]]]], 'blocks.0.content.content.0.attrs.type', EmailDesignValidator::MALFORMED],
            'unknown list attribute' => [['type' => 'doc', 'content' => [['type' => 'orderedList', 'attrs' => ['reversed' => true], 'content' => [$item]]]], 'blocks.0.content.content.0.attrs.reversed', EmailDesignValidator::UNKNOWN_FIELD],
            'attrs on a bullet list' => [['type' => 'doc', 'content' => [['type' => 'bulletList', 'attrs' => ['start' => 2], 'content' => [$item]]]], 'blocks.0.content.content.0.attrs', EmailDesignValidator::UNKNOWN_FIELD],
        ];
    }

    /** @param  array<string, mixed>  $doc */
    #[DataProvider('malformedDocuments')]
    public function test_malformed_documents_are_rejected(array $doc, string $path, string $message): void
    {
        $this->assertContentProblem($path, $message, $this->content([$this->textBlock(['content' => $doc])]));
    }

    public function test_a_block_document_has_a_total_character_limit(): void
    {
        $half = str_repeat('a', 2500);
        $atLimit = $this->doc($this->para($this->text($half)), $this->para($this->text(substr($half, 1)), $this->hardBreak()));
        $overLimit = $this->doc($this->para($this->text($half)), $this->para($this->text($half), $this->hardBreak()));
        $message = sprintf(EmailDesignValidator::TEXT_TOO_LONG, EmailDesignSchema::MAX_BLOCK_TEXT);

        $this->assertNull($this->validator()->contentProblem($this->content([$this->textBlock(['content' => $atLimit])])));
        $this->assertContentProblem('blocks.0.content', $message, $this->content([$this->textBlock(['content' => $overLimit])]));
        $this->assertLayoutProblem('footer.0.content', $message, $this->layout([], [$this->footer(['content' => $overLimit])]));
    }

    // ── Asset references ─────────────────────────────────────────────────────────────────────

    public function test_referenced_asset_ids_are_listed_once_in_order_across_documents(): void
    {
        $other = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $layout = $this->layout([$this->image(['id' => 'header-image', 'asset_id' => $other])], []);
        $content = $this->content([$this->image(['id' => 'a']), $this->image(['id' => 'b', 'asset_id' => $other])]);

        $this->assertNull($this->validator()->layoutProblem($layout));
        $this->assertNull($this->validator()->contentProblem($content));
        $this->assertSame([$other, self::ASSET_ID], EmailDesignSchema::referencedAssetIds($layout, $content));
    }
}
