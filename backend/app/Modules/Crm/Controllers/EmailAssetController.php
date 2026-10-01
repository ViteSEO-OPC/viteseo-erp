<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Services\Outreach\EmailAssetService;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public email-asset route: GET /api/v1/crm/email-assets/{uuid}.png, with NO authentication.
 *
 * Email clients fetch images anonymously, often through their own proxy, and long after the email was sent,
 * so the URL cannot need a session and must not expire. The random UUID is the whole identity; the route
 * serves only the server-encoded PNG — never an asset's SVG — with headers that stop it being sniffed or
 * rendered as anything but an image.
 */
class EmailAssetController extends Controller
{
    public function __construct(private readonly EmailAssetService $assets) {}

    public function show(string $uuid): Response
    {
        $asset = $this->assets->findPublic($uuid);
        $bytes = $asset === null ? null : $this->assets->pngBytes($asset);

        abort_if($asset === null || $bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="'.$asset->uuid.'.png"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"'.$asset->sha256.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
