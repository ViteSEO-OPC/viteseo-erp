<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support\EmailAssets;

use GdImage;

/**
 * The PNG side of a logo: decoding an upload safely, cropping it, and re-encoding what email clients receive.
 *
 * Every PNG that is stored was DRAWN by GD onto a fresh transparent canvas and encoded by GD — the uploaded
 * bytes themselves are never kept, so nothing hidden in them (extra chunks, trailing data, a polyglot file)
 * survives. Dimensions are read from the header and checked BEFORE the image is decoded, so a small file
 * that claims to be enormous cannot exhaust memory.
 *
 * Email clients receive a PNG at most RENDITION_WIDTH pixels wide: twice the widest a logo block can be
 * placed, so it stays sharp on high-density screens. Transparency is kept.
 */
final class LogoPng
{
    public const SIGNATURE = "\x89PNG\r\n\x1a\n";

    public const MAX_BYTES = 2_097_152;

    public const MAX_SIDE = 5_000;

    public const MAX_PIXELS = 16_000_000;

    /** Twice EmailDesignSchema::LOGO_WIDTH's maximum. */
    public const RENDITION_WIDTH = 600;

    public const MIN_RENDITION_WIDTH = 16;

    public const MAX_RENDITION_WIDTH = 1_200;

    public const MAX_RENDITION_HEIGHT = 4_800;

    /** The smallest crop, in source pixels, for a PNG upload. */
    public const MIN_CROP = 8;

    /** A crop's width ÷ height must lie within these, so a logo fits an email header. */
    public const MIN_ASPECT = 0.25;

    public const MAX_ASPECT = 10.0;

    public const INVALID = 'This file is not a valid PNG image.';

    public const TOO_LARGE = 'The logo must be 2 MB or smaller.';

    public const TOO_MANY_PIXELS = 'This image is too large. Use one at most 5,000 pixels on each side.';

    public const CROP_OUTSIDE = 'The crop must lie inside the logo.';

    public const CROP_TOO_SMALL = 'The crop is too small. Keep at least 8 × 8 pixels.';

    public const BAD_ASPECT = 'Crop the logo to a shape between 1:4 (tall) and 10:1 (wide) so it fits an email header.';

    public const RENDITION_MISMATCH = 'The logo image does not match the crop. Try again.';

    public static function isPng(string $bytes): bool
    {
        return str_starts_with($bytes, self::SIGNATURE);
    }

    /** Null when a crop of this size has an acceptable shape; otherwise why not. */
    public static function aspectProblem(float $width, float $height): ?string
    {
        if ($width <= 0 || $height <= 0) {
            return self::BAD_ASPECT;
        }
        $aspect = $width / $height;

        return $aspect < self::MIN_ASPECT || $aspect > self::MAX_ASPECT ? self::BAD_ASPECT : null;
    }

    /**
     * @return array{width: int, height: int}
     *
     * @throws LogoRejected
     */
    public function dimensions(string $bytes): array
    {
        [$width, $height] = $this->checkHeader($bytes);

        return ['width' => $width, 'height' => $height];
    }

    /**
     * A PNG upload cropped to whole source pixels and scaled to at most RENDITION_WIDTH wide.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $crop
     * @return array{png: string, width: int, height: int, source_width: int, source_height: int}
     *
     * @throws LogoRejected
     */
    public function crop(string $bytes, array $crop): array
    {
        [$image, $sourceWidth, $sourceHeight] = $this->decode($bytes);

        $x = (int) round($crop['x']);
        $y = (int) round($crop['y']);
        $width = (int) round($crop['width']);
        $height = (int) round($crop['height']);

        if ($x < 0 || $y < 0 || $x + $width > $sourceWidth || $y + $height > $sourceHeight) {
            throw new LogoRejected(self::CROP_OUTSIDE);
        }
        if ($width < self::MIN_CROP || $height < self::MIN_CROP) {
            throw new LogoRejected(self::CROP_TOO_SMALL);
        }
        if (($problem = self::aspectProblem($width, $height)) !== null) {
            throw new LogoRejected($problem);
        }

        $outWidth = min($width, self::RENDITION_WIDTH);
        $outHeight = max(1, (int) round($outWidth * $height / $width));

        $canvas = $this->transparentCanvas($outWidth, $outHeight);
        imagecopyresampled($canvas, $image, 0, 0, $x, $y, $outWidth, $outHeight, $width, $height);

        return [
            'png' => $this->encode($canvas),
            'width' => $outWidth,
            'height' => $outHeight,
            'source_width' => $sourceWidth,
            'source_height' => $sourceHeight,
        ];
    }

    /**
     * The PNG rendering the browser drew from a cropped SVG, checked against that crop and re-encoded.
     *
     * @return array{png: string, width: int, height: int}
     *
     * @throws LogoRejected
     */
    public function reencodeRendition(string $bytes, float $cropWidth, float $cropHeight): array
    {
        [$image, $width, $height] = $this->decode($bytes);

        if (($problem = self::aspectProblem($cropWidth, $cropHeight)) !== null) {
            throw new LogoRejected($problem);
        }
        if ($width < self::MIN_RENDITION_WIDTH || $width > self::MAX_RENDITION_WIDTH || $height > self::MAX_RENDITION_HEIGHT) {
            throw new LogoRejected(self::RENDITION_MISMATCH);
        }

        $expectedHeight = $width * $cropHeight / $cropWidth;
        if (abs($height - $expectedHeight) > max(2.0, $expectedHeight * 0.02)) {
            throw new LogoRejected(self::RENDITION_MISMATCH);
        }

        $canvas = $this->transparentCanvas($width, $height);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        return ['png' => $this->encode($canvas), 'width' => $width, 'height' => $height];
    }

    /**
     * @return array{int, int}
     *
     * @throws LogoRejected
     */
    private function checkHeader(string $bytes): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new LogoRejected(self::TOO_LARGE);
        }
        if (! self::isPng($bytes)) {
            throw new LogoRejected(self::INVALID);
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_PNG) {
            throw new LogoRejected(self::INVALID);
        }

        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw new LogoRejected(self::TOO_MANY_PIXELS);
        }

        return [$width, $height];
    }

    /**
     * @return array{GdImage, int, int}
     *
     * @throws LogoRejected
     */
    private function decode(string $bytes): array
    {
        [$width, $height] = $this->checkHeader($bytes);

        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage || imagesx($image) !== $width || imagesy($image) !== $height) {
            throw new LogoRejected(self::INVALID);
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return [$image, $width, $height];
    }

    private function transparentCanvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }
}
