<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Kiosk\Support\KioskCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Serves the Kiosk option catalog to the configurator. Readable by anyone who
 * can run the configurator (BD staff or a temp client), i.e. holds the
 * `kiosk.configure` permission.
 */
class CatalogController extends Controller
{
    /** GET /api/v1/kiosk/catalog — business/website types, sections, brand kits. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('kiosk.configure'), 403);

        return response()->json(['data' => KioskCatalog::all()]);
    }
}
