<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support\EmailDesign;

use InvalidArgumentException;

/**
 * The only code that turns a design and a template's content into a message: the text/html part and the
 * text/plain part, both from the same two validated documents and the same context.
 *
 * ── Composition ──
 * header (design) · content blocks (template) · signature area (design, with the template's sign-off when
 * it sets one) · footer (design). A template never renders a header, footer or signature of its own, so
 * every template that uses a design shares its look, and a change to the design reaches all of them.
 *
 * ── The HTML ──
 * A table-based, single-column layout: a full-width page table, then a content table that is 100% wide up
 * to 600px (with a fixed 600px ghost table for Outlook desktop, which ignores max-width). Every style is
 * inline; there is no <style> element, no class, no script and no external resource except images the
 * context resolved. Each block is one row inset from the column's sides (EmailDesignSchema::CONTENT_PADDING,
 * resolved once per render and passed down to every row), so images and buttons scale down on a phone without
 * a media query. A preheader, when set, is the first thing in <body>: hidden text that inbox
 * lists show beside the subject.
 *
 * ── Styles ──
 * Resolved once per render by {@see effectiveStyles()}: the template's style override, then the design's
 * style, then the organization's branding from the context, then EmailDesignSchema::SYSTEM_DEFAULTS. A block
 * color the block does not set falls back to its style in EmailDesignSchema::BLOCK_COLOR_FALLBACKS. The optional
 * additional styles (card, eyebrow, secondary button, content padding, container radius) resolve through the same
 * chain in {@see additionalStyles()}, and each is written only where it is used, so a document that sets none
 * of them renders byte for byte as it did before they existed.
 *
 * ── Escaping ──
 * Nothing from the documents or the context reaches the output unescaped. Placeholders are substituted in a
 * single pass — a value that itself contains `{{token}}` is never expanded again — and the result is escaped
 * as a whole. Token values also lose control characters, line separators and bidi overrides, so a lead's
 * data cannot add lines to the text part or reorder text. URLs come only from validated fields; a context
 * image URL that is not a plain absolute http(s) address omits its image, a brand website that is not an
 * allowed link leaves the logo unlinked, and a brand style that is not a known font or hex color is ignored.
 *
 * ── Determinism ──
 * The same documents and context always produce byte-identical output: block ids are never written, marks
 * nest in a fixed order, colors are lowercased, and no time, random value or key order is involved.
 */
final class EmailDesignRenderer
{
    /** The text part's stand-in for a divider. Deliberately not `-- `, the signature delimiter. */
    private const TEXT_RULE = '----------------------------------------';

    /** Stripped from placeholder values and context text before either part is written. */
    private const UNSAFE_VALUE_CHARACTERS = '/[\x00-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u';

    /** The largest PNG a Settings preview inlines. */
    private const MAX_PREVIEW_IMAGE_BYTES = 3_000_000;

    /** Alignment → the margin that places a fixed-width table or block image in its row. */
    private const MARGINS = ['left' => '0 auto 0 0', 'center' => '0 auto', 'right' => '0 0 0 auto'];

    /** Vertical space above the first block and below the last. */
    private const CANVAS_PADDING = 16;

    /** Vertical padding above and below every block row. */
    private const ROW_VERTICAL_PADDING = 8;

    /** Invisible characters after the preheader, so a client does not fill the preview with body text. */
    private const PREHEADER_FILLER = '&#847;&zwnj;&nbsp;';

    private const PREHEADER_FILLER_REPEAT = 30;

    private const HEX_COLOR = '/^#[0-9A-Fa-f]{6}\z/';

    public function __construct(private readonly EmailDesignValidator $validator = new EmailDesignValidator) {}

    /**
     * The text/html part.
     *
     * @param  array<string, mixed>  $layout  a design's layout
     * @param  array<string, mixed>  $content  a template's content
     *
     * @throws InvalidArgumentException when either document does not validate
     */
    public function toHtml(array $layout, array $content, EmailDesignContext $context): string
    {
        $this->assertValid($layout, $content);

        $theme = $this->effectiveStyles($layout['styles'], $content['style_overrides'] ?? [], $context);
        $extra = $this->additionalStyles($layout['styles'], $content['style_overrides'] ?? [], $context, $theme);
        $page = $theme['background_color'];
        $canvas = $theme['content_background_color'];
        // Rounded corners need separate borders: a collapsed table ignores border-radius. Written only when set,
        // so a square email's table is unchanged.
        $canvasCorners = $extra['container_radius'] > 0
            ? 'border-collapse:separate;border-spacing:0;border-radius:'.$extra['container_radius'].'px;'
            : 'border-collapse:collapse;';
        $font = $this->escape(EmailDesignSchema::FONTS[$theme['font']]);
        $tokens = $this->cleanTokens($context->tokens);

        $lines = [
            '<!DOCTYPE html>',
            '<html lang="en">',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<meta http-equiv="X-UA-Compatible" content="IE=edge">',
            '<meta name="x-apple-disable-message-reformatting">',
            '<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">',
            '<meta name="color-scheme" content="light">',
            '<meta name="supported-color-schemes" content="light">',
            '<title></title>',
            '</head>',
            '<body style="margin:0;padding:0;width:100%;background-color:'.$page.';">',
        ];

        $preheader = $this->preheaderHtml($content['preheader'] ?? null, $tokens);
        if ($preheader !== '') {
            $lines[] = $preheader;
        }

        array_push(
            $lines,
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="'.$page.'" style="width:100%;border-collapse:collapse;background-color:'.$page.';">',
            '<tr>',
            '<td align="center" style="padding:24px 12px;">',
            '<!--[if mso]><table role="presentation" width="'.EmailDesignSchema::CONTENT_WIDTH.'" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->',
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="'.$canvas.'" style="width:100%;max-width:'.EmailDesignSchema::CONTENT_WIDTH.'px;'.$canvasCorners.'background-color:'.$canvas.';">',
            $this->spacerRow(self::CANVAS_PADDING),
        );

        // The side inset of every block row: the design's content padding, EmailDesignSchema::CONTENT_PADDING
        // unless set. Resolved here, once, so a row never reaches for it itself.
        $inset = $extra['content_padding'];

        $rows = [];
        foreach ([...$layout['header'], ...$content['blocks']] as $block) {
            $rows[] = $this->blockHtml($block, $theme, $extra, $font, $tokens, $context, $inset);
        }
        $rows[] = $this->signatureRow($layout['signature'], $content, $font, $theme, $tokens, $context, $inset);
        foreach ($layout['footer'] as $block) {
            $rows[] = $this->blockHtml($block, $theme, $extra, $font, $tokens, $context, $inset);
        }

        foreach ($rows as $row) {
            if ($row !== '') {
                $lines[] = $row;
            }
        }

        array_push(
            $lines,
            $this->spacerRow(self::CANVAS_PADDING),
            '</table>',
            '<!--[if mso]></td></tr></table><![endif]-->',
            '</td>',
            '</tr>',
            '</table>',
            '</body>',
            '</html>',
        );

        return implode("\n", $lines);
    }

    /**
     * The text/plain part: every part's readable content in the same order as the HTML, separated by a
     * blank line. Links keep their URL — "Visit us (https://…)", "Book a call: https://…" — because a
     * plain-text reader has no other way to follow them. Logos, spacers and unlinked images have no text
     * form, and the preheader is an HTML inbox preview only.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $content
     *
     * @throws InvalidArgumentException when either document does not validate
     */
    public function toText(array $layout, array $content, EmailDesignContext $context): string
    {
        $this->assertValid($layout, $content);

        $tokens = $this->cleanTokens($context->tokens);

        $parts = [];
        foreach ([...$layout['header'], ...$content['blocks']] as $block) {
            $parts[] = $this->blockText($block, $tokens);
        }
        $parts[] = implode("\n", [
            ...array_column($this->signatureLines($layout['signature'], $content, $tokens), 0),
            ...$this->signatureExtraText($layout['signature'], $context),
        ]);
        foreach ($layout['footer'] as $block) {
            $parts[] = $this->blockText($block, $tokens);
        }

        $parts = array_values(array_filter(array_map(fn (string $part): string => trim($part, "\n"), $parts), fn (string $part): bool => $part !== ''));
        $text = implode("\n", array_map('rtrim', explode("\n", implode("\n\n", $parts))));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * The styles an email renders with: each of EmailDesignSchema::SYSTEM_DEFAULTS' keys resolved from the
     * template's style overrides, then the design's styles, then the context's brand styles, then the system
     * default. A value that is not a known font key or a six-digit hex color is skipped at every step.
     * Colors come back lowercased.
     *
     * @param  array<string, mixed>  $designStyles
     * @param  array<string, mixed>  $overrides
     * @return array<string, string>
     */
    public function effectiveStyles(array $designStyles, array $overrides, EmailDesignContext $context): array
    {
        $styles = [];
        foreach (EmailDesignSchema::SYSTEM_DEFAULTS as $key => $default) {
            $styles[$key] = $this->styleValue($key, $overrides[$key] ?? null)
                ?? $this->styleValue($key, $designStyles[$key] ?? null)
                ?? $this->styleValue($key, $context->brandStyles[$key] ?? null)
                ?? $default;
        }

        return $styles;
    }

    /**
     * The optional additional styles an email renders with, through the same chain as {@see effectiveStyles()}:
     * the template's override, the design's style, the context's brand style, then — for a color — the resolved
     * style EmailDesignSchema::ADDITIONAL_COLOR_STYLES names, and for a number its default. A color that is not a
     * six-digit hex, a number that is not a whole number in its range, or a switch that is not a boolean, is skipped
     * at every step.
     *
     * @param  array<string, mixed>  $designStyles
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $theme  the effectiveStyles() of the same render
     * @return array<string, string|int|bool>
     */
    public function additionalStyles(array $designStyles, array $overrides, EmailDesignContext $context, array $theme): array
    {
        $styles = [];
        foreach (EmailDesignSchema::ADDITIONAL_COLOR_STYLES as $key => $fallback) {
            $styles[$key] = $this->styleValue($key, $overrides[$key] ?? null)
                ?? $this->styleValue($key, $designStyles[$key] ?? null)
                ?? $this->styleValue($key, $context->brandStyles[$key] ?? null)
                ?? $theme[$fallback];
        }
        foreach (EmailDesignSchema::NUMERIC_STYLES as $key => [$default, $range]) {
            $styles[$key] = $this->numericStyle($overrides[$key] ?? null, $range)
                ?? $this->numericStyle($designStyles[$key] ?? null, $range)
                ?? $this->numericStyle($context->brandStyles[$key] ?? null, $range)
                ?? $default;
        }
        foreach (EmailDesignSchema::BOOLEAN_STYLES as $key => $default) {
            $styles[$key] = $this->booleanStyle($overrides[$key] ?? null)
                ?? $this->booleanStyle($designStyles[$key] ?? null)
                ?? $this->booleanStyle($context->brandStyles[$key] ?? null)
                ?? $default;
        }

        return $styles;
    }

    // ── HTML blocks ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $theme
     * @param  array<string, string>  $tokens
     * @param  array<string, string|int>  $extra  the render's additionalStyles()
     * @param  int  $inset  the row's side padding in px
     */
    private function blockHtml(array $block, array $theme, array $extra, string $font, array $tokens, EmailDesignContext $context, int $inset): string
    {
        return match ($block['type']) {
            'logo' => $this->imageRow($context->logoUrl, $block['width'], $this->cleanValue($context->logoAlt), $block['align'], $this->logoLink($block, $context), $context, $inset),
            'heading' => $this->headingRow($block, $font, $tokens, $this->blockColor($block, 'color', $theme), $inset),
            'text' => $this->documentRow($block, $font, $this->blockColor($block, 'color', $theme), $this->linkCss($theme, $extra), $tokens, 12, $inset),
            'button' => $this->buttonRow($block, $font, $tokens, $this->buttonColor($block, 'background_color', $theme, $extra), $this->buttonColor($block, 'text_color', $theme, $extra), $inset),
            'image' => $this->imageRow($context->assetUrls[$block['asset_id']] ?? null, $block['width'], $block['alt'], $block['align'], $block['link_url'] ?? null, $context, $inset),
            'divider' => $this->dividerRow($block, $this->blockColor($block, 'color', $theme), $inset),
            'spacer' => $this->spacerRow($block['height']),
            'footer' => $this->documentRow($block, $font, $this->blockColor($block, 'color', $theme), $this->linkCss($theme, $extra), $tokens, 8, $inset),
            'card' => $this->cardRow($block, $theme, $extra, $font, $tokens, $context, $inset),
            'rows' => $this->rowsRow($block, $theme, $font, $tokens, $inset),
            'actions' => $this->actionsRow($block, $theme, $extra, $font, $tokens, $inset),
            // Unreachable for a validated document, and deliberately renders nothing.
            default => '',
        };
    }

    /**
     * A content card: a full-width panel in the card background, its corners, border and padding from the design,
     * holding its eyebrow and title and then its own blocks. Its blocks are drawn by blockHtml() exactly as at the
     * top level, inset by the card padding instead of the column's — and since a card never holds a card, this
     * recursion is one level deep. Rounded corners need separate borders; Outlook desktop shows them square.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $theme
     * @param  array<string, string|int>  $extra
     * @param  array<string, string>  $tokens
     */
    private function cardRow(array $block, array $theme, array $extra, string $font, array $tokens, EmailDesignContext $context, int $inset): string
    {
        $background = $this->styleValue('background_color', $block['background_color'] ?? null) ?? (string) $extra['card_background_color'];
        $borderColor = $this->styleValue('border_color', $block['border_color'] ?? null) ?? (string) $extra['card_border_color'];
        $padding = (int) $extra['card_padding'];
        $radius = (int) $extra['card_radius'];
        $borderWidth = (int) $extra['card_border_width'];

        $rows = [];
        $heading = $this->cardHeadingHtml($block, $theme, $extra, $font, $tokens);
        if ($heading !== '') {
            $rows[] = $this->row($block['align'], $heading, $padding);
        }
        foreach ($block['blocks'] as $inner) {
            $rows[] = $this->blockHtml($inner, $theme, $extra, $font, $tokens, $context, $padding);
        }
        $rows = array_filter($rows, fn (string $row): bool => $row !== '');
        if ($rows === []) {
            return '';
        }

        $frame = 'width:100%;border-collapse:separate;border-spacing:0;background-color:'.$background.';'
            .($radius > 0 ? 'border-radius:'.$radius.'px;' : '')
            .($borderWidth > 0 ? 'border:'.$borderWidth.'px solid '.$borderColor.';' : '');

        return '<tr><td style="'.$this->rowPadding($inset).'">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="'.$background.'" style="'.$frame.'">'
            .'<tr><td style="padding:'.max($padding - self::ROW_VERTICAL_PADDING, 0).'px 0;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">'
            .implode('', $rows)
            .'</table></td></tr></table></td></tr>';
    }

    /**
     * A card's eyebrow and title, or nothing when neither has visible text once its placeholders are filled in.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $theme
     * @param  array<string, string|int>  $extra
     * @param  array<string, string>  $tokens
     */
    private function cardHeadingHtml(array $block, array $theme, array $extra, string $font, array $tokens): string
    {
        [$eyebrow, $title] = $this->cardHeadingText($block, $tokens);

        $html = '';
        if ($eyebrow !== '') {
            $html .= '<p style="margin:'.($title !== '' ? '0 0 4px 0' : '0').';font-family:'.$font.';font-size:'.EmailDesignSchema::CARD_EYEBROW_SIZE.'px;'
                .'line-height:1.4;font-weight:bold;color:'.$extra['eyebrow_color'].';">'.$this->escape($eyebrow).'</p>';
        }
        if ($title !== '') {
            $html .= '<p style="margin:0;font-family:'.$font.';font-size:'.EmailDesignSchema::CARD_TITLE_SIZE.'px;'
                .'line-height:1.3;font-weight:bold;color:'.$theme['text_color'].';">'.$this->escape($title).'</p>';
        }

        return $html;
    }

    /**
     * A rows block: each item's title over its description, optionally with a rule between items. An item
     * whose title and description are both blank once filled in is left out.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $theme
     * @param  array<string, string>  $tokens
     */
    private function rowsRow(array $block, array $theme, string $font, array $tokens, int $inset): string
    {
        $items = $this->rowsItems($block, $tokens);
        if ($items === []) {
            return '';
        }

        $align = $this->escape($block['align']);
        $titleColor = $this->blockColor($block, 'title_color', $theme);
        $descriptionColor = $this->blockColor($block, 'description_color', $theme);
        $ruleColor = $this->blockColor($block, 'divider_color', $theme);
        $last = count($items) - 1;

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">';
        foreach ($items as $i => [$title, $description]) {
            if ($i > 0 && $block['dividers'] === true) {
                $html .= '<tr><td height="1" bgcolor="'.$ruleColor.'" style="height:1px;font-size:0;line-height:0;background-color:'.$ruleColor.';">&nbsp;</td></tr>';
            }
            $html .= '<tr><td align="'.$align.'" style="padding:'.($i === 0 ? 0 : 10).'px 0 '.($i === $last ? 0 : 10).'px 0;text-align:'.$align.';">';
            if ($title !== '') {
                $html .= '<p style="margin:0;font-family:'.$font.';font-size:'.EmailDesignSchema::ROW_TITLE_SIZE.'px;line-height:1.4;font-weight:bold;color:'.$titleColor.';">'
                    .$this->escape($title).'</p>';
            }
            if ($description !== '') {
                $html .= '<p style="margin:'.($title !== '' ? '4px 0 0 0' : '0').';font-family:'.$font.';font-size:'.EmailDesignSchema::ROW_DESCRIPTION_SIZE.'px;line-height:1.5;color:'.$descriptionColor.';">'
                    .$this->escape($description).'</p>';
            }
            $html .= '</td></tr>';
        }

        return $this->row($block['align'], $html.'</table>', $inset);
    }

    private function row(string $align, string $inner, int $inset): string
    {
        $align = $this->escape($align);

        return '<tr><td align="'.$align.'" style="'.$this->rowPadding($inset).'text-align:'.$align.';">'.$inner.'</td></tr>';
    }

    /** A block row's padding declaration: the fixed vertical padding and the given side inset. */
    private function rowPadding(int $inset): string
    {
        return 'padding:'.self::ROW_VERTICAL_PADDING.'px '.$inset.'px;';
    }

    private function spacerRow(int $height): string
    {
        return '<tr><td height="'.$height.'" style="height:'.$height.'px;font-size:0;line-height:0;">&nbsp;</td></tr>';
    }

    /**
     * Hidden preview text, when the content has a preheader that is not blank once its placeholders are
     * filled in.
     *
     * @param  array<string, string>  $tokens
     */
    private function preheaderHtml(mixed $preheader, array $tokens): string
    {
        $text = is_string($preheader) ? trim($this->substitute($preheader, $tokens)) : '';
        if ($text === '') {
            return '';
        }

        return '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'
            .$this->escape($text)
            .str_repeat(self::PREHEADER_FILLER, self::PREHEADER_FILLER_REPEAT)
            .'</div>';
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $tokens
     */
    private function headingRow(array $block, string $font, array $tokens, string $color, int $inset): string
    {
        $level = (int) $block['level'];
        $size = is_int($block['font_size'] ?? null) ? $block['font_size'] : EmailDesignSchema::HEADING_SIZES[$level];
        $style = 'margin:0;font-family:'.$font.';font-size:'.$size.'px;line-height:1.25;'
            .'font-weight:bold;color:'.$color.';text-align:'.$this->escape($block['align']).';';

        return $this->row(
            $block['align'],
            '<h'.$level.' style="'.$style.'">'.$this->escape($this->substitute($block['text'], $tokens)).'</h'.$level.'>',
            $inset,
        );
    }

    /**
     * A text or footer block. Styles repeat on every paragraph and list because Outlook desktop does not
     * reliably inherit them from the cell.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $tokens
     */
    private function documentRow(array $block, string $font, string $color, string $linkCss, array $tokens, int $gap, int $inset): string
    {
        $base = 'font-family:'.$font.';font-size:'.(int) $block['font_size'].'px;line-height:1.5;color:'.$color.';';
        $nodes = $block['content']['content'];
        $last = count($nodes) - 1;

        $html = '';
        foreach ($nodes as $i => $node) {
            $margin = $i === $last ? '0' : '0 0 '.$gap.'px 0';
            $html .= $node['type'] === 'paragraph'
                ? '<p style="margin:'.$margin.';'.$base.'">'.$this->inlineHtml($node, $linkCss, $tokens, '&nbsp;').'</p>'
                : $this->listHtml($node, $margin, $base, $linkCss, $tokens);
        }

        return $this->row($block['align'], $html, $inset);
    }

    /**
     * @param  array<string, mixed>  $list
     * @param  array<string, string>  $tokens
     */
    private function listHtml(array $list, string $margin, string $base, string $linkCss, array $tokens): string
    {
        $tag = $list['type'] === 'orderedList' ? 'ol' : 'ul';
        $start = $tag === 'ol' ? $this->listStart($list) : 1;

        $html = '<'.$tag.($start > 1 ? ' start="'.$start.'"' : '').' style="margin:'.$margin.';padding:0 0 0 24px;'.$base.'">';
        foreach ($list['content'] as $item) {
            $inner = '';
            $afterParagraph = false;
            foreach ($item['content'] as $child) {
                if ($child['type'] === 'paragraph') {
                    $inner .= ($afterParagraph ? '<br>' : '').$this->inlineHtml($child, $linkCss, $tokens, '');
                    $afterParagraph = true;
                } else {
                    $inner .= $this->listHtml($child, '4px 0 0 0', $base, $linkCss, $tokens);
                    $afterParagraph = false;
                }
            }
            $html .= '<li style="margin:0;'.$base.'">'.$inner.'</li>';
        }

        return $html.'</'.$tag.'>';
    }

    /**
     * @param  array<string, mixed>  $paragraph
     * @param  array<string, string>  $tokens
     */
    private function inlineHtml(array $paragraph, string $linkCss, array $tokens, string $empty): string
    {
        $html = '';
        foreach ($paragraph['content'] ?? [] as $node) {
            $html .= $node['type'] === 'hardBreak' ? '<br>' : $this->textHtml($node, $linkCss, $tokens);
        }

        return $html === '' ? $empty : $html;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $tokens
     */
    private function textHtml(array $node, string $linkCss, array $tokens): string
    {
        $present = [];
        $href = null;
        foreach ($node['marks'] ?? [] as $mark) {
            if ($mark['type'] === EmailDesignSchema::LINK_MARK) {
                $href = $mark['attrs']['href'];
            } else {
                $present[$mark['type']] = true;
            }
        }

        $html = $this->escape($this->substitute($node['text'], $tokens));

        // Innermost first, in the canonical order; a link always wraps the formatting.
        foreach (array_reverse(EmailDesignSchema::MARK_TAGS, true) as $mark => $tag) {
            if (isset($present[$mark])) {
                $html = '<'.$tag.'>'.$html.'</'.$tag.'>';
            }
        }

        return $href === null
            ? $html
            : '<a href="'.$this->escape($href).'" target="_blank" rel="noopener noreferrer" style="'.$linkCss.'">'.$html.'</a>';
    }

    /**
     * A "bulletproof" button: the color sits on a table cell, so clients that drop padding on links (Outlook
     * desktop) still show a colored, clickable block.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $tokens
     */
    private function buttonRow(array $block, string $font, array $tokens, string $background, string $labelColor, int $inset): string
    {
        return $this->row(
            $block['align'],
            $this->buttonTable($block['url'], $this->substitute($block['label'], $tokens), $font, $background, $labelColor, (int) $block['radius'], self::MARGINS[$block['align']]),
            $inset,
        );
    }

    /**
     * The bulletproof button itself — a colored cell holding the link — shared by button blocks and the buttons of
     * an actions row.
     */
    private function buttonTable(string $url, string $label, string $font, string $background, string $labelColor, int $radius, string $margin): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:'.$margin.';">'
            .'<tr><td align="center" bgcolor="'.$background.'" style="border-radius:'.$radius.'px;background-color:'.$background.';">'
            .'<a href="'.$this->escape($url).'" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 24px;'
            .'font-family:'.$font.';font-size:16px;line-height:20px;font-weight:bold;color:'.$labelColor.';'
            .'text-decoration:none;border-radius:'.$radius.'px;">'
            .$this->escape($label)
            .'</a></td></tr></table>';
    }

    /**
     * A row of calls to action side by side: each button or link an inline block, so they share a line and wrap
     * onto the next on a narrow screen without a media query. Outlook desktop ignores inline blocks, so a table
     * only it reads keeps them on one line there. A link is as tall as a button, so the two line up.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $theme
     * @param  array<string, string|int|bool>  $extra
     * @param  array<string, string>  $tokens
     */
    private function actionsRow(array $block, array $theme, array $extra, string $font, array $tokens, int $inset): string
    {
        $items = $this->actionItems($block, $tokens);
        if ($items === []) {
            return '';
        }

        $align = $this->escape($block['align']);
        $html = '<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="'.$align.'"><tr><![endif]-->';
        foreach ($items as [$item, $label]) {
            if ($item['kind'] === 'button') {
                $secondary = ($item['variant'] ?? null) === 'secondary';
                $inner = $this->buttonTable(
                    $item['url'],
                    $label,
                    $font,
                    $secondary ? (string) $extra['secondary_color'] : $theme['accent_color'],
                    $secondary ? (string) $extra['secondary_text_color'] : $theme['accent_text_color'],
                    (int) $block['radius'],
                    '0',
                );
            } else {
                $inner = '<a href="'.$this->escape($item['url']).'" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 0;'
                    .'font-family:'.$font.';font-size:16px;line-height:20px;'.$this->linkCss($theme, $extra).'">'.$this->escape($label).'</a>';
            }

            $html .= '<!--[if mso]><td style="padding:0 6px;"><![endif]-->'
                .'<div style="display:inline-block;vertical-align:middle;margin:4px 6px;">'.$inner.'</div>'
                .'<!--[if mso]></td><![endif]-->';
        }

        return $this->row($block['align'], $html.'<!--[if mso]></tr></table><![endif]-->', $inset);
    }

    /**
     * An actions block's items with their labels filled in, leaving out any whose label is blank once filled in.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $tokens
     * @return list<array{array<string, mixed>, string}>
     */
    private function actionItems(array $block, array $tokens): array
    {
        $items = [];
        foreach ($block['items'] as $item) {
            $label = trim($this->substitute($item['label'], $tokens));
            if ($label !== '') {
                $items[] = [$item, $label];
            }
        }

        return $items;
    }

    /**
     * How a link is drawn: the link color, underlined unless the design's `link_underline` turns it off.
     *
     * @param  array<string, string>  $theme
     * @param  array<string, string|int|bool>  $extra
     */
    private function linkCss(array $theme, array $extra): string
    {
        return 'color:'.$theme['link_color'].';text-decoration:'.($extra['link_underline'] === false ? 'none' : 'underline').';';
    }

    /**
     * An image or logo row, or nothing when the context gave no safe URL for it.
     *
     * A Settings preview context may carry the PNG bytes for a URL (EmailDesignContext::$previewImages); the
     * image is then inlined as a data URI — but only once the URL itself passed the same check, and only when
     * the bytes are a PNG. A data URI is never accepted AS a URL from the context.
     */
    private function imageRow(mixed $url, int $width, string $alt, string $align, ?string $link, EmailDesignContext $context, int $inset): string
    {
        $src = $this->safeImageUrl($url);
        if ($src === null) {
            return '';
        }

        // Never wider than the row it sits in. With the default inset every allowed width already fits; a wider
        // content padding narrows the row, and Outlook sizes an image by its width attribute alone.
        $width = min($width, EmailDesignSchema::CONTENT_WIDTH - 2 * $inset);

        $inline = $context->previewImages[$src] ?? null;
        if (is_string($inline) && strlen($inline) <= self::MAX_PREVIEW_IMAGE_BYTES && str_starts_with($inline, "\x89PNG\r\n\x1a\n")) {
            $src = 'data:image/png;base64,'.base64_encode($inline);
        }

        // The chosen width IS the image's width: the attribute (Outlook) and inline `width` both carry it, and
        // max-width:100% only lets it shrink on a screen narrower than that. Sizing through max-width instead would
        // be overridden by any client or viewer that forces `img{max-width:100%}`, which then shows the image at
        // the full column width whatever width was chosen.
        $image = '<img src="'.$this->escape($src).'" width="'.$width.'" alt="'.$this->escape($alt).'" style="display:block;width:'.$width.'px;'
            .'max-width:100%;height:auto;margin:'.self::MARGINS[$align].';border:0;outline:none;text-decoration:none;">';

        if ($link !== null) {
            $image = '<a href="'.$this->escape($link).'" target="_blank" rel="noopener noreferrer" style="display:block;text-decoration:none;">'.$image.'</a>';
        }

        return $this->row($align, $image, $inset);
    }

    /**
     * Where a logo links: its custom address, the organization website when the context has an allowed one,
     * or nowhere.
     *
     * @param  array<string, mixed>  $block
     */
    private function logoLink(array $block, EmailDesignContext $context): ?string
    {
        return match (EmailDesignSchema::logoLinkTarget($block)) {
            'custom' => $block['link_url'],
            'website' => is_string($context->brandWebsite) && $this->validator->isAllowedLinkUrl($context->brandWebsite)
                ? $context->brandWebsite
                : null,
            default => null,
        };
    }

    /** @param  array<string, mixed>  $block */
    private function dividerRow(array $block, string $color, int $inset): string
    {
        $thickness = (int) $block['thickness'];

        return '<tr><td style="'.$this->rowPadding($inset).'">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">'
            .'<tr><td height="'.$thickness.'" bgcolor="'.$color.'" style="height:'.$thickness.'px;font-size:0;line-height:0;background-color:'.$color.';">&nbsp;</td></tr>'
            .'</table></td></tr>';
    }

    /**
     * The design's signature area, or nothing when it is switched off or would print no line.
     *
     * @param  array<string, mixed>  $signature
     * @param  array<string, mixed>  $content
     * @param  array<string, string>  $tokens
     */
    private function signatureRow(array $signature, array $content, string $font, array $theme, array $tokens, EmailDesignContext $context, int $inset): string
    {
        $lines = $this->signatureLines($signature, $content, $tokens);
        $extras = $this->signatureExtras($signature, $context);
        if ($lines === [] && $extras === []) {
            return '';
        }

        $base = 'font-family:'.$font.';font-size:14px;line-height:1.5;color:'.$theme['text_color'].';';
        $html = '';
        $count = count($lines) + count($extras);
        foreach ($lines as [$text, $isSignOff, $isName]) {
            $margin = $isSignOff && $count > 1 ? '0 0 8px 0' : '0';
            $escaped = $this->escape($text);
            $html .= '<p style="margin:'.$margin.';'.$base.'">'.($isName ? '<strong>'.$escaped.'</strong>' : $escaped).'</p>';
        }

        if (isset($extras['company'])) {
            $html .= '<p style="margin:0;font-family:'.$font.';font-size:14px;line-height:1.5;color:'.$theme['muted_text_color'].';">'.$this->escape($extras['company']).'</p>';
        }

        $contacts = [];
        $linkStyle = 'color:'.$theme['link_color'].';text-decoration:none;';
        if (isset($extras['email'])) {
            $email = $this->escape($extras['email']);
            $contacts[] = '<a href="mailto:'.$email.'" style="'.$linkStyle.'">'.$email.'</a>';
        }
        if (isset($extras['phone'])) {
            $tel = $this->telNumber($extras['phone']);
            $phone = $this->escape($extras['phone']);
            $contacts[] = $tel === null ? $phone : '<a href="tel:'.$tel.'" style="'.$linkStyle.'">'.$phone.'</a>';
        }
        if ($contacts !== []) {
            $html .= '<p style="margin:0;'.$base.'">'.implode(' &middot; ', $contacts).'</p>';
        }

        return $this->row($signature['align'], $html, $inset);
    }

    /**
     * The signature's optional lines the design switched on and the context has a usable value for: the
     * organization's name, its reply address and the sender's phone. Nothing when the signature is off.
     *
     * @param  array<string, mixed>  $signature
     * @return array{company?: string, email?: string, phone?: string}
     */
    private function signatureExtras(array $signature, EmailDesignContext $context): array
    {
        if ($signature['enabled'] !== true) {
            return [];
        }

        $extras = [];
        $company = trim($this->cleanValue($context->organizationName));
        if (($signature['show_company'] ?? false) === true && $company !== '') {
            $extras['company'] = $company;
        }
        $email = trim($this->cleanValue((string) $context->organizationEmail));
        if (($signature['show_email'] ?? false) === true && $this->isPlainEmail($email)) {
            $extras['email'] = $email;
        }
        $phone = trim($this->cleanValue((string) $context->senderPhone));
        if (($signature['show_phone'] ?? false) === true && $phone !== '') {
            $extras['phone'] = $phone;
        }

        return $extras;
    }

    /**
     * The signature's optional lines as text: the company, then the email and phone on one line.
     *
     * @param  array<string, mixed>  $signature
     * @return list<string>
     */
    private function signatureExtraText(array $signature, EmailDesignContext $context): array
    {
        $extras = $this->signatureExtras($signature, $context);
        $lines = isset($extras['company']) ? [$extras['company']] : [];
        $contacts = array_values(array_filter([$extras['email'] ?? null, $extras['phone'] ?? null]));

        return $contacts === [] ? $lines : [...$lines, implode(' · ', $contacts)];
    }

    /** An address safe to write into a mailto link: one @, and nothing that could end or extend the URL. */
    private function isPlainEmail(string $email): bool
    {
        return strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('~^[^\s"\'<>?&#%\\\\/:;,()\[\]]+@[A-Za-z0-9.-]+\z~', $email) === 1;
    }

    /** The digits a tel: link dials — an optional leading + and 3 to 20 digits — or null to show plain text. */
    private function telNumber(string $phone): ?string
    {
        if (preg_match('/^\+?[0-9 ().-]{3,40}\z/', $phone) !== 1) {
            return null;
        }
        $digits = (str_starts_with($phone, '+') ? '+' : '').(string) preg_replace('/\D/', '', $phone);

        return preg_match('/^\+?[0-9]{3,20}\z/', $digits) === 1 ? $digits : null;
    }

    // ── Text blocks ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $tokens
     */
    private function blockText(array $block, array $tokens): string
    {
        return match ($block['type']) {
            'heading' => $this->substitute($block['text'], $tokens),
            'text', 'footer' => $this->documentText($block['content'], $tokens),
            'button' => $this->substitute($block['label'], $tokens).': '.$block['url'],
            'image' => ($block['link_url'] ?? null) === null
                ? ''
                : ($block['alt'] === '' ? $block['link_url'] : $block['alt'].': '.$block['link_url']),
            'divider' => self::TEXT_RULE,
            'card' => $this->cardText($block, $tokens),
            'actions' => implode("\n", array_map(fn (array $pair): string => $pair[1].': '.$pair[0]['url'], $this->actionItems($block, $tokens))),
            'rows' => implode("\n\n", array_map(
                fn (array $item): string => trim($item[0]."\n".$item[1], "\n"),
                $this->rowsItems($block, $tokens),
            )),
            default => '',
        };
    }

    /**
     * A card as text: its eyebrow and title, then each of its blocks' text, a blank line apart.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $tokens
     */
    private function cardText(array $block, array $tokens): string
    {
        [$eyebrow, $title] = $this->cardHeadingText($block, $tokens);
        $heading = trim($eyebrow."\n".$title, "\n");

        $parts = $heading === '' ? [] : [$heading];
        foreach ($block['blocks'] as $inner) {
            $part = trim($this->blockText($inner, $tokens), "\n");
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * A card's eyebrow and title with placeholders filled in and edges trimmed; '' for an absent one.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $tokens
     * @return array{string, string}
     */
    private function cardHeadingText(array $block, array $tokens): array
    {
        return [
            trim($this->substitute((string) ($block['eyebrow'] ?? ''), $tokens)),
            trim($this->substitute((string) ($block['title'] ?? ''), $tokens)),
        ];
    }

    /**
     * A rows block's items as [title, description], placeholders filled in and edges trimmed, leaving out an
     * item that has neither.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $tokens
     * @return list<array{string, string}>
     */
    private function rowsItems(array $block, array $tokens): array
    {
        $items = [];
        foreach ($block['items'] as $item) {
            $title = trim($this->substitute($item['title'], $tokens));
            $description = trim($this->substitute((string) ($item['description'] ?? ''), $tokens));
            if ($title !== '' || $description !== '') {
                $items[] = [$title, $description];
            }
        }

        return $items;
    }

    /**
     * Top-level paragraphs and lists separated by a blank line; list items one per line, "• " or "1. ",
     * nested lists indented two spaces per level.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, string>  $tokens
     */
    private function documentText(array $doc, array $tokens): string
    {
        $chunks = [];
        foreach ($doc['content'] as $node) {
            $chunks[] = $node['type'] === 'paragraph'
                ? $this->inlineText($node, $tokens)
                : implode("\n", $this->listLines($node, $tokens, 0));
        }

        return implode("\n\n", $chunks);
    }

    /**
     * @param  array<string, mixed>  $list
     * @param  array<string, string>  $tokens
     * @return list<string>
     */
    private function listLines(array $list, array $tokens, int $indent): array
    {
        $lines = [];
        $number = $list['type'] === 'orderedList' ? $this->listStart($list) : null;

        foreach ($list['content'] as $item) {
            $marker = $number === null ? '• ' : ($number++).'. ';
            $first = true;

            foreach ($item['content'] as $child) {
                if ($child['type'] === 'paragraph') {
                    // A later paragraph of the same item lines up under the first one's text.
                    $lead = $first ? $marker : str_repeat(' ', mb_strlen($marker));
                    $lines[] = str_repeat('  ', $indent).$lead.$this->inlineText($child, $tokens);
                    $first = false;
                } else {
                    array_push($lines, ...$this->listLines($child, $tokens, $indent + 1));
                }
            }
        }

        return $lines;
    }

    /**
     * A paragraph's text. Consecutive text nodes under the same link — "Book" bold then "a call" plain —
     * are one link, so its URL is written once, after the whole label.
     *
     * @param  array<string, mixed>  $paragraph
     * @param  array<string, string>  $tokens
     */
    private function inlineText(array $paragraph, array $tokens): string
    {
        $text = '';
        $label = '';
        $href = null;

        foreach ($paragraph['content'] ?? [] as $node) {
            $nodeHref = null;
            if ($node['type'] === 'hardBreak') {
                $piece = "\n";
            } else {
                $piece = $this->substitute($node['text'], $tokens);
                foreach ($node['marks'] ?? [] as $mark) {
                    if ($mark['type'] === EmailDesignSchema::LINK_MARK) {
                        $nodeHref = $mark['attrs']['href'];
                    }
                }
            }

            if ($nodeHref !== $href) {
                $text .= $this->linkText($label, $href);
                $label = '';
                $href = $nodeHref;
            }
            $label .= $piece;
        }

        return $text.$this->linkText($label, $href);
    }

    private function linkText(string $label, ?string $href): string
    {
        if ($href === null) {
            return $label;
        }

        $trimmed = trim($label);

        return $trimmed === '' || $trimmed === $href ? $href : $label.' ('.$href.')';
    }

    // ── Shared ───────────────────────────────────────────────────────────────────────────────

    /**
     * The signature area's lines, in order: the sign-off — the template's when it sets one, the design's
     * otherwise — then the sender's name, title and phone, each only when it has a value. Nothing when the
     * design switches the signature off. Each line is [text, isSignOff, isName].
     *
     * @param  array<string, mixed>  $signature
     * @param  array<string, mixed>  $content
     * @param  array<string, string>  $tokens
     * @return list<array{string, bool, bool}>
     */
    private function signatureLines(array $signature, array $content, array $tokens): array
    {
        if ($signature['enabled'] !== true) {
            return [];
        }

        $lines = [];

        $signOff = is_string($content['sign_off'] ?? null) ? $content['sign_off'] : $signature['sign_off'];
        $signOff = trim($this->substitute($signOff, $tokens));
        if ($signOff !== '') {
            $lines[] = [$signOff, true, false];
        }

        foreach (EmailDesignSchema::SIGNATURE_TOKENS as $key) {
            // A design that shows the sender's phone takes it from the context (signatureExtras), never twice.
            if ($key === 'sender_phone' && ($signature['show_phone'] ?? false) === true) {
                continue;
            }
            $value = trim($tokens[$key] ?? '');
            if ($value !== '') {
                $lines[] = [$value, false, $key === 'sender_name'];
            }
        }

        return $lines;
    }

    /**
     * A block's own color when it sets one, otherwise the style EmailDesignSchema::BLOCK_COLOR_FALLBACKS
     * names for that field.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $theme
     */
    private function blockColor(array $block, string $field, array $theme): string
    {
        return $this->styleValue($field, $block[$field] ?? null)
            ?? $theme[EmailDesignSchema::BLOCK_COLOR_FALLBACKS[$block['type']][$field]];
    }

    /**
     * A button's own color when it sets one; otherwise the accent colors, or the secondary colors for a
     * `secondary` button.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $theme
     * @param  array<string, string|int>  $extra
     */
    private function buttonColor(array $block, string $field, array $theme, array $extra): string
    {
        if (($block['variant'] ?? null) !== 'secondary') {
            return $this->blockColor($block, $field, $theme);
        }

        return $this->styleValue($field, $block[$field] ?? null)
            ?? (string) $extra[$field === 'background_color' ? 'secondary_color' : 'secondary_text_color'];
    }

    /** A usable on/off style — a boolean — or null. */
    private function booleanStyle(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * A usable numeric style — a whole number within its range — or null.
     *
     * @param  array{int, int}  $range
     */
    private function numericStyle(mixed $value, array $range): ?int
    {
        return is_int($value) && $value >= $range[0] && $value <= $range[1] ? $value : null;
    }

    /** A usable style value — a known font key for `font`, a six-digit hex color otherwise — or null. */
    private function styleValue(string $key, mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        if ($key === 'font') {
            return array_key_exists($value, EmailDesignSchema::FONTS) ? $value : null;
        }

        return preg_match(self::HEX_COLOR, $value) === 1 ? strtolower($value) : null;
    }

    /**
     * One pass of TemplateRenderer's substitution: unknown keys blank, and a substituted value is never
     * scanned again, so a value containing `{{token}}` stays literal.
     *
     * @param  array<string, string>  $tokens
     */
    private function substitute(string $text, array $tokens): string
    {
        return (string) preg_replace_callback(
            EmailDesignSchema::TOKEN_PATTERN,
            static fn (array $m): string => $tokens[$m[1]] ?? '',
            $text,
        );
    }

    /**
     * @param  array<array-key, mixed>  $tokens
     * @return array<string, string>
     */
    private function cleanTokens(array $tokens): array
    {
        $clean = [];
        foreach ($tokens as $key => $value) {
            if (is_string($key)) {
                $clean[$key] = $this->cleanValue(is_scalar($value) ? (string) $value : '');
            }
        }

        return $clean;
    }

    /** A context value made safe for either part: valid UTF-8, with every unsafe character run a single space. */
    private function cleanValue(string $value): string
    {
        return (string) preg_replace(self::UNSAFE_VALUE_CHARACTERS, ' ', mb_scrub($value, 'UTF-8'));
    }

    /** A plain absolute http(s) URL with a host and no credentials, or null. */
    private function safeImageUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > EmailDesignSchema::MAX_URL) {
            return null;
        }
        if (preg_match('~^https?://[\x21-\x7E]+\z~', $url) !== 1 || preg_match('/["\'<>\\\\`]/', $url) !== 0) {
            return null;
        }

        $parts = parse_url($url);

        return is_array($parts) && ($parts['host'] ?? '') !== '' && ! isset($parts['user']) && ! isset($parts['pass'])
            ? $url
            : null;
    }

    /** @param  array<string, mixed>  $list */
    private function listStart(array $list): int
    {
        $start = $list['attrs']['start'] ?? 1;

        return is_int($start) && $start >= 1 ? $start : 1;
    }

    private function escape(string $text): string
    {
        // HTML 4.01 entities: `&#039;` for a quote, which every mail client decodes (`&apos;` is not universal).
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $content
     */
    private function assertValid(array $layout, array $content): void
    {
        if ($this->validator->layoutProblem($layout) !== null || $this->validator->contentProblem($content) !== null) {
            throw new InvalidArgumentException('Refusing to render an email design or content that did not validate.');
        }
    }
}
