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
 * context resolved. Each block is one row with 24px side padding, so images and buttons scale down on a
 * phone without a media query. A preheader, when set, is the first thing in <body>: hidden text that inbox
 * lists show beside the subject.
 *
 * ── Styles ──
 * Resolved once per render by {@see effectiveStyles()}: the template's style override, then the design's
 * style, then the organization's branding from the context, then EmailDesignSchema::SYSTEM_DEFAULTS. A block
 * color the block does not set falls back to its style in EmailDesignSchema::BLOCK_COLOR_FALLBACKS.
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
        $page = $theme['background_color'];
        $canvas = $theme['content_background_color'];
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
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="'.$canvas.'" style="width:100%;max-width:'.EmailDesignSchema::CONTENT_WIDTH.'px;border-collapse:collapse;background-color:'.$canvas.';">',
            $this->spacerRow(self::CANVAS_PADDING),
        );

        $rows = [];
        foreach ([...$layout['header'], ...$content['blocks']] as $block) {
            $rows[] = $this->blockHtml($block, $theme, $font, $tokens, $context);
        }
        $rows[] = $this->signatureRow($layout['signature'], $content, $font, $theme['text_color'], $tokens);
        foreach ($layout['footer'] as $block) {
            $rows[] = $this->blockHtml($block, $theme, $font, $tokens, $context);
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
        $parts[] = implode("\n", array_column($this->signatureLines($layout['signature'], $content, $tokens), 0));
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

    // ── HTML blocks ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $theme
     * @param  array<string, string>  $tokens
     */
    private function blockHtml(array $block, array $theme, string $font, array $tokens, EmailDesignContext $context): string
    {
        return match ($block['type']) {
            'logo' => $this->imageRow($context->logoUrl, $block['width'], $this->cleanValue($context->logoAlt), $block['align'], $this->logoLink($block, $context), $context),
            'heading' => $this->headingRow($block, $font, $tokens, $this->blockColor($block, 'color', $theme)),
            'text' => $this->documentRow($block, $font, $this->blockColor($block, 'color', $theme), $theme['link_color'], $tokens, 12),
            'button' => $this->buttonRow($block, $font, $tokens, $this->blockColor($block, 'background_color', $theme), $this->blockColor($block, 'text_color', $theme)),
            'image' => $this->imageRow($context->assetUrls[$block['asset_id']] ?? null, $block['width'], $block['alt'], $block['align'], $block['link_url'] ?? null, $context),
            'divider' => $this->dividerRow($block, $this->blockColor($block, 'color', $theme)),
            'spacer' => $this->spacerRow($block['height']),
            'footer' => $this->documentRow($block, $font, $this->blockColor($block, 'color', $theme), $theme['link_color'], $tokens, 8),
            // Unreachable for a validated document, and deliberately renders nothing.
            default => '',
        };
    }

    private function row(string $align, string $inner): string
    {
        $align = $this->escape($align);

        return '<tr><td align="'.$align.'" style="padding:8px '.EmailDesignSchema::CONTENT_PADDING.'px;text-align:'.$align.';">'.$inner.'</td></tr>';
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
    private function headingRow(array $block, string $font, array $tokens, string $color): string
    {
        $level = (int) $block['level'];
        $style = 'margin:0;font-family:'.$font.';font-size:'.EmailDesignSchema::HEADING_SIZES[$level].'px;line-height:1.25;'
            .'font-weight:bold;color:'.$color.';text-align:'.$this->escape($block['align']).';';

        return $this->row(
            $block['align'],
            '<h'.$level.' style="'.$style.'">'.$this->escape($this->substitute($block['text'], $tokens)).'</h'.$level.'>',
        );
    }

    /**
     * A text or footer block. Styles repeat on every paragraph and list because Outlook desktop does not
     * reliably inherit them from the cell.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $tokens
     */
    private function documentRow(array $block, string $font, string $color, string $linkColor, array $tokens, int $gap): string
    {
        $base = 'font-family:'.$font.';font-size:'.(int) $block['font_size'].'px;line-height:1.5;color:'.$color.';';
        $nodes = $block['content']['content'];
        $last = count($nodes) - 1;

        $html = '';
        foreach ($nodes as $i => $node) {
            $margin = $i === $last ? '0' : '0 0 '.$gap.'px 0';
            $html .= $node['type'] === 'paragraph'
                ? '<p style="margin:'.$margin.';'.$base.'">'.$this->inlineHtml($node, $linkColor, $tokens, '&nbsp;').'</p>'
                : $this->listHtml($node, $margin, $base, $linkColor, $tokens);
        }

        return $this->row($block['align'], $html);
    }

    /**
     * @param  array<string, mixed>  $list
     * @param  array<string, string>  $tokens
     */
    private function listHtml(array $list, string $margin, string $base, string $linkColor, array $tokens): string
    {
        $tag = $list['type'] === 'orderedList' ? 'ol' : 'ul';
        $start = $tag === 'ol' ? $this->listStart($list) : 1;

        $html = '<'.$tag.($start > 1 ? ' start="'.$start.'"' : '').' style="margin:'.$margin.';padding:0 0 0 24px;'.$base.'">';
        foreach ($list['content'] as $item) {
            $inner = '';
            $afterParagraph = false;
            foreach ($item['content'] as $child) {
                if ($child['type'] === 'paragraph') {
                    $inner .= ($afterParagraph ? '<br>' : '').$this->inlineHtml($child, $linkColor, $tokens, '');
                    $afterParagraph = true;
                } else {
                    $inner .= $this->listHtml($child, '4px 0 0 0', $base, $linkColor, $tokens);
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
    private function inlineHtml(array $paragraph, string $linkColor, array $tokens, string $empty): string
    {
        $html = '';
        foreach ($paragraph['content'] ?? [] as $node) {
            $html .= $node['type'] === 'hardBreak' ? '<br>' : $this->textHtml($node, $linkColor, $tokens);
        }

        return $html === '' ? $empty : $html;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $tokens
     */
    private function textHtml(array $node, string $linkColor, array $tokens): string
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
            : '<a href="'.$this->escape($href).'" target="_blank" rel="noopener noreferrer" style="color:'.$linkColor.';text-decoration:underline;">'.$html.'</a>';
    }

    /**
     * A "bulletproof" button: the color sits on a table cell, so clients that drop padding on links (Outlook
     * desktop) still show a colored, clickable block.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, string>  $tokens
     */
    private function buttonRow(array $block, string $font, array $tokens, string $background, string $labelColor): string
    {
        $radius = (int) $block['radius'];

        return $this->row(
            $block['align'],
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:'.self::MARGINS[$block['align']].';">'
            .'<tr><td align="center" bgcolor="'.$background.'" style="border-radius:'.$radius.'px;background-color:'.$background.';">'
            .'<a href="'.$this->escape($block['url']).'" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 24px;'
            .'font-family:'.$font.';font-size:16px;line-height:20px;font-weight:bold;color:'.$labelColor.';'
            .'text-decoration:none;border-radius:'.$radius.'px;">'
            .$this->escape($this->substitute($block['label'], $tokens))
            .'</a></td></tr></table>',
        );
    }

    /**
     * An image or logo row, or nothing when the context gave no safe URL for it.
     *
     * A Settings preview context may carry the PNG bytes for a URL (EmailDesignContext::$previewImages); the
     * image is then inlined as a data URI — but only once the URL itself passed the same check, and only when
     * the bytes are a PNG. A data URI is never accepted AS a URL from the context.
     */
    private function imageRow(mixed $url, int $width, string $alt, string $align, ?string $link, EmailDesignContext $context): string
    {
        $src = $this->safeImageUrl($url);
        if ($src === null) {
            return '';
        }

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

        return $this->row($align, $image);
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
    private function dividerRow(array $block, string $color): string
    {
        $thickness = (int) $block['thickness'];

        return '<tr><td style="padding:8px '.EmailDesignSchema::CONTENT_PADDING.'px;">'
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
    private function signatureRow(array $signature, array $content, string $font, string $textColor, array $tokens): string
    {
        $lines = $this->signatureLines($signature, $content, $tokens);
        if ($lines === []) {
            return '';
        }

        $base = 'font-family:'.$font.';font-size:14px;line-height:1.5;color:'.$textColor.';';
        $html = '';
        foreach ($lines as [$text, $isSignOff, $isName]) {
            $margin = $isSignOff && count($lines) > 1 ? '0 0 8px 0' : '0';
            $escaped = $this->escape($text);
            $html .= '<p style="margin:'.$margin.';'.$base.'">'.($isName ? '<strong>'.$escaped.'</strong>' : $escaped).'</p>';
        }

        return $this->row($signature['align'], $html);
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
            default => '',
        };
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
