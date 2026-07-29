<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

/**
 * Server-side handling of a Tiptap JSON document.
 *
 * The client is never trusted: a stored document may only contain node and mark
 * types that the editor is actually configured with, so a payload can't smuggle
 * a node that some future renderer would turn into markup (defense-in-depth
 * against stored XSS — the editor's own schema filter is the first layer).
 */
final class TiptapDocument
{
    /** Max serialized size of a document, in bytes. */
    public const MAX_BYTES = 512 * 1024;

    /** Max nesting depth before a document is rejected. */
    public const MAX_DEPTH = 50;

    /**
     * Node types produced by the enabled extensions (StarterKit + TaskList,
     * TaskItem, Link, Highlight, Underline). Keep in sync with the editor
     * configuration in the frontend notes module.
     *
     * @var list<string>
     */
    public const ALLOWED_NODES = [
        'doc',
        'paragraph',
        'text',
        'heading',
        'bulletList',
        'orderedList',
        'listItem',
        'taskList',
        'taskItem',
        'blockquote',
        'codeBlock',
        'horizontalRule',
        'hardBreak',
    ];

    /**
     * Mark types produced by the enabled extensions.
     *
     * @var list<string>
     */
    public const ALLOWED_MARKS = [
        'bold',
        'italic',
        'strike',
        'code',
        'underline',
        'highlight',
        'link',
    ];

    /**
     * An empty document — what a note is lazily created with.
     *
     * It holds one empty paragraph rather than nothing at all: ProseMirror
     * renders a doc with no children as literally nothing, which leaves the
     * editor with no line to place the caret on and no node for the placeholder
     * to hang off.
     *
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    }

    /**
     * Every disallowed node/mark type in the document, deduplicated.
     *
     * @param  array<string, mixed>  $doc
     * @return list<string>
     */
    public static function disallowedTypes(array $doc): array
    {
        $found = [];
        self::walk($doc, function (array $node) use (&$found): void {
            $type = is_string($node['type'] ?? null) ? $node['type'] : null;
            if ($type !== null && ! in_array($type, self::ALLOWED_NODES, true)) {
                $found[] = $type;
            }

            foreach (self::marksOf($node) as $mark) {
                $markType = is_string($mark['type'] ?? null) ? $mark['type'] : null;
                if ($markType !== null && ! in_array($markType, self::ALLOWED_MARKS, true)) {
                    $found[] = $markType;
                }
            }
        });

        return array_values(array_unique($found));
    }

    /**
     * Deepest nesting level in the document (the root node counts as 1).
     *
     * @param  array<string, mixed>  $doc
     */
    public static function depth(array $doc): int
    {
        $max = 0;
        self::walk($doc, function (array $node, int $depth) use (&$max): void {
            $max = max($max, $depth);
        });

        return $max;
    }

    /**
     * Plain-text projection of the document — every `text` node, with block
     * boundaries collapsed to newlines. Powers previews and future search.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function extractText(array $doc): string
    {
        $parts = [];
        self::walk($doc, function (array $node) use (&$parts): void {
            if (($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null)) {
                $parts[] = $node['text'];

                return;
            }

            // Block-level nodes end a line so words don't run together.
            if (in_array($node['type'] ?? null, ['paragraph', 'heading', 'listItem', 'taskItem', 'codeBlock', 'blockquote'], true)) {
                $parts[] = "\n";
            }
        });

        $text = preg_replace('/\n{3,}/', "\n\n", implode('', $parts)) ?? '';

        return trim($text);
    }

    /**
     * Depth-first walk over the document. The callback receives each node and
     * its 1-based depth.
     *
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>, int): void  $callback
     */
    private static function walk(array $node, callable $callback, int $depth = 1): void
    {
        $callback($node, $depth);

        // Bail out below the walk itself rather than recursing forever on a
        // pathological document — depth() reports the cutoff and validation
        // rejects it.
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $children = $node['content'] ?? null;
        if (! is_array($children)) {
            return;
        }

        foreach ($children as $child) {
            if (is_array($child)) {
                self::walk($child, $callback, $depth + 1);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function marksOf(array $node): array
    {
        $marks = $node['marks'] ?? null;
        if (! is_array($marks)) {
            return [];
        }

        return array_values(array_filter($marks, 'is_array'));
    }
}
