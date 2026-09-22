<?php

namespace Tests\Unit\Crm\EmailAssets;

use App\Modules\Crm\Support\EmailAssets\LogoRejected;
use App\Modules\Crm\Support\EmailAssets\SvgSanitizer;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SVG sanitizer is the only thing between an uploaded SVG and a stored logo. The output is REBUILT from an
 * allowlist, so these cases check both halves: every kind of active content is gone and reported, and ordinary
 * logo markup — shapes, text, gradients, same-document references, safe CSS — survives unchanged.
 */
class SvgSanitizerTest extends TestCase
{
    private const LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100"><rect x="10" y="10" width="180" height="80" fill="#0d9488"/><text x="20" y="60">ACME</text></svg>';

    /** @return array{svg: string, viewBox: array{float, float, float, float}, width: float, height: float, removed: array<string, int>} */
    private function sanitize(string $svg): array
    {
        return (new SvgSanitizer)->sanitize($svg);
    }

    private function assertRejected(string $svg, string $message): void
    {
        try {
            $this->sanitize($svg);
            $this->fail('The SVG was accepted.');
        } catch (LogoRejected $rejected) {
            $this->assertSame($message, $rejected->getMessage());
        }
    }

    /** The output parses, is SVG, and holds nothing active. */
    private function assertOnlyAllowlisted(string $svg): void
    {
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($svg, LIBXML_NONET));
        $this->assertSame(SvgSanitizer::SVG_NS, $document->documentElement->namespaceURI);

        foreach ($document->getElementsByTagName('*') as $element) {
            $this->assertNotContains($element->localName, ['script', 'foreignObject', 'image', 'a', 'set', 'animate', 'iframe', 'feImage']);
            foreach ($element->attributes as $attribute) {
                $this->assertStringStartsNotWith('on', strtolower($attribute->localName));
                if ($attribute->localName === 'href') {
                    $this->assertStringStartsWith('#', $attribute->value);
                }
            }
        }
    }

    public function test_a_clean_logo_keeps_its_shapes_and_gets_a_definite_size(): void
    {
        $result = $this->sanitize(self::LOGO);

        $this->assertSame([0.0, 0.0, 200.0, 100.0], $result['viewBox']);
        $this->assertStringContainsString('<rect x="10" y="10" width="180" height="80" fill="#0d9488"/>', $result['svg']);
        $this->assertStringContainsString('>ACME</text>', $result['svg']);
        $this->assertStringContainsString('viewBox="0 0 200 100"', $result['svg']);
        $this->assertStringContainsString('width="200" height="100"', $result['svg']);
        $this->assertSame([], $result['removed']);
        $this->assertOnlyAllowlisted($result['svg']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function activeContent(): array
    {
        return [
            'script' => ['<script>alert(1)</script>', 'script', '<script'],
            'onload handler' => ['<rect width="1" height="1" onload="alert(1)"/>', 'event handler', 'onload'],
            'upper-case handler' => ['<rect width="1" height="1" ONCLICK="alert(1)"/>', 'event handler', 'alert'],
            'foreignObject' => ['<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>', 'foreignObject', 'foreignObject'],
            'linked image' => ['<image href="https://evil.example/x.png" width="10" height="10"/>', 'image', 'evil.example'],
            'embedded image' => ['<image href="data:image/png;base64,AAAA" width="10" height="10"/>', 'image', 'data:'],
            'external use' => ['<use href="https://evil.example/sprite.svg#a"/>', 'external reference', 'evil.example'],
            'xlink external use' => ['<use xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="https://evil.example/s.svg#a"/>', 'external reference', 'evil.example'],
            'javascript link' => ['<a href="javascript:alert(1)"><rect width="1" height="1"/></a>', 'link', 'javascript'],
            'animation rewriting an href' => ['<set attributeName="href" to="javascript:alert(1)"/>', 'animation', 'javascript'],
            'style import' => ['<style>@import url(https://evil.example/a.css);</style>', 'unsafe style', 'evil.example'],
            'style remote url' => ['<style>rect{fill:url(https://evil.example/p.svg#x)}</style>', 'unsafe style', 'evil.example'],
            'style attribute remote url' => ['<rect width="1" height="1" style="fill:url(https://evil.example/x)"/>', 'unsafe style', 'evil.example'],
            'fill attribute remote url' => ['<rect width="1" height="1" fill="url(https://evil.example/x#g)"/>', 'unsafe attribute', 'evil.example'],
            'css expression' => ['<rect width="1" height="1" style="width:expression(alert(1))"/>', 'unsafe style', 'expression'],
            'html iframe' => ['<iframe xmlns="http://www.w3.org/1999/xhtml" src="https://evil.example"/>', 'non-SVG element', 'evil.example'],
            'processing instruction' => ['<?xml-stylesheet href="https://evil.example/a.css"?>', 'processing instruction', 'evil.example'],
            'filter image' => ['<filter id="f"><feImage href="https://evil.example/x.png"/></filter>', 'image', 'evil.example'],
        ];
    }

    #[DataProvider('activeContent')]
    public function test_active_content_is_removed_and_reported(string $fragment, string $reported, string $absent): void
    {
        $result = $this->sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="red"/>'.$fragment.'</svg>');

        $this->assertStringNotContainsStringIgnoringCase($absent, $result['svg']);
        $this->assertArrayHasKey($reported, $result['removed']);
        $this->assertStringContainsString('fill="red"', $result['svg']);
        $this->assertOnlyAllowlisted($result['svg']);
    }

    public function test_gradients_same_document_references_and_safe_css_survive(): void
    {
        $result = $this->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10">'
            .'<defs><linearGradient id="g"><stop offset="0" stop-color="#fff"/></linearGradient><rect id="r" width="5" height="5"/></defs>'
            .'<style>.a{fill:url(#g);stroke:#000}</style>'
            .'<rect class="a" width="10" height="10" fill="url(#g)" transform="rotate(45 5 5)"/><use xlink:href="#r"/></svg>',
        );

        $this->assertStringContainsString('fill="url(#g)"', $result['svg']);
        $this->assertStringContainsString('<use href="#r"/>', $result['svg']);
        $this->assertStringContainsString('.a{fill:url(#g);stroke:#000}', $result['svg']);
        $this->assertStringContainsString('transform="rotate(45 5 5)"', $result['svg']);
        $this->assertSame([], $result['removed']);
    }

    public function test_a_link_is_unwrapped_and_its_drawing_kept(): void
    {
        $result = $this->sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><a href="https://evil.example"><path d="M0 0h10v10z"/></a></svg>');

        $this->assertStringContainsString('<path d="M0 0h10v10z"/>', $result['svg']);
        $this->assertStringNotContainsString('evil.example', $result['svg']);
    }

    public function test_an_svg_without_a_namespace_is_rebuilt_as_proper_svg(): void
    {
        $result = $this->sanitize('<svg viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');

        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $result['svg']);
        $this->assertOnlyAllowlisted($result['svg']);
    }

    public function test_doctypes_and_entities_are_refused_so_no_external_entity_is_ever_read(): void
    {
        $this->assertRejected(
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><text>&xxe;</text></svg>',
            SvgSanitizer::HAS_DOCTYPE,
        );
        $this->assertRejected(
            '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>',
            SvgSanitizer::HAS_DOCTYPE,
        );
    }

    /** @return array<string, array{string}> */
    public static function notSvg(): array
    {
        return [
            'empty' => [''],
            'html' => ['<html><body><svg/></body></html>'],
            'broken xml' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect>'],
            'binary' => ["\x89PNG\r\n\x1a\nnot-an-svg"],
            'svg in another namespace' => ['<svg xmlns="http://evil.example/ns" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>'],
        ];
    }

    #[DataProvider('notSvg')]
    public function test_anything_that_is_not_an_svg_is_refused(string $bytes): void
    {
        $this->assertRejected($bytes, SvgSanitizer::INVALID);
    }

    public function test_the_size_comes_from_the_viewbox_or_pixel_width_and_height(): void
    {
        $this->assertSame([0.0, 0.0, 120.0, 40.0], $this->sanitize('<svg xmlns="http://www.w3.org/2000/svg" width="120px" height="40"><rect width="1" height="1"/></svg>')['viewBox']);
        $this->assertSame([-5.0, 2.5, 24.0, 24.0], $this->sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="-5,2.5 24 24" width="10cm"><rect width="1" height="1"/></svg>')['viewBox']);

        $this->assertRejected('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>', SvgSanitizer::NO_SIZE);
        $this->assertRejected('<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="50%"><rect width="1" height="1"/></svg>', SvgSanitizer::NO_SIZE);
        $this->assertRejected('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 10"><rect width="1" height="1"/></svg>', SvgSanitizer::NO_SIZE);
    }

    public function test_an_svg_with_nothing_safe_to_draw_is_refused(): void
    {
        $this->assertRejected('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><script>alert(1)</script></svg>', SvgSanitizer::NOTHING_VISIBLE);
    }

    public function test_oversized_or_overly_complex_svgs_are_refused(): void
    {
        $this->assertRejected('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1">'.str_repeat(' ', SvgSanitizer::MAX_BYTES).'</svg>', SvgSanitizer::TOO_LARGE);
        $this->assertRejected('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1">'.str_repeat('<g>', 60).'<rect width="1" height="1"/>'.str_repeat('</g>', 60).'</svg>', SvgSanitizer::TOO_COMPLEX);
        $this->assertRejected('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1">'.str_repeat('<rect/>', SvgSanitizer::MAX_ELEMENTS + 1).'</svg>', SvgSanitizer::TOO_COMPLEX);
    }

    public function test_a_crop_rewrites_the_viewbox_and_keeps_the_logo_vector(): void
    {
        $sanitizer = new SvgSanitizer;
        $cropped = $sanitizer->crop($sanitizer->sanitize(self::LOGO)['svg'], ['x' => 50.0, 'y' => 0.0, 'width' => 100.0, 'height' => 50.0]);

        $this->assertSame([50.0, 0.0, 100.0, 50.0], $cropped['viewBox']);
        $this->assertStringContainsString('viewBox="50 0 100 50"', $cropped['svg']);
        $this->assertStringContainsString('width="100" height="50"', $cropped['svg']);
        $this->assertStringContainsString('<rect x="10" y="10" width="180" height="80" fill="#0d9488"/>', $cropped['svg']);
    }

    public function test_a_crop_is_relative_to_the_viewbox_origin(): void
    {
        $sanitizer = new SvgSanitizer;
        $clean = $sanitizer->sanitize('<svg xmlns="http://www.w3.org/2000/svg" viewBox="-10 -20 100 100"><rect width="1" height="1"/></svg>');

        $this->assertSame([0.0, 0.0, 50.0, 40.0], $sanitizer->crop($clean['svg'], ['x' => 10.0, 'y' => 20.0, 'width' => 50.0, 'height' => 40.0])['viewBox']);
    }

    public function test_a_crop_outside_the_logo_is_refused(): void
    {
        $sanitizer = new SvgSanitizer;
        $clean = $sanitizer->sanitize(self::LOGO)['svg'];

        foreach ([['x' => 150.0, 'y' => 0.0, 'width' => 100.0, 'height' => 50.0], ['x' => -5.0, 'y' => 0.0, 'width' => 10.0, 'height' => 10.0], ['x' => 0.0, 'y' => 0.0, 'width' => 0.0, 'height' => 10.0]] as $crop) {
            try {
                $sanitizer->crop($clean, $crop);
                $this->fail('The crop was accepted.');
            } catch (LogoRejected $rejected) {
                $this->assertSame(SvgSanitizer::CROP_OUTSIDE, $rejected->getMessage());
            }
        }
    }
}
