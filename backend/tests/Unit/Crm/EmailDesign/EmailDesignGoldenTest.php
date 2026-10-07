<?php

declare(strict_types=1);

namespace Tests\Unit\Crm\EmailDesign;

use App\Modules\Crm\Support\EmailDesign\EmailDesignContext;
use App\Modules\Crm\Support\EmailDesign\EmailDesignRenderer;
use App\Modules\Crm\Support\EmailDesign\EmailDesignValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The frozen schema-version-1 output: every case in tests/Fixtures/EmailDesignGolden must render to exactly
 * the bytes stored beside it, in both the text/html and the text/plain part.
 *
 * WHY BYTES. A scheduled email is rendered when it is delivered, not when it was previewed, and Lead Details
 * shows `body_html`, the string that went out. So any change to what a stored v1 design and content render to
 * — a space, an attribute order, a color's case — changes emails people already approved. New blocks, fields
 * and styles are additive: a document that does not use them must keep rendering byte for byte.
 *
 * THE CORPUS. `documents/` holds design layouts and template contents; `cases.json` pairs a layout, a content
 * and a named context from `contexts.json`; `expected/<case>.html|.txt` is what the renderer produced when the
 * corpus was frozen (2026-10-07, schema version 1). It covers every v1 block type and option in every region,
 * the style inheritance chain, the signature variants, every logo link target, the contexts the product
 * renders with (send, Settings preview, the Gmail guard's measurement, the placeholder-preserving plain-text
 * body), hostile context values, and every design and designed template that existed locally on that date.
 *
 * THESE FILES ARE NEVER REGENERATED TO MAKE A TEST PASS. If a case fails, the renderer changed v1 output; fix
 * the renderer. A deliberate change to v1 output is a schema version bump with an upgrade path, not an edit
 * here. Adding cases for NEW blocks or fields is fine — they cannot affect the existing ones.
 */
final class EmailDesignGoldenTest extends TestCase
{
    private const DIRECTORY = __DIR__.'/../../../Fixtures/EmailDesignGolden';

    /** The size of the frozen corpus, so a case cannot quietly disappear from it. */
    private const FROZEN_CASES = 108;

    /** @return array<string, array{string}> */
    public static function cases(): array
    {
        $names = array_keys(self::json('cases.json'));

        return array_combine($names, array_map(fn (string $name): array => [$name], $names));
    }

    #[DataProvider('cases')]
    public function test_v1_output_is_byte_identical_to_the_golden_corpus(string $name): void
    {
        $case = self::json('cases.json')[$name];
        $layout = self::json("documents/{$case['layout']}.json");
        $content = self::json("documents/{$case['content']}.json");
        $context = self::context($case['context']);
        $renderer = new EmailDesignRenderer;

        $this->assertSame(
            self::expected("{$name}.html"),
            $renderer->toHtml($layout, $content, $context),
            "The text/html part of golden case {$name} changed. Existing v1 designs must render byte-identically.",
        );
        $this->assertSame(
            self::expected("{$name}.txt"),
            $renderer->toText($layout, $content, $context),
            "The text/plain part of golden case {$name} changed. Existing v1 designs must render byte-identically.",
        );
    }

    public function test_the_corpus_is_complete_and_has_no_orphans(): void
    {
        $cases = self::json('cases.json');
        $contexts = self::json('contexts.json');

        $this->assertCount(self::FROZEN_CASES, $cases, 'The frozen v1 corpus lost or gained cases.');

        $expected = [];
        $documents = [];
        foreach ($cases as $name => $case) {
            $this->assertArrayHasKey($case['context'], $contexts, "Case {$name} names an unknown context.");
            foreach (['html', 'txt'] as $extension) {
                $this->assertFileExists(self::DIRECTORY."/expected/{$name}.{$extension}");
                $expected[] = "{$name}.{$extension}";
            }
            $documents[$case['layout']] = true;
            $documents[$case['content']] = true;
        }

        $onDisk = array_map('basename', glob(self::DIRECTORY.'/expected/*') ?: []);
        sort($onDisk);
        sort($expected);
        $this->assertSame($expected, $onDisk, 'Every expected file must belong to a case, and every case must have both.');

        $documentFiles = array_map(fn (string $path): string => basename($path, '.json'), glob(self::DIRECTORY.'/documents/*.json') ?: []);
        sort($documentFiles);
        $used = array_keys($documents);
        sort($used);
        $this->assertSame($used, $documentFiles, 'Every document must be used by a case, and every case document must exist.');
    }

    public function test_every_golden_document_is_a_valid_v1_document(): void
    {
        $validator = new EmailDesignValidator;

        foreach (glob(self::DIRECTORY.'/documents/*.json') ?: [] as $path) {
            $key = basename($path, '.json');
            $document = self::json("documents/{$key}.json");
            $problem = str_starts_with($key, 'layout.') ? $validator->layoutProblem($document) : $validator->contentProblem($document);

            $this->assertSame(1, $document['version'], "{$key} must stay a version 1 document.");
            $this->assertNull($problem, "{$key} no longer validates: ".json_encode($problem));
        }
    }

    /** The named context, with preview image bytes stored base64-encoded. */
    public static function context(string $name): EmailDesignContext
    {
        $context = self::json('contexts.json')[$name];

        return new EmailDesignContext(
            tokens: $context['tokens'],
            assetUrls: $context['assetUrls'],
            logoUrl: $context['logoUrl'],
            logoAlt: $context['logoAlt'],
            brandStyles: $context['brandStyles'],
            brandWebsite: $context['brandWebsite'],
            previewImages: array_map(fn (string $bytes): string => (string) base64_decode($bytes, true), $context['previewImages']),
        );
    }

    /** @return array<string, mixed> */
    public static function json(string $path): array
    {
        return json_decode((string) file_get_contents(self::DIRECTORY.'/'.$path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function expected(string $file): string
    {
        $bytes = file_get_contents(self::DIRECTORY.'/expected/'.$file);
        if ($bytes === false) {
            self::fail("Missing golden file expected/{$file}.");
        }

        return $bytes;
    }
}
