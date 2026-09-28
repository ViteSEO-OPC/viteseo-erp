<?php

namespace Tests\Unit\Crm\EmailAssets;

use App\Modules\Crm\Support\EmailAssets\LogoPng;
use App\Modules\Crm\Support\EmailAssets\LogoRejected;
use Closure;
use GdImage;
use PHPUnit\Framework\TestCase;

/**
 * PNG logos: what is stored is always GD's own re-encoding of the crop — right size, transparency kept, nothing
 * of the uploaded bytes carried over — and anything that is not a PNG, or claims impossible dimensions, is refused
 * before it is decoded.
 */
class LogoPngTest extends TestCase
{
    /** A transparent PNG with an opaque red rectangle on its right half. */
    private function png(int $width, int $height, ?Closure $draw = null): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocatealpha($image, 255, 0, 0, 0));
        if ($draw !== null) {
            $draw($image);
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @return array{int, int, int, int} red, green, blue, alpha (127 = transparent) */
    private function pixel(string $png, int $x, int $y): array
    {
        $image = imagecreatefromstring($png);
        $this->assertInstanceOf(GdImage::class, $image);
        $color = imagecolorat($image, $x, $y);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF, ($color >> 24) & 0x7F];
    }

    private function assertRejected(Closure $call, string $message): void
    {
        try {
            $call();
            $this->fail('The logo was accepted.');
        } catch (LogoRejected $rejected) {
            $this->assertSame($message, $rejected->getMessage());
        }
    }

    public function test_a_crop_keeps_transparency_and_the_exact_region(): void
    {
        $source = $this->png(400, 200);
        $whole = (new LogoPng)->crop($source, ['x' => 0.0, 'y' => 0.0, 'width' => 400.0, 'height' => 200.0]);

        $this->assertSame([400, 200], [$whole['width'], $whole['height']]);
        $this->assertSame(127, $this->pixel($whole['png'], 10, 10)[3], 'the transparent half stays transparent');
        $this->assertSame([255, 0, 0, 0], $this->pixel($whole['png'], 300, 100));

        $right = (new LogoPng)->crop($source, ['x' => 200.0, 'y' => 0.0, 'width' => 200.0, 'height' => 200.0]);
        $this->assertSame([200, 200], [$right['width'], $right['height']]);
        $this->assertSame([255, 0, 0, 0], $this->pixel($right['png'], 0, 0));
        $this->assertSame([400, 200], [$right['source_width'], $right['source_height']]);
    }

    public function test_the_email_image_is_at_most_600_pixels_wide_and_never_enlarged(): void
    {
        $large = (new LogoPng)->crop($this->png(1600, 400), ['x' => 0.0, 'y' => 0.0, 'width' => 1600.0, 'height' => 400.0]);
        $this->assertSame([600, 150], [$large['width'], $large['height']]);
        $this->assertSame([600, 150], array_slice(getimagesizefromstring($large['png']), 0, 2));

        $small = (new LogoPng)->crop($this->png(50, 20), ['x' => 0.0, 'y' => 0.0, 'width' => 50.0, 'height' => 20.0]);
        $this->assertSame([50, 20], [$small['width'], $small['height']]);
    }

    public function test_what_is_stored_is_a_fresh_encoding_that_drops_anything_hidden_in_the_upload(): void
    {
        $polyglot = $this->png(40, 20).'<script>alert(1)</script>';
        $result = (new LogoPng)->crop($polyglot, ['x' => 0.0, 'y' => 0.0, 'width' => 40.0, 'height' => 20.0]);

        $this->assertTrue(LogoPng::isPng($result['png']));
        $this->assertStringNotContainsString('<script>', $result['png']);
        $this->assertNotSame($polyglot, $result['png']);
    }

    public function test_files_that_are_not_png_are_refused(): void
    {
        $jpeg = (function (): string {
            $image = imagecreatetruecolor(10, 10);
            ob_start();
            imagejpeg($image);

            return (string) ob_get_clean();
        })();
        $crop = ['x' => 0.0, 'y' => 0.0, 'width' => 10.0, 'height' => 10.0];

        $this->assertRejected(fn () => (new LogoPng)->crop($jpeg, $crop), LogoPng::INVALID);
        $this->assertRejected(fn () => (new LogoPng)->crop('GIF89a'.str_repeat("\0", 20), $crop), LogoPng::INVALID);
        $this->assertRejected(fn () => (new LogoPng)->crop(LogoPng::SIGNATURE.'xx', $crop), LogoPng::INVALID);
        $this->assertRejected(fn () => (new LogoPng)->crop(str_repeat('a', LogoPng::MAX_BYTES + 1), $crop), LogoPng::TOO_LARGE);
    }

    public function test_a_png_claiming_huge_dimensions_is_refused_before_it_is_decoded(): void
    {
        $png = $this->png(10, 10);
        $claimsHuge = substr($png, 0, 16).pack('NN', 6000, 6000).substr($png, 24);

        $this->assertRejected(fn () => (new LogoPng)->dimensions($claimsHuge), LogoPng::TOO_MANY_PIXELS);
    }

    public function test_crops_outside_too_small_or_badly_shaped_are_refused(): void
    {
        $png = $this->png(400, 200);

        $this->assertRejected(fn () => (new LogoPng)->crop($png, ['x' => 390.0, 'y' => 0.0, 'width' => 20.0, 'height' => 10.0]), LogoPng::CROP_OUTSIDE);
        $this->assertRejected(fn () => (new LogoPng)->crop($png, ['x' => -1.0, 'y' => 0.0, 'width' => 20.0, 'height' => 10.0]), LogoPng::CROP_OUTSIDE);
        $this->assertRejected(fn () => (new LogoPng)->crop($png, ['x' => 0.0, 'y' => 0.0, 'width' => 4.0, 'height' => 4.0]), LogoPng::CROP_TOO_SMALL);
        $this->assertRejected(fn () => (new LogoPng)->crop($png, ['x' => 0.0, 'y' => 0.0, 'width' => 400.0, 'height' => 20.0]), LogoPng::BAD_ASPECT);
    }

    public function test_an_svg_rendering_must_match_its_crop(): void
    {
        $ok = (new LogoPng)->reencodeRendition($this->png(600, 300), 200.0, 100.0);
        $this->assertSame([600, 300], [$ok['width'], $ok['height']]);
        $this->assertSame(127, $this->pixel($ok['png'], 10, 10)[3]);

        $this->assertRejected(fn () => (new LogoPng)->reencodeRendition($this->png(600, 400), 200.0, 100.0), LogoPng::RENDITION_MISMATCH);
        $this->assertRejected(fn () => (new LogoPng)->reencodeRendition($this->png(2000, 1000), 200.0, 100.0), LogoPng::RENDITION_MISMATCH);
    }

    public function test_the_shape_limits(): void
    {
        $this->assertNull(LogoPng::aspectProblem(100, 10));
        $this->assertNull(LogoPng::aspectProblem(25, 100));
        $this->assertSame(LogoPng::BAD_ASPECT, LogoPng::aspectProblem(110, 10));
        $this->assertSame(LogoPng::BAD_ASPECT, LogoPng::aspectProblem(20, 100));
        $this->assertSame(LogoPng::BAD_ASPECT, LogoPng::aspectProblem(0, 100));
    }
}
