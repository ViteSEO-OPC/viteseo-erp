<?php

namespace App\Modules\Crm\Services\Outreach;

use App\Modules\Crm\Models\EmailAsset;
use App\Modules\Crm\Support\EmailAssets\LogoPng;
use App\Modules\Crm\Support\EmailAssets\LogoRejected;
use App\Modules\Crm\Support\EmailAssets\SvgSanitizer;
use App\Support\CurrentOrg;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Email assets: storing the organization logo and serving it to email clients.
 *
 * ── Formats ──
 * The format is decided from the bytes, never from the file name or the browser's MIME type.
 *
 *   PNG  cropped on the server from the original (LogoPng) and re-encoded; the original is not kept.
 *   SVG  sanitized (SvgSanitizer) and cropped by rewriting its viewBox, so it stays vector and is kept as SVG.
 *        Email clients do not render SVG reliably — Gmail and Outlook do not show it — so every email gets a
 *        PNG. The server has no SVG rasterizer, so the browser draws the SERVER-SANITIZED, cropped SVG onto a
 *        canvas and uploads that PNG with it; the server decodes it, checks it matches the crop's shape, and
 *        re-encodes it. What an email client receives was always produced by the server's encoder.
 *
 * ── Identity ──
 * An asset is immutable and identified publicly by a random UUID. Its PNG is served by that UUID at
 * /api/v1/crm/email-assets/{uuid}.png with no authentication, because an email client cannot authenticate —
 * and the URL never expires, so an email already sent keeps its logo. No design stores the UUID or the URL.
 */
class EmailAssetService
{
    public const DISK = 'local';

    public const DIRECTORY = 'crm/email-assets';

    public const UNSUPPORTED = 'Upload a PNG or SVG logo.';

    public const CROP_INVALID = 'Choose the part of the logo to keep.';

    public const RENDITION_REQUIRED = 'An SVG logo needs its PNG rendering for email clients. Try uploading it again.';

    public function __construct(
        private readonly SvgSanitizer $svg,
        private readonly LogoPng $png,
    ) {}

    /**
     * What the crop step needs before anything is stored: a PNG's size, or an SVG sanitized — the version the
     * browser shows and draws from — with its size in user units and what sanitizing removed.
     *
     * @return array{format: string, width: float, height: float, svg?: string, removed?: array<string, int>}
     *
     * @throws ValidationException
     */
    public function inspect(UploadedFile $file): array
    {
        $bytes = (string) $file->get();

        try {
            if ($this->formatOf($bytes) === EmailAsset::FORMAT_PNG) {
                $size = $this->png->dimensions($bytes);

                return ['format' => EmailAsset::FORMAT_PNG, 'width' => (float) $size['width'], 'height' => (float) $size['height']];
            }

            $clean = $this->svg->sanitize($bytes);

            return [
                'format' => EmailAsset::FORMAT_SVG,
                'width' => $clean['width'],
                'height' => $clean['height'],
                'svg' => $clean['svg'],
                'removed' => $clean['removed'],
            ];
        } catch (LogoRejected $rejected) {
            throw ValidationException::withMessages(['file' => $rejected->getMessage()]);
        }
    }

    /**
     * Stores a logo upload, cropped, as a new immutable asset in the current organization.
     *
     * `$cropJson` is `{x, y, width, height}`: source pixels for a PNG; for an SVG, user units from the top-left
     * of its sanitized viewBox.
     *
     * @throws ValidationException
     */
    public function storeLogo(UploadedFile $file, string $cropJson, ?UploadedFile $rendition, ?int $actorId): EmailAsset
    {
        $crop = $this->parseCrop($cropJson);
        $bytes = (string) $file->get();

        try {
            $format = $this->formatOf($bytes);

            if ($format === EmailAsset::FORMAT_PNG) {
                $result = $this->png->crop($bytes, $crop);
                $png = $result['png'];
                [$width, $height] = [$result['width'], $result['height']];
                $svg = null;
                $meta = ['source_width' => $result['source_width'], 'source_height' => $result['source_height'], 'removed' => []];
            } else {
                $clean = $this->svg->sanitize($bytes);
                if (($problem = LogoPng::aspectProblem($crop['width'], $crop['height'])) !== null) {
                    throw new LogoRejected($problem);
                }
                $cropped = $this->svg->crop($clean['svg'], $crop);
                $svg = $cropped['svg'];
                $meta = ['source_width' => $clean['width'], 'source_height' => $clean['height'], 'removed' => $clean['removed'], 'view_box' => $cropped['viewBox']];
            }
        } catch (LogoRejected $rejected) {
            throw ValidationException::withMessages(['file' => $rejected->getMessage()]);
        }

        if ($format === EmailAsset::FORMAT_SVG) {
            if ($rendition === null) {
                throw ValidationException::withMessages(['rendition' => self::RENDITION_REQUIRED]);
            }
            try {
                $result = $this->png->reencodeRendition((string) $rendition->get(), $cropped['viewBox'][2], $cropped['viewBox'][3]);
            } catch (LogoRejected $rejected) {
                throw ValidationException::withMessages(['rendition' => $rejected->getMessage()]);
            }
            $png = $result['png'];
            [$width, $height] = [$result['width'], $result['height']];
        }

        $orgId = CurrentOrg::id() ?? throw new RuntimeException('An email asset needs a current organization.');
        $uuid = strtolower((string) Str::uuid());
        $directory = self::DIRECTORY.'/'.$orgId;
        $paths = ['png' => "{$directory}/{$uuid}.png", 'svg' => $svg === null ? null : "{$directory}/{$uuid}.svg"];

        $disk = Storage::disk(self::DISK);
        try {
            if (! $disk->put($paths['png'], $png) || ($svg !== null && ! $disk->put((string) $paths['svg'], $svg))) {
                throw new RuntimeException('The logo could not be stored.');
            }

            return EmailAsset::create([
                'uuid' => $uuid,
                'purpose' => EmailAsset::PURPOSE_LOGO,
                'source_format' => $format,
                'path' => $paths['png'],
                'svg_path' => $paths['svg'],
                'width' => $width,
                'height' => $height,
                'bytes' => strlen($png),
                'sha256' => hash('sha256', $png),
                'meta' => [...$meta, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 150), 'crop' => $crop],
                'created_by' => $actorId,
            ]);
        } catch (Throwable $failure) {
            $disk->delete(array_filter($paths));

            throw $failure;
        }
    }

    /** The PNG email clients receive, or null when its file is missing. */
    public function pngBytes(EmailAsset $asset): ?string
    {
        $bytes = Storage::disk(self::DISK)->get($asset->path);

        return is_string($bytes) && LogoPng::isPng($bytes) ? $bytes : null;
    }

    /** The asset's public, non-expiring URL — built from the current request's host, or APP_URL outside one. */
    public function publicUrl(EmailAsset $asset): string
    {
        return url('/api/v1/crm/email-assets/'.$asset->uuid.'.png');
    }

    /**
     * The asset the public route serves. Deliberately outside tenant scoping: the route has no authenticated
     * organization, and the random UUID is the whole identity. Soft-deleted assets are not served.
     */
    public function findPublic(string $uuid): ?EmailAsset
    {
        return EmailAsset::withoutGlobalScope('org')->where('uuid', $uuid)->first();
    }

    /**
     * @return 'png'|'svg'
     *
     * @throws LogoRejected
     */
    private function formatOf(string $bytes): string
    {
        if (LogoPng::isPng($bytes)) {
            return EmailAsset::FORMAT_PNG;
        }

        $head = ltrim(str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3, 4096) : substr($bytes, 0, 4096));
        if (str_starts_with($head, '<') && stripos($head, '<svg') !== false) {
            return EmailAsset::FORMAT_SVG;
        }

        throw new LogoRejected(self::UNSUPPORTED);
    }

    /**
     * @return array{x: float, y: float, width: float, height: float}
     *
     * @throws ValidationException
     */
    private function parseCrop(string $json): array
    {
        try {
            $crop = json_decode($json, true, 3, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $crop = null;
        }

        $fields = ['x', 'y', 'width', 'height'];
        if (! is_array($crop) || array_diff(array_keys($crop), $fields) !== [] || count($crop) !== 4) {
            throw ValidationException::withMessages(['crop' => self::CROP_INVALID]);
        }

        $values = [];
        foreach ($fields as $field) {
            if (! is_int($crop[$field]) && ! is_float($crop[$field]) || ! is_finite((float) $crop[$field]) || abs((float) $crop[$field]) > SvgSanitizer::MAX_DIMENSION) {
                throw ValidationException::withMessages(['crop' => self::CROP_INVALID]);
            }
            $values[$field] = (float) $crop[$field];
        }

        return $values;
    }
}
