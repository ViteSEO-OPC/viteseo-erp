<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Mail;

use App\Support\TiptapDocument;
use InvalidArgumentException;

/**
 * The email-safe subset of a Tiptap document: what a formatted reply may contain, and the only two
 * things ever produced from it — the text/plain part and the text/html part of the message.
 *
 * ── Why this is not TiptapDocument's allowlist ──
 * `TiptapDocument` describes what an Internal Note may hold: headings, task lists, highlight, code,
 * links, quotes. Most of that has no business in an email — task-list checkboxes and highlight do not
 * survive mail clients, and a link is an href nobody approved. So a reply is held to a much smaller,
 * CLOSED set, and the structure is checked too, not just the type names:
 *
 *   nodes   doc · paragraph · text · bulletList · orderedList · listItem
 *   marks   bold · italic · underline · strike
 *
 * ── The check ORDER is a security requirement, as in ValidatesNoteDocument ──
 * bytes → depth → structure. `TiptapDocument::depth()` shares a walk that stops descending past
 * MAX_DEPTH, so depth is rejected BEFORE anything below it is inspected; the structural walk here
 * carries its own depth guard as well, so it can never recurse unboundedly either.
 *
 * ── The browser never supplies HTML ──
 * The client sends JSON. The only HTML that ever leaves this server is written by {@see toHtml()}, one
 * allowlisted tag at a time, with every text node escaped and no attribute taken from the document
 * except an ordered list's integer `start`. A document that does not validate is never rendered.
 */
final class EmailDocument
{
    /** @var list<string> */
    public const NODES = ['doc', 'paragraph', 'text', 'bulletList', 'orderedList', 'listItem'];

    /** @var list<string> */
    public const MARKS = ['bold', 'italic', 'underline', 'strike'];

    /**
     * Which children each node may hold — the schema the editor itself enforces, restated so a
     * hand-written payload cannot put a list inside a paragraph or text directly inside a list.
     *
     * @var array<string, list<string>>
     */
    private const CHILDREN = [
        'doc' => ['paragraph', 'bulletList', 'orderedList'],
        'paragraph' => ['text'],
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        // ProseMirror's listItem is `paragraph block*`: it must open with a paragraph (checked below).
        'listItem' => ['paragraph', 'bulletList', 'orderedList'],
        'text' => [],
    ];

    /**
     * Mark → tag. Also the canonical NESTING order, so the same marks always produce the same HTML
     * whatever order the client listed them in.
     *
     * @var array<string, string>
     */
    private const MARK_TAGS = ['bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's'];

    /** Upper bound on an ordered list's `start`; anything else is ignored rather than rendered. */
    private const MAX_LIST_START = 100000;

    public const MALFORMED = 'This message is not a well-formed document.';

    public const TOO_LARGE = 'This message is too large to send (limit 512 KB).';

    public const TOO_DEEP = 'This message is nested too deeply.';

    /**
     * Null when the document may be sent; otherwise the reason, safe to show the user.
     */
    public static function problem(mixed $doc): ?string
    {
        if (! is_array($doc) || ($doc['type'] ?? null) !== 'doc') {
            return self::MALFORMED;
        }

        // 1 ── bytes
        $encoded = json_encode($doc);
        if ($encoded === false || strlen($encoded) > TiptapDocument::MAX_BYTES) {
            return self::TOO_LARGE;
        }

        // 2 ── depth. Must precede the structural walk; see the class docblock.
        if (TiptapDocument::depth($doc) > TiptapDocument::MAX_DEPTH) {
            return self::TOO_DEEP;
        }

        // 3 ── structure: allowlisted types, allowed children, well-formed leaves and marks.
        return self::structuralProblem($doc, null, 1);
    }

    /**
     * The text/html part. Only for a document that has passed {@see problem()}.
     *
     * Paragraphs and lists carry `margin: 0`, so the message reads the way it was typed — Enter is a
     * new line, Enter twice a blank one — and matches the text/plain part line for line, rather than
     * gaining the paragraph spacing a mail client would otherwise add. The styles are fixed strings:
     * nothing from the document ever reaches an attribute except an integer list `start`.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function toHtml(array $doc): string
    {
        self::assertValid($doc);

        return '<div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.5;">'
            .self::renderChildren($doc)
            .'</div>';
    }

    /**
     * The text/plain part, and the canonical `body`. Only for a document that has passed
     * {@see problem()}.
     *
     * One line per paragraph (an empty paragraph is a blank line), bullets as "• ", numbered items
     * as "1. ", nested lists indented two spaces per level — the readable form every plain-text
     * consumer of `body` (notifications, previews, the Follow-ups list) has always received.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function toText(array $doc): string
    {
        self::assertValid($doc);

        $lines = [];
        self::blockLines($doc, $lines, 0);

        return trim(implode("\n", array_map('rtrim', $lines)), "\n");
    }

    // ── Validation ───────────────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $node */
    private static function structuralProblem(array $node, ?string $parent, int $depth): ?string
    {
        if ($depth > TiptapDocument::MAX_DEPTH) {
            return self::TOO_DEEP;
        }

        $type = $node['type'] ?? null;
        if (! is_string($type)) {
            return self::MALFORMED;
        }
        if (! in_array($type, self::NODES, true)) {
            return 'This message contains formatting that email does not support: '.self::label($type).'.';
        }
        if ($parent !== null && ! in_array($type, self::CHILDREN[$parent], true)) {
            return self::MALFORMED;
        }

        // Marks belong on text and nowhere else, and must all be allowlisted.
        if (array_key_exists('marks', $node)) {
            if ($type !== 'text' || ! is_array($node['marks']) || ! array_is_list($node['marks'])) {
                return self::MALFORMED;
            }
            foreach ($node['marks'] as $mark) {
                $markType = is_array($mark) ? ($mark['type'] ?? null) : null;
                if (! is_string($markType)) {
                    return self::MALFORMED;
                }
                if (! isset(self::MARK_TAGS[$markType])) {
                    return 'This message contains formatting that email does not support: '.self::label($markType).'.';
                }
            }
        }

        if ($type === 'text') {
            // ProseMirror never produces an empty text node, and a text node has no children.
            return is_string($node['text'] ?? null) && $node['text'] !== '' && ! array_key_exists('content', $node)
                ? null
                : self::MALFORMED;
        }

        if (array_key_exists('text', $node)) {
            return self::MALFORMED;
        }

        $content = $node['content'] ?? [];
        if (! is_array($content) || ! array_is_list($content)) {
            return self::MALFORMED;
        }

        // A document, a list and a list item cannot be empty; a paragraph can (a blank line).
        if ($content === [] && $type !== 'paragraph') {
            return self::MALFORMED;
        }
        if ($type === 'listItem' && (($content[0]['type'] ?? null) !== 'paragraph')) {
            return self::MALFORMED;
        }

        foreach ($content as $child) {
            if (! is_array($child)) {
                return self::MALFORMED;
            }
            $problem = self::structuralProblem($child, $type, $depth + 1);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /** A type name as it may appear in a message: bounded and printable, never raw input. */
    private static function label(string $type): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $type) ?? '';

        return $clean === '' ? 'unknown' : mb_substr($clean, 0, 40);
    }

    /** @param  array<string, mixed>  $doc */
    private static function assertValid(array $doc): void
    {
        if (self::problem($doc) !== null) {
            throw new InvalidArgumentException('Refusing to render an email document that did not validate.');
        }
    }

    // ── HTML ─────────────────────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $node */
    private static function renderChildren(array $node): string
    {
        $html = '';
        foreach ($node['content'] ?? [] as $child) {
            $html .= self::renderNode($child);
        }

        return $html;
    }

    /** @param  array<string, mixed>  $node */
    private static function renderNode(array $node): string
    {
        return match ($node['type']) {
            'paragraph' => '<p style="margin: 0;">'.(($inner = self::renderChildren($node)) === '' ? '<br>' : $inner).'</p>',
            'bulletList' => '<ul style="margin: 0; padding-left: 24px;">'.self::renderChildren($node).'</ul>',
            'orderedList' => '<ol'.self::startAttribute($node).' style="margin: 0; padding-left: 24px;">'.self::renderChildren($node).'</ol>',
            'listItem' => '<li>'.self::renderChildren($node).'</li>',
            'text' => self::renderText($node),
            // Unreachable for a validated document, and deliberately emits NOTHING rather than a
            // best-effort rendering of a type this renderer does not know.
            default => '',
        };
    }

    /** @param  array<string, mixed>  $node */
    private static function renderText(array $node): string
    {
        $present = [];
        foreach ($node['marks'] ?? [] as $mark) {
            $present[$mark['type']] = true;
        }

        $html = self::escape((string) $node['text']);

        // Innermost first, in the canonical order, so the output is deterministic.
        foreach (array_reverse(self::MARK_TAGS, true) as $mark => $tag) {
            if (isset($present[$mark])) {
                $html = "<{$tag}>{$html}</{$tag}>";
            }
        }

        return $html;
    }

    /** @param  array<string, mixed>  $node */
    private static function startAttribute(array $node): string
    {
        $start = self::listStart($node);

        return $start > 1 ? ' start="'.$start.'"' : '';
    }

    /** @param  array<string, mixed>  $node */
    private static function listStart(array $node): int
    {
        $start = $node['attrs']['start'] ?? 1;

        return is_int($start) && $start >= 1 && $start <= self::MAX_LIST_START ? $start : 1;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    // ── Plain text ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $lines
     */
    private static function blockLines(array $node, array &$lines, int $indent): void
    {
        foreach ($node['content'] ?? [] as $child) {
            if ($child['type'] === 'paragraph') {
                $lines[] = str_repeat('  ', $indent).self::inlineText($child);
            } else {
                self::listLines($child, $lines, $indent);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $list
     * @param  list<string>  $lines
     */
    private static function listLines(array $list, array &$lines, int $indent): void
    {
        $number = $list['type'] === 'orderedList' ? self::listStart($list) : null;

        foreach ($list['content'] ?? [] as $item) {
            $marker = $number === null ? '• ' : ($number++).'. ';
            $first = true;

            foreach ($item['content'] ?? [] as $child) {
                if ($child['type'] === 'paragraph') {
                    // A later paragraph of the same item lines up under the first one's text.
                    $lead = $first ? $marker : str_repeat(' ', mb_strlen($marker));
                    $lines[] = str_repeat('  ', $indent).$lead.self::inlineText($child);
                    $first = false;
                } else {
                    self::listLines($child, $lines, $indent + 1);
                }
            }
        }
    }

    /** @param  array<string, mixed>  $paragraph */
    private static function inlineText(array $paragraph): string
    {
        $text = '';
        foreach ($paragraph['content'] ?? [] as $child) {
            $text .= (string) ($child['text'] ?? '');
        }

        return $text;
    }
}
